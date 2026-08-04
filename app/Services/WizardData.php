<?php

namespace App\Services;

use App\Enums\RelocationStep;
use App\Models\Relocation;
use App\Models\Site;
use Illuminate\Support\Facades\Cache;

/**
 * Assembles everything the relocation wizard shows: step cards with states,
 * sub-check verdicts (auto pass/warn/fail from cached probes), and helper
 * payloads (env template, db info, dns, sizes).
 */
class WizardData
{
    public function __construct(
        protected RemoteProbe $probe,
        protected DnsService $dns,
        protected EnvKeyClassifier $classifier,
    ) {}

    /** @return list<array> one entry per wizard card, in order */
    public function steps(Relocation $relocation): array
    {
        $relocation->load(['runs', 'checks', 'sourceServer', 'targetServer']);
        $states = $relocation->scriptStates();
        $running = $relocation->runningStep();

        $steps = [];
        $previousDone = true;
        foreach (RelocationStep::ordered() as $index => $step) {
            $done = $relocation->stepDone($step);
            $state = $step->isScript()
                ? $states[$step->value]->value
                : ($done ? 'done' : 'to do');

            $steps[] = [
                'step' => $step,
                'key' => $step->value,
                'label' => $step->label(),
                'number' => $index + 1,
                'script' => $step->isScript(),
                'state' => $state,
                'done' => $done,
                'done_at' => $relocation->checks->firstWhere('step_key', $step->value)?->done_at,
                'current' => ! $done && $previousDone,
                'locked' => ! $done && ! $previousDone,
                // done script steps stay runnable — that's the "Run again" button
                'can_run' => $step->isScript() && $running === null && ($done || $previousDone),
                'summary' => match (true) {
                    $step->isScript() => $relocation->runs->where('step', $step->value)->sortByDesc('id')->first()?->summary,
                    $done => $this->doneSummary($relocation, $step),
                    default => null,
                },
                // a done manual step shows only its one-line summary — skip the sub-check probes
                'sub_checks' => $done && ! $step->isScript() ? [] : $this->subChecks($relocation, $step),
            ];
            $previousDone = $previousDone && $done;
        }

        return $steps;
    }

    protected function subChecks(Relocation $relocation, RelocationStep $step): array
    {
        return collect($step->subChecks())->map(function (string $check) use ($relocation, $step) {
            $key = "{$step->value}:{$check}";
            $verdict = $this->verdict($relocation, $step, $check);

            return [
                'key' => $key,
                'check' => $check,
                'label' => $verdict['label'],
                'status' => $verdict['status'], // pass|warn|fail|unknown
                'detail' => $verdict['detail'],
                'ticked' => $verdict['status'] === 'pass' || $relocation->checkDone($key),
                'auto' => $verdict['status'] === 'pass',
            ];
        })->all();
    }

    /** @return array{label: string, status: string, detail: string} */
    protected function verdict(Relocation $relocation, RelocationStep $step, string $check): array
    {
        return match ("{$step->value}:{$check}") {
            'preflight:php' => $this->checkPhp($relocation),
            'preflight:key' => $this->checkKeys($relocation),
            'preflight:dns' => $this->checkDnsTtl($relocation),
            'preflight:jobs' => $this->checkJobs($relocation),
            'forge_site:source' => $this->info('Source control connected',
                'Connect '.($this->sourceSite($relocation)?->repo_provider ?: 'the git provider').' in Forge'),
            'forge_site:repo' => $this->checkRepo($relocation),
            'forge_site:db' => $this->checkDb($relocation),
            'forge_site:domain' => $this->checkDomain($relocation),
            'forge_site:composer' => $this->info('Install composer dependencies', 'Tick "Install Composer Dependencies" when creating the site'),
            'forge_site:deploykey' => $this->info('Deploy key', 'Leave off — the org-level integration handles auth'),
            'forge_site:webdir' => $this->info('Web directory', 'Use /public'),
            'forge_site:php' => $this->checkSitePhp($relocation),
            'forge_site:isolation' => $this->checkIsolation($relocation),
            'forge_site:zdd' => $this->checkZdd($relocation),
            'forge_site:env' => $this->info('.env filled in on target', 'Paste the generated template below into the Forge site .env'),
            'deploy_test:storagelink' => $this->checkStorageLink($relocation),
            'cutover:dnslive' => $this->checkDnsLive($relocation),
            default => ['label' => $check, 'status' => 'unknown', 'detail' => ''],
        };
    }

    protected function info(string $label, string $detail): array
    {
        return ['label' => $label, 'status' => 'unknown', 'detail' => $detail];
    }

