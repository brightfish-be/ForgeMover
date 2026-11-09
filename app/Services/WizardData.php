<?php

namespace App\Services;

use App\Enums\RelocationStep;
use App\Models\Relocation;
use App\Models\Setting;
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
        protected HttpProbe $http,
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
            if ($step->isScript() && $done && $state !== 'success') {
                $state = 'skipped';
            }

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
            'forge_site:source' => $this->checkSourceControl($relocation),
            'forge_site:repo' => $this->checkRepo($relocation),
            'forge_site:db' => $this->checkDb($relocation),
            'forge_site:domain' => $this->checkDomain($relocation),
            'forge_site:composer' => $this->isStatic($relocation)
                ? ['label' => 'Install composer dependencies', 'status' => 'pass', 'detail' => 'Static HTML site — leave "Install Composer Dependencies" off']
                : $this->info('Install composer dependencies', 'Tick "Install Composer Dependencies" when creating the site'),
            'forge_site:deploykey' => $this->info('Deploy key', 'Leave off — the org-level integration handles auth'),
            'forge_site:webdir' => $this->checkWebDir($relocation),
            'forge_site:php' => $this->checkSitePhp($relocation),
            'forge_site:isolation' => $this->checkIsolation($relocation),
            'forge_site:zdd' => $this->checkZdd($relocation),
            'forge_site:env' => $this->isStatic($relocation)
                ? ['label' => '.env filled in on target', 'status' => 'pass', 'detail' => 'Static HTML site — no .env needed']
                : $this->info('.env filled in on target', 'Paste the generated template below into the Forge site .env'),
            'deploy_test:storagelink' => $this->checkStorageLink($relocation),
            'cutover:dnslive' => $this->checkDnsLive($relocation),
            'cutover:tls' => $this->checkTls($relocation),
            'cutover:https' => $this->checkHttps($relocation),
            'cutover:default' => $this->info("Set {$relocation->site_name} as the default domain",
                'In Forge: target site → Domains → add '.$relocation->site_name.', set it as default'
                .($relocation->temp_domain ? ", then remove {$relocation->temp_domain}" : '')),
            'cutover:env' => $this->checkCutoverEnv($relocation),
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

    /** Site type + web directory for the wizard header ("static · web dir /_site"). */
    public function siteInfo(Relocation $relocation): array
    {
        return [
            'type' => $this->sourceSite($relocation)?->app_type ?: null,
            'static' => $this->isStatic($relocation),
            'web_dir' => $this->webDir($relocation),
        ];
    }

    /** Static HTML sites need no PHP, database, .env or storage symlink. */
    protected function isStatic(Relocation $relocation): bool
    {
        return ($this->sourceSite($relocation)?->app_type ?? '') === 'static';
    }

    protected function webDir(Relocation $relocation): ?string
    {
        return $this->cached($relocation, 'webdir', 'web_dir',
            fn () => $this->probe->webDirectory($relocation->sourceServer->name, $relocation->site_name, $relocation->unix_user));
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
        if ($this->isStatic($relocation)) {
            return ['label' => 'PHP version on target', 'status' => 'pass',
                'detail' => 'Static HTML site — PHP not required'];
        }
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
        if ($this->isStatic($relocation)) {
            return ['label' => 'APP_KEY & credentials strategy', 'status' => 'pass',
                'detail' => 'Static HTML site — no credentials to rotate'];
        }
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
        $manage = $this->dns->manageUrl($dns['provider'], $dns['zone'], $this->primaryNs($dns));
        $target = $dns['ip'] ? "IP {$dns['ip']}" : (($dns['cname'] ?? '') ? "CNAME → {$dns['cname']}" : '');
        $checked = isset($dns['checked_at'])
            ? " · checked {$dns['checked_at']}".(($dns['authoritative'] ?? false) ? ' (authoritative)' : ' (resolver)')
            : '';
        if ($ttl === null) {
            return [
                'label' => "DNS TTL ({$dns['provider']}, zone {$dns['zone']})",
                'status' => 'unknown',
                'detail' => 'Could not read the record TTL — check manually'
                    .($manage ? " · manage: {$manage}" : '').$checked,
            ];
        }
        $ok = $ttl <= 300;

        return [
            'label' => "DNS TTL ≤ 300 s ({$dns['provider']}, zone {$dns['zone']})",
            'status' => $ok ? 'pass' : 'fail',
            'detail' => ($ok ? trim("TTL {$ttl}s · {$target}", ' ·')
                : "TTL is {$ttl}s — lower it to 60–300 s at least 24 h before cutover")
                .($manage ? " · manage: {$manage}" : '').$checked,
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

    /**
     * Forge v2 source-control integrations are org-level: any synced site
     * already deploying from the same provider proves it's connected.
     */
    protected function checkSourceControl(Relocation $relocation): array
    {
        $provider = $this->sourceSite($relocation)?->repo_provider ?: null;
        if (! $provider) {
            return $this->info('Source control connected', 'Connect the git provider in Forge');
        }
        $inUse = Site::where('repo_provider', $provider)->where('repo_url', '!=', '')->count();

        return $inUse
            ? ['label' => 'Source control connected', 'status' => 'pass',
                'detail' => ucfirst($provider)." org integration in use by {$inUse} synced site(s)"]
            : $this->info('Source control connected', "Connect {$provider} in Forge");
    }

    /** The repo must be readable from the target server, or Forge's clone fails with "Permission denied (publickey)". */
    protected function checkRepo(Relocation $relocation): array
    {
        $site = $this->sourceSite($relocation);
        if (! $site?->repo_url) {
            return ['label' => 'Repository', 'status' => 'unknown', 'detail' => 'No repo known for the source site'];
        }
        $sshUrl = $this->gitSshUrl($site->repo_url);
        $readable = Cache::remember($this->key($relocation, 'gitaccess'), config('forgemover.ttl.git_access'),
            fn () => $this->probe->gitReadable($relocation->targetServer->name, $sshUrl));
        $host = parse_url($site->repo_url, PHP_URL_HOST) ?: 'the git host';

        return match ($readable) {
            true => ['label' => "Repository readable from {$relocation->targetServer->name}", 'status' => 'pass',
                'detail' => "{$site->repo_url} · branch {$site->repo_branch}"],
            false => ['label' => "Repository readable from {$relocation->targetServer->name}", 'status' => 'fail',
                'detail' => "{$relocation->targetServer->name} cannot read {$sshUrl} — add its SSH key"
                    ." (ssh {$relocation->targetServer->name} 'cat ~/.ssh/id_*.pub') to {$host}"],
            null => ['label' => 'Repository', 'status' => 'unknown',
                'detail' => "{$site->repo_url} · branch {$site->repo_branch} · target unreachable for the access test"],
        };
    }

    /** Forge clones over ssh — convert an https repo URL to the git@host:path.git form. */
    protected function gitSshUrl(string $url): string
    {
        return preg_match('~^https?://([^/]+)/(.+?)(\.git)?/?$~', $url, $m)
            ? "git@{$m[1]}:{$m[2]}.git"
            : $url;
    }

    protected function checkDb(Relocation $relocation): array
    {
        if ($this->isStatic($relocation)) {
            return ['label' => 'Create database on target', 'status' => 'pass',
                'detail' => 'Static HTML site — no database'];
        }
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
        if ($this->isStatic($relocation)) {
            return ['label' => 'Site PHP version', 'status' => 'pass',
                'detail' => 'Static HTML site — any PHP version'];
        }
        $needed = $this->normalizePhp($this->sourceSite($relocation)?->php_version);

        return ['label' => 'Site PHP version', 'status' => 'unknown',
            'detail' => $needed ? "Select PHP {$needed} when creating the site" : 'Match the source site'];
    }

    protected function checkWebDir(Relocation $relocation): array
    {
        $webdir = $this->webDir($relocation);

        return ['label' => 'Web directory', 'status' => 'unknown',
            'detail' => $webdir && $webdir !== '/public'
                ? "Use {$webdir} — the source nginx serves from there, not /public"
                : 'Use /public'];
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
        if ($this->isStatic($relocation)) {
            return ['label' => 'public/storage symlink', 'status' => 'pass',
                'detail' => 'Static HTML site — no storage symlink needed'];
        }
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
        $dns = $this->cached($relocation, 'dns', 'dns',
            fn () => $this->dns->lookup($relocation->site_name), $relocation->site_name);
        $live = Cache::remember($this->key($relocation, 'dnslive'), config('forgemover.ttl.dns_live'),
            fn () => $this->dns->liveIp($relocation->site_name, $dns['nameservers'][0] ?? null));

        $ok = $expected && ($live['ip'] ?? null) === $expected;
        $checked = isset($live['checked_at'])
            ? " · checked {$live['checked_at']}".(($live['authoritative'] ?? false) ? ' (authoritative)' : ' (resolver)')
            : '';
        $record = match (true) {
            ($live['cname'] ?? '') !== '' => "CNAME → {$live['cname']}".(($live['ip'] ?? '') ? " → {$live['ip']}" : ' (unresolved)'),
            ($live['ip'] ?? '') !== '' => "A record {$live['ip']}",
            default => 'no A/CNAME record',
        };

        $manage = ($dns['ok'] ?? false)
            ? $this->dns->manageUrl($dns['provider'], $dns['zone'], $this->primaryNs($dns)) : null;

        return [
            'label' => "DNS points at {$relocation->targetServer->name}".($expected ? " ({$expected})" : ''),
            'status' => $ok ? 'pass' : 'fail',
            'detail' => ($ok
                ? "Live {$record}, TTL {$live['ttl']}s"
                : "Live {$record} — repoint {$relocation->site_name} to the target"
                    .($manage ? " · manage: {$manage}" : ''))
                .$checked,
        ];
    }

    /** Zone facts for the wizard's DNS panel: nameservers, SOA, manage link. */
    public function dnsInfo(Relocation $relocation): array
    {
        $dns = $this->cached($relocation, 'dns', 'dns',
            fn () => $this->dns->lookup($relocation->site_name), $relocation->site_name);
        if (! ($dns['ok'] ?? false)) {
            return ['ok' => false];
        }
        $primaryNs = $this->primaryNs($dns);

        return [
            ...$dns,
            'manage_ns' => $primaryNs,
            'manage' => $this->dns->manageUrl($dns['provider'], $dns['zone'], $primaryNs),
            'manage_custom' => $primaryNs
                && Setting::get('dns_manage:'.DnsService::nsKey($primaryNs)) !== null,
        ];
    }

    /** The zone's canonical nameserver: SOA primary, NS list as fallback. */
    protected function primaryNs(array $dns): ?string
    {
        return $dns['soa']['primary'] ?? $dns['nameservers'][0] ?? null;
    }

    /** TLS handshake against the target IP directly — works before the DNS cutover. */
    protected function checkTls(Relocation $relocation): array
    {
        $label = "TLS certificate for {$relocation->site_name} on {$relocation->targetServer->name}";
        $expected = Cache::remember($this->key($relocation, 'serverip'), config('forgemover.ttl.server_ip'),
            fn () => $this->probe->publicIp($relocation->targetServer->name));
        if (! $expected) {
            return ['label' => $label, 'status' => 'unknown',
                'detail' => 'Target server IP unknown — probe the target server first'];
        }
        $tls = Cache::remember($this->key($relocation, 'tlscert'), config('forgemover.ttl.https_live'),
            fn () => $this->http->status($relocation->site_name, $expected));

        return $tls['code'] !== null
            ? ['label' => $label, 'status' => 'pass',
                'detail' => "TLS handshake OK on {$expected} (HTTP {$tls['code']}) · checked {$tls['checked_at']}"]
            : ['label' => $label, 'status' => 'fail',
                'detail' => "In Forge: add the domain to the target site and issue a Let's Encrypt certificate"
                    ." — {$tls['error']} · checked {$tls['checked_at']}"];
    }

    /** Live status code of https://<domain> via normal DNS — the post-cutover smoke test. */
    protected function checkHttps(Relocation $relocation): array
    {
        $live = Cache::remember($this->key($relocation, 'httpslive'), config('forgemover.ttl.https_live'),
            fn () => $this->http->status($relocation->site_name));
        $ok = $live['code'] !== null && $live['code'] < 400;

        return [
            'label' => "https://{$relocation->site_name} responds",
            'status' => $ok ? 'pass' : 'fail',
            'detail' => ($live['code'] !== null ? "HTTP {$live['code']}" : $live['error'])
                ." · checked {$live['checked_at']}",
        ];
    }

    /**
     * Target .env updated for the final domain: APP_URL must point at the real
     * domain, carry-over keys must match the source .env. Only non-secret keys.
     */
    protected function checkCutoverEnv(Relocation $relocation): array
    {
        $label = "Target .env updated for {$relocation->site_name}";
        if ($this->isStatic($relocation)) {
            return ['label' => $label, 'status' => 'pass', 'detail' => 'Static HTML site — no .env'];
        }
        $manage = ($url = $this->targetSite($relocation)?->forgeUrl()) ? "{$url}/environment" : null;
        $hint = 'tick "Run php artisan config:cache" and restart the queue workers after updating'
            .($manage ? " · manage: {$manage}" : '');

        $keys = ['APP_NAME', 'APP_TIMEZONE', 'APP_URL', 'LOG_STACK'];
        $source = $this->cached($relocation, 'envsrc', 'env_keys',
            fn () => $this->probe->envValues($relocation->sourceServer->name,
                [$relocation->site_name], $keys, $relocation->unix_user));
        $target = Cache::remember($this->key($relocation, 'envtgt'), config('forgemover.ttl.env_target'),
            fn () => $this->probe->envValues($relocation->targetServer->name,
                [$relocation->targetSiteName(), $relocation->site_name], $keys, $relocation->unix_user));

        if ($target === null) {
            return ['label' => $label, 'status' => 'unknown',
                'detail' => "Could not read the target .env — {$hint}"];
        }

        $expected = [
            'APP_URL' => 'https://'.$relocation->site_name,
            ...array_intersect_key($source ?? [], array_flip(['APP_NAME', 'APP_TIMEZONE', 'LOG_STACK'])),
        ];
        $wrong = [];
        foreach ($expected as $key => $value) {
            if (rtrim($target[$key] ?? '', '/') !== rtrim($value, '/')) {
                $wrong[] = "{$key}={$value}";
            }
        }

        return $wrong === []
            ? ['label' => $label, 'status' => 'pass',
                'detail' => implode(', ', array_keys($expected)).' set for the final domain']
            : ['label' => $label, 'status' => 'fail',
                'detail' => 'In Forge set '.implode(', ', $wrong)." — {$hint}"];
    }

    // ── extra panels ─────────────────────────────────────────────────────

    public function envTemplate(Relocation $relocation): ?string
    {
        if ($this->isStatic($relocation)) {
            return null;
        }

        return $this->cached($relocation, 'envtpl', 'env_template',
            fn () => $this->probe->envTemplate($relocation->sourceServer->name, $relocation->site_name, $relocation->unix_user));
    }

    public function sizes(Relocation $relocation): array
    {
        return Cache::remember($this->key($relocation, 'sizes'), config('forgemover.ttl.sizes'), fn () => [
            'old' => $this->probe->sizes($relocation->sourceServer->name,
                [$relocation->site_name], $relocation->unix_user, $relocation->site_name),
            'new' => $this->probe->sizes($relocation->targetServer->name,
                array_unique([$relocation->targetSiteName(), $relocation->site_name]),
                $relocation->unix_user, $relocation->site_name),
            'fetched_at' => now()->format('H:i:s'),
        ]);
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
        foreach (['envkeys', 'dbinfo', 'envtpl', 'inventory', 'storagelink', 'serverip', 'dnslive', 'tlscert', 'httpslive', 'envsrc', 'envtgt', 'webdir', 'gitaccess', 'sizes'] as $prefix) {
            Cache::forget($this->key($relocation, $prefix));
        }
    }
}