    /** One-line digest shown instead of the sub-checks once a manual step is done. */
    protected function doneSummary(Relocation $relocation, RelocationStep $step): ?string
    {
        return match ($step) {
            RelocationStep::Preflight => $this->preflightSummary($relocation),
            RelocationStep::ForgeSite => 'Site created: '.$relocation->targetSiteName(),
            RelocationStep::DeployTest => 'Deployed & tested on '.$relocation->targetSiteName(),
            RelocationStep::Cutover => "DNS cut over — {$relocation->site_name} is live on {$relocation->targetServer->name}",
            default => null,
        };
    }

    protected function preflightSummary(Relocation $relocation): string
    {
        $parts = [];
        if ($php = $this->normalizePhp($this->sourceSite($relocation)?->php_version)) {
            $parts[] = "PHP {$php}";
        }
        $dns = $this->cached($relocation, 'dns', 'dns',
            fn () => $this->dns->lookup($relocation->site_name), $relocation->site_name);
        if (($dns['ttl'] ?? null) !== null) {
            $parts[] = "DNS TTL {$dns['ttl']} sec";
        }
        $inventory = $this->cached($relocation, 'inventory', 'inventory',
            fn () => $this->probe->inventory($relocation->sourceServer->name, $relocation->site_name, $relocation->unix_user));
        if ($inventory !== null) {
            $workers = substr_count($inventory, '[program');
            $parts[] = ($workers ? "{$workers} worker(s)" : 'no workers')
                .(str_contains($inventory, 'schedule:run') ? ', schedule:run' : ', no schedule');
        }
        if ($relocation->sourceServer->compromised) {
            $parts[] = 'source COMPROMISED — rotate credentials';
        }

        return implode(' · ', $parts) ?: 'all checks confirmed';
    }

    protected function sourceSite(Relocation $relocation): ?Site
    {
        return Site::where('server_id', $relocation->source_server_id)
            ->where('name', $relocation->site_name)->first();
    }

    protected function targetSite(Relocation $relocation): ?Site
    {
        return Site::where('server_id', $relocation->target_server_id)
            ->whereIn('name', [$relocation->targetSiteName(), $relocation->site_name])->first();
    }

    // ── preflight ────────────────────────────────────────────────────────

    /** Forge reports site versions as "PHP 8.3", probes report "8.3" — compare bare versions. */
    protected function normalizePhp(?string $version): ?string
    {
        return $version ? trim(preg_replace('/^php\s*/i', '', $version)) : null;
    }

    protected function checkPhp(Relocation $relocation): array
    {
        $needed = $this->normalizePhp($this->sourceSite($relocation)?->php_version);
        $installed = array_map($this->normalizePhp(...), $relocation->targetServer->php_versions ?? []);
        if (! $needed) {
            return ['label' => 'PHP version on target', 'status' => 'unknown',
                'detail' => 'Source site PHP version unknown — sync Forge sites first'];
        }
        $ok = in_array($needed, $installed, true);

        return [
            'label' => "PHP {$needed} installed on {$relocation->targetServer->name}",
            'status' => $ok ? 'pass' : 'fail',
            'detail' => $ok ? '' : 'Installed: '.(implode(', ', $installed) ?: 'unknown — probe the target server'),
        ];
    }

    protected function checkKeys(Relocation $relocation): array
    {
        $compromised = $relocation->sourceServer->compromised;
        if (! $compromised) {
            return ['label' => 'APP_KEY & credentials strategy', 'status' => 'pass',
                'detail' => 'Source not compromised — secrets may be carried over'];
        }
        $keys = $this->cached($relocation, 'envkeys', 'env_keys',
            fn () => $this->probe->envKeys($relocation->sourceServer->name, $relocation->site_name, $relocation->unix_user));
        $buckets = $this->classifier->classify($keys ?? [], true);
        $rotate = count($buckets['rotate'] ?? []);

        return [
            'label' => 'Rotate credentials (source is COMPROMISED)',
            'status' => 'warn',
            'detail' => $rotate
                ? "Rotate {$rotate} credentials: ".implode(', ', $buckets['rotate'])
                : 'Could not read .env key names from the source',
        ];
    }

    protected function checkDnsTtl(Relocation $relocation): array
    {
        $dns = $this->cached($relocation, 'dns', 'dns',
            fn () => $this->dns->lookup($relocation->site_name), $relocation->site_name);
        if (! ($dns['ok'] ?? false)) {
            return ['label' => 'DNS TTL', 'status' => 'unknown', 'detail' => 'DNS lookup failed'];
        }
        $ttl = $dns['ttl'];
        $manage = $this->dns->manageUrl($dns['provider'], $dns['zone']);
        $target = $dns['ip'] ? "IP {$dns['ip']}" : (($dns['cname'] ?? '') ? "CNAME → {$dns['cname']}" : '');
        if ($ttl === null) {
            return [
                'label' => "DNS TTL ({$dns['provider']}, zone {$dns['zone']})",
                'status' => 'unknown',
                'detail' => 'Could not read the record TTL — check manually'
                    .($manage ? " · manage: {$manage}" : ''),
            ];
        }
        $ok = $ttl <= 300;

        return [
            'label' => "DNS TTL ≤ 300 s ({$dns['provider']}, zone {$dns['zone']})",
            'status' => $ok ? 'pass' : 'fail',
            'detail' => ($ok ? trim("TTL {$ttl}s · {$target}", ' ·')
                : "TTL is {$ttl}s — lower it to 60–300 s at least 24 h before cutover")
                .($manage ? " · manage: {$manage}" : ''),
        ];
    }

    protected function checkJobs(Relocation $relocation): array
    {
        $inventory = $this->cached($relocation, 'inventory', 'inventory',
            fn () => $this->probe->inventory($relocation->sourceServer->name, $relocation->site_name, $relocation->unix_user));
        if ($inventory === null) {
            return ['label' => 'Workers, cron & scheduler inventory', 'status' => 'unknown', 'detail' => 'Probe failed'];
        }
        $workers = substr_count($inventory, '[program');
        $hasSchedule = str_contains($inventory, 'schedule:run');

        return [
            'label' => 'Workers, cron & scheduler inventory',
            'status' => 'warn',
            'detail' => "{$workers} supervisor program(s)"
                .($hasSchedule ? ', schedule:run in crontab' : ', no schedule:run in crontab')
                .' — recreate them on the target after cutover',
        ];
    }

    // ── forge_site ───────────────────────────────────────────────────────

    protected function checkRepo(Relocation $relocation): array
    {
        $site = $this->sourceSite($relocation);
        if (! $site?->repo_url) {
            return ['label' => 'Repository', 'status' => 'unknown', 'detail' => 'No repo known for the source site'];
        }

        return ['label' => 'Repository', 'status' => 'unknown',
            'detail' => "{$site->repo_url} · branch {$site->repo_branch}"];
    }

    protected function checkDb(Relocation $relocation): array
    {
        $info = $this->cached($relocation, 'dbinfo', 'db_info',
            fn () => $this->probe->dbInfo($relocation->sourceServer->name, $relocation->site_name, $relocation->unix_user));
        if (! $info || empty($info['database'])) {
            return ['label' => 'Create database on target', 'status' => 'unknown', 'detail' => 'Could not read DB info from the source .env'];
        }

        return ['label' => 'Create database on target', 'status' => 'unknown',
            'detail' => "name {$info['database']} · user {$info['username']}"
                .(isset($info['size_mb']) ? " · {$info['size_mb']} MB, {$info['tables']} tables" : '')];
    }

    protected function checkDomain(Relocation $relocation): array
    {
        $suggested = $this->suggestTempDomain($relocation);

        return ['label' => 'Temp domain for the target site', 'status' => 'unknown',
            'detail' => $relocation->temp_domain
                ? "Using {$relocation->temp_domain}"
                : "Suggested: {$suggested} — enter it in the note field when confirming"];
    }

    public function suggestTempDomain(Relocation $relocation): string
    {
        $labels = str_replace('brightfish', 'bf', $relocation->site_name);

        return 'new-'.str_replace('.', '-', $labels).'.on-forge.com';
    }

    protected function checkSitePhp(Relocation $relocation): array
    {
        $needed = $this->normalizePhp($this->sourceSite($relocation)?->php_version);

        return ['label' => 'Site PHP version', 'status' => 'unknown',
            'detail' => $needed ? "Select PHP {$needed} when creating the site" : 'Match the source site'];
    }

    protected function checkIsolation(Relocation $relocation): array
    {
        $source = $this->sourceSite($relocation);
        $target = $this->targetSite($relocation);
        if ($target) {
            $ok = $target->isolated === ($source?->isolated ?? false);

            return ['label' => 'Site isolation matches source', 'status' => $ok ? 'pass' : 'warn',
                'detail' => $ok ? '' : 'Target isolation differs from the source site'];
        }

        return ['label' => 'Site isolation', 'status' => 'unknown',
            'detail' => ($source?->isolated ? "Enable isolation, user {$source->site_user}" : 'Source is not isolated').' (target site not synced yet)'];
    }

    protected function checkZdd(Relocation $relocation): array
    {
        $target = $this->targetSite($relocation);
        if (! $target) {
            return ['label' => 'Zero-downtime deployments OFF', 'status' => 'unknown',
                'detail' => 'Create the site plainly — ZDD complicates the restore step'];
        }

        return $target->zero_downtime
            ? ['label' => 'Zero-downtime deployments OFF', 'status' => 'warn',
                'detail' => 'Target site has ZDD enabled — the restore step expects a plain site dir']
            : ['label' => 'Zero-downtime deployments OFF', 'status' => 'pass', 'detail' => ''];
    }

    // ── deploy_test / cutover ────────────────────────────────────────────

    protected function checkStorageLink(Relocation $relocation): array
    {
        $result = Cache::remember(
            $this->key($relocation, 'storagelink'), config('forgemover.ttl.storage_link'),
            fn () => $this->probe->storageLink($relocation->targetServer->name,
                array_unique([$relocation->targetSiteName(), $relocation->site_name]),
                $relocation->unix_user));

        return match (true) {
            ! ($result['ok'] ?? false) => ['label' => 'public/storage symlink', 'status' => 'unknown',
                'detail' => 'Site directory not found on the target yet'],
            $result['exists'] && $result['valid'] => ['label' => 'public/storage symlink', 'status' => 'pass',
                'detail' => "→ {$result['target']}"],
            $result['exists'] => ['label' => 'public/storage symlink', 'status' => 'fail',
                'detail' => "Broken symlink → {$result['target']} — run php artisan storage:link"],
            default => ['label' => 'public/storage symlink', 'status' => 'fail',
                'detail' => 'Missing — run php artisan storage:link on the target'],
        };
    }

    protected function checkDnsLive(Relocation $relocation): array
    {
        $expected = Cache::remember($this->key($relocation, 'serverip'), config('forgemover.ttl.server_ip'),
            fn () => $this->probe->publicIp($relocation->targetServer->name));
        $live = Cache::remember($this->key($relocation, 'dnslive'), config('forgemover.ttl.dns_live'),
            fn () => $this->dns->liveIp($relocation->site_name));

        $ok = $expected && ($live['ip'] ?? null) === $expected;

        return [
            'label' => "DNS points at {$relocation->targetServer->name}".($expected ? " ({$expected})" : ''),
            'status' => $ok ? 'pass' : 'fail',
            'detail' => $ok
                ? "Live A record {$live['ip']}, TTL {$live['ttl']}s"
                : 'Live A record: '.(($live['ip'] ?? '') ?: 'none')." — repoint {$relocation->site_name} to the target",
        ];
    }

    // ── extra panels ─────────────────────────────────────────────────────

    public function envTemplate(Relocation $relocation): ?string
    {
        return $this->cached($relocation, 'envtpl', 'env_template',
            fn () => $this->probe->envTemplate($relocation->sourceServer->name, $relocation->site_name, $relocation->unix_user));
    }

    public function sizes(Relocation $relocation): array
    {
        return [
            'old' => $this->probe->sizes($relocation->sourceServer->name,
                [$relocation->site_name], $relocation->unix_user, $relocation->site_name),
            'new' => $this->probe->sizes($relocation->targetServer->name,
                array_unique([$relocation->targetSiteName(), $relocation->site_name]),
                $relocation->unix_user, $relocation->site_name),
            'fetched_at' => now()->format('H:i:s'),
        ];
    }

    protected function cached(Relocation $relocation, string $prefix, string $ttlKey, \Closure $producer, ?string $suffix = null): mixed
    {
        return Cache::remember(
            $suffix ? "{$prefix}:{$suffix}" : $this->key($relocation, $prefix),
            config("forgemover.ttl.{$ttlKey}"),
            $producer,
        );
    }

    protected function key(Relocation $relocation, string $prefix): string
    {
        return "{$prefix}:{$relocation->id}:{$relocation->sourceServer->name}:{$relocation->site_name}:{$relocation->targetServer->name}";
    }

    /** Purge cached probes when a relocation is cancelled. */
    public function forget(Relocation $relocation): void
    {
        foreach (['envkeys', 'dbinfo', 'envtpl', 'inventory', 'storagelink', 'serverip', 'dnslive'] as $prefix) {
            Cache::forget($this->key($relocation, $prefix));
        }
    }
}
