<?php

namespace App\Services;

use App\Models\Relocation;
use App\Models\Site;
use RuntimeException;
use Symfony\Component\Process\Process;

/**
 * The four relocation steps (collect / transfer / restore / verify), native
 * port of the legacy relocate-laravel.sh. The heavy lifting still happens on
 * the servers (bash over ssh), but orchestration, error handling and logging
 * are PHP. Every method streams combined stdout+stderr to $out and throws a
 * RuntimeException on failure — no "always exit 0" bashew semantics.
 */
class RelocationSteps
{
    /**
     * Archive storage/app, database and .env of the site on the source server,
     * into /tmp/relocate-<site>, with a sha256 manifest. Code is never
     * collected — it always deploys from the installed git repository.
     */
    protected const COLLECT_SCRIPT = <<<'REMOTE'
    set -euo pipefail
    site=$1
    user=$2
    site_dir="/home/$user/$site"
    work="/tmp/relocate-$site"

    [[ -d "$site_dir" ]] || { echo "!! site folder [$site_dir] not found" >&2; exit 1; }
    # start clean: archives of a previous run must not survive into this one
    if [[ -d "$work" ]]; then
      echo "... removing stale [$work] from a previous run"
      rm -rf "$work"
    fi
    mkdir -p "$work"

    # read KEY= from the site's .env, stripping optional surrounding quotes.
    # The inner grep may find nothing — that must not trip set -e/pipefail.
    env_get() { (grep -E "^$1=" "$site_dir/.env" || true) | tail -1 | cut -d= -f2- | sed -e 's/^"//' -e 's/"$//' -e "s/^'//" -e "s/'\$//"; }

    # static (HTML-only) sites have no .env and no database
    has_env=0
    if [[ -f "$site_dir/.env" ]]; then
      has_env=1
      cp "$site_dir/.env" "$work/env-$site.txt"
    else
      echo "?? no .env in [$site_dir] - static site? skipping .env and database"
    fi

    # password protection (Forge: Site → Security) does not travel with the site - record it.
    # Only count ENABLED auth_basic: every Forge site has "auth_basic off;" in its
    # letsencrypt challenge config, which must not trigger this.
    auth_hits=$( (grep -rnE '^[[:space:]]*auth_basic(_user_file)?[[:space:]]' \
      "/etc/nginx/sites-available/$site" "/etc/nginx/forge-conf/$site" 2>/dev/null || true) \
      | grep -vE 'auth_basic[[:space:]]+off' || true)
    if [[ -n "$auth_hits" ]]; then
      echo "?? this site is PASSWORD-PROTECTED (nginx auth_basic) - recreate the security rule in Forge on the target site"
      printf '%s\n' "$auth_hits" >"$work/nginx-auth-$site.txt"
    fi

    if [[ -d "$site_dir/storage/app" ]]; then
      echo "... storage/app -> storage-app-$site.tar.gz"
      tar czf "$work/storage-app-$site.tar.gz" -C "$site_dir/storage" app
    else
      echo "?? no storage/app in [$site_dir] - skipped (static site?)"
    fi

    if [[ $has_env -eq 1 ]]; then
    db_conn=$(env_get DB_CONNECTION)
    db_name=$(env_get DB_DATABASE)
    db_user=$(env_get DB_USERNAME)
    db_pass=$(env_get DB_PASSWORD)
    if [[ "$db_conn" == "sqlite" ]]; then
      # DB_DATABASE may be an absolute path, a path relative to the site, or empty (Laravel default)
      db_file="${db_name:-database/database.sqlite}"
      [[ "$db_file" != /* ]] && db_file="$site_dir/$db_file"
      if [[ -f "$db_file" ]]; then
        echo "... sqlite database [$db_file] -> db-$site.sqlite.gz"
        if command -v sqlite3 >/dev/null; then
          sqlite3 "$db_file" ".backup '$work/db-$site.sqlite'"   # consistent snapshot, even mid-write
        else
          cp "$db_file" "$work/db-$site.sqlite"
        fi
        gzip -f -6 "$work/db-$site.sqlite"
      else
        echo "?? sqlite database [$db_file] not found - dump skipped"
      fi
    elif [[ -n "$db_name" ]]; then
      command -v mysqldump >/dev/null || { echo "!! mysqldump not found on this server" >&2; exit 1; }
      echo "... database [$db_name] -> db-$site.sql.gz (consistent snapshot, incl. routines/triggers/events)"
      MYSQL_PWD="$db_pass" mysqldump -u "$db_user" \
        --single-transaction --quick --routines --triggers --events \
        --default-character-set=utf8mb4 "$db_name" | gzip -6 >"$work/db-$site.sql.gz"
      # approximate row counts, to compare with the target after restore
      MYSQL_PWD="$db_pass" mysql -u "$db_user" -N -B -e \
        "SELECT table_name, table_rows FROM information_schema.tables WHERE table_schema='$db_name' ORDER BY table_rows DESC;" \
        >"$work/db-tables-$site.txt"
    else
      echo "?? no DB_DATABASE in .env - database dump skipped"
    fi
    fi

    (cd "$work" && find . -maxdepth 1 -type f ! -name 'MANIFEST*' -exec sha256sum {} + >MANIFEST.sha256)
    if [[ ! -s "$work/MANIFEST.sha256" ]]; then
      echo "?? nothing to collect (no .env, storage or database) - transfer and restore will be no-ops"
    fi
    echo
    echo "Collected in [$work]:"
    ls -lh "$work"
    REMOTE;

    /**
     * Restore storage/app + database into the (possibly renamed) target site.
     * Code is NOT restored: the site must exist in Forge with its repository.
     */
    protected const RESTORE_SCRIPT = <<<'REMOTE'
    set -euo pipefail
    site=$1
    target_site=$2
    user=$3
    php_bin=${4:-php}
    command -v "$php_bin" >/dev/null 2>&1 || php_bin=php
    site_dir="/home/$user/$target_site"
    work="/tmp/relocate-$site"

    [[ -d "$work" ]] || { echo "!! [$work] not found - run transfer first" >&2; exit 1; }
    if [[ ! -d "$site_dir" ]]; then
      echo "!! [$site_dir] not found" >&2
      echo "!! create the site in Forge (Server → Sites → Add Site) and install the git repository first" >&2
      echo "!! never hand-copy nginx/supervisor/cron config - Forge must own this site" >&2
      exit 1
    fi
    me=$(id -un)
    [[ "$me" != "$user" ]] && echo "?? connected as [$me] but site user is [$user] - fix ownership afterwards: chown -R $user:$user $site_dir/storage"

    # a static (HTML-only) site produced no storage archive and no dump — nothing to restore
    if [[ -f "$work/storage-app-$site.tar.gz" ]]; then
      if [[ ! -f "$site_dir/artisan" ]]; then
        echo "!! [$site_dir/artisan] not found" >&2
        echo "!! create the site in Forge (Server → Sites → Add Site) and install the git repository first" >&2
        echo "!! never hand-copy nginx/supervisor/cron config - Forge must own this site" >&2
        exit 1
      fi
      echo "... restoring storage/app"
      mkdir -p "$site_dir/storage"
      tar xzf "$work/storage-app-$site.tar.gz" -C "$site_dir/storage"
    else
      echo "?? no storage archive in [$work] - skipped (static site?)"
    fi

    # import with the credentials Forge created on THIS server (never restore mysql system users)
    env_get() { (grep -E "^$1=" "$site_dir/.env" || true) | tail -1 | cut -d= -f2- | sed -e 's/^"//' -e 's/"$//' -e "s/^'//" -e "s/'\$//"; }

    sqlite_dump=$(ls "$work"/db-*.sqlite.gz 2>/dev/null | head -1 || true)
    dump=$(ls "$work"/db-*.sql.gz 2>/dev/null | head -1 || true)
    if [[ -z "$sqlite_dump" && -z "$dump" ]]; then
      echo "?? no database dump in [$work] - import skipped"
    else
    [[ -f "$site_dir/.env" ]] || { echo "!! no .env in [$site_dir] - fill in the Forge Environment tab first" >&2; exit 1; }
    db_name=$(env_get DB_DATABASE)
    db_user=$(env_get DB_USERNAME)
    db_pass=$(env_get DB_PASSWORD)
    if [[ -n "$sqlite_dump" ]]; then
      # place the file where the TARGET .env expects it; Laravel default otherwise
      db_conn=$(env_get DB_CONNECTION)
      db_file=$(env_get DB_DATABASE)
      [[ "$db_conn" != "sqlite" || -z "$db_file" ]] && db_file="database/database.sqlite"
      [[ "$db_file" != /* ]] && db_file="$site_dir/$db_file"
      echo "... restoring sqlite database -> $db_file"
      mkdir -p "$(dirname "$db_file")"
      gunzip -c "$sqlite_dump" >"$db_file"
      rm -f "$db_file-wal" "$db_file-shm"   # stale WAL/SHM of a previous database
    elif [[ -z "$db_name" ]]; then
      echo "?? no DB_DATABASE in the target .env - import skipped"
    else
      echo "... importing $(basename "$dump") into [$db_name] as [$db_user]"
      gunzip -c "$dump" | MYSQL_PWD="$db_pass" mysql -u "$db_user" "$db_name"
      echo "... row counts on this server (compare with $work/db-tables-$site.txt from the source):"
      MYSQL_PWD="$db_pass" mysql -u "$db_user" -N -B -e \
        "SELECT table_name, table_rows FROM information_schema.tables WHERE table_schema='$db_name' ORDER BY table_rows DESC LIMIT 10;"
    fi
    fi

    if [[ -f "$site_dir/artisan" ]]; then
      echo "... $php_bin artisan storage:link --force"
      (cd "$site_dir" && "$php_bin" artisan storage:link --force) || echo "?? storage:link failed - run it manually"
    else
      echo "?? no artisan in [$site_dir] - storage:link skipped (static site?)"
    fi
    REMOTE;

    /**
     * Scan the target site for references to the old server (hardcoded IPs,
     * hostnames, stale cached config). Exits non-zero when references remain.
     */
    protected const VERIFY_SCRIPT = <<<'REMOTE'
    set -uo pipefail
    site=$1
    user=$2
    old_ip=$3
    old_host=$4
    site_dir="/home/$user/$site"
    [[ -d "$site_dir" ]] || { echo "!! [$site_dir] not found" >&2; exit 1; }

    echo "## key .env values on this server:"
    if [[ -f "$site_dir/.env" ]]; then
      grep -E '^(APP_URL|APP_ENV|DB_HOST|DB_DATABASE|REDIS_HOST|MAIL_HOST|CACHE_DRIVER|SESSION_DRIVER|QUEUE_CONNECTION)=' "$site_dir/.env" || true
    else
      echo "(no .env - static site?)"
    fi
    echo

    problems=0
    for needle in "$old_ip" "$old_host"; do
      [[ -z "$needle" ]] && continue
      echo "## references to [$needle] in $site_dir (vendor/node_modules/.git/storage excluded):"
      hits=$(grep -rIl \
        --exclude-dir=vendor --exclude-dir=node_modules --exclude-dir=.git --exclude-dir=storage \
        -e "$needle" "$site_dir" 2>/dev/null || true)
      if [[ -n "$hits" ]]; then
        echo "$hits"
        problems=1
      else
        echo "(none)"
      fi
      echo
    done

    # stale cached config is the classic place where old hosts/paths survive a relocation
    if [[ -f "$site_dir/bootstrap/cache/config.php" ]]; then
      echo "?? bootstrap/cache/config.php exists - after fixing .env, run: php artisan config:clear && php artisan config:cache"
    fi
    echo "?? also check data stored IN the database for absolute URLs pointing to the old server"

    if [[ $problems -ne 0 ]]; then
      echo "!! old-server references found - fix them before cutover" >&2
      exit 1
    fi
    echo "OK - no references to the old server found in the site files"
    REMOTE;

    /**
     * @param  callable(string): void  $out
     * @return string one-line metrics: archive sizes + duration
     */
    public function collect(Relocation $relocation, callable $out): string
    {
        $startedAt = microtime(true);
        $site = $relocation->site_name;
        $source = $relocation->sourceServer->name;
        $this->assertReachable($source);
        $out("Collect [{$site}] on [{$source}]: storage/app, database, .env (code always deploys from git)\n");
        $this->remote($source, self::COLLECT_SCRIPT, [$site, $relocation->unix_user], $out,
            "collect failed on [{$source}]");
        $out("collect done: archives in /tmp/relocate-{$site} on [{$source}]\n");

        return $this->sizesSummary($this->remoteFileSizes($source, $site))
            .' · took '.$this->duration(microtime(true) - $startedAt);
    }

    /**
     * @param  callable(string): void  $out
     * @return string one-line metrics: sizes at destination + throughput
     */
    public function transfer(Relocation $relocation, callable $out): string
    {
        $site = $relocation->site_name;
        $source = $relocation->sourceServer->name;
        $target = $relocation->targetServer->name;
        $this->assertReachable($source);
        $this->assertReachable($target);

        $manifest = new Process([...$this->sshCommand($source), "[[ -f /tmp/relocate-{$site}/MANIFEST.sha256 ]]"],
            env: $this->env(), timeout: 30);
        if ($manifest->run() !== 0) {
            throw new RuntimeException("nothing to transfer on [{$source}] - run collect first");
        }

        $out("Transfer /tmp/relocate-{$site} from [{$source}] to [{$target}] (relayed via this machine)\n");
        $out("removing stale /tmp/relocate-{$site} on [{$target}] first\n");
        $startedAt = microtime(true);
        $ssh = implode(' ', $this->sshCommand());
        $pipeline = sprintf('set -o pipefail; %s %s %s | %s %s %s',
            $ssh, escapeshellarg($source), escapeshellarg("tar cf - -C /tmp relocate-{$site}"),
            $ssh, escapeshellarg($target), escapeshellarg("rm -rf /tmp/relocate-{$site} && tar xf - -C /tmp"));
        $this->run(['bash', '-c', $pipeline], $out, 'transfer failed', timeout: 7200);
        $elapsed = max(microtime(true) - $startedAt, 0.001);

        $out("Verifying checksums on [{$target}] ...\n");
        $this->run([...$this->sshCommand($target),
            "cd /tmp/relocate-{$site} && if [ -s MANIFEST.sha256 ]; then sha256sum --check --quiet MANIFEST.sha256; else echo 'nothing to verify (empty manifest)'; fi",
        ], $out, 'checksum mismatch after transfer - do NOT restore from these files', timeout: 600);
        $out("transfer done, checksums verified on [{$target}]\n");

        $sizes = $this->remoteFileSizes($target, $site);
        $total = array_sum($sizes);

        return 'at destination: '.$this->sizesSummary($sizes)
            .' · total '.$this->human($total)
            .' in '.$this->duration($elapsed)
            .' ('.$this->human((int) ($total / $elapsed)).'/s)';
    }

    /** @param  callable(string): void  $out */
    public function restore(Relocation $relocation, callable $out): void
    {
        $site = $relocation->site_name;
        $target = $relocation->targetServer->name;
        $targetSite = $relocation->targetSiteName();
        $this->assertReachable($target);
        $out("code is NOT restored from the archive: the site must exist in Forge with its git repository installed\n");
        $out("Restore storage/app and database of [{$site}] into [{$targetSite}] on [{$target}]\n");
        $this->remote($target, self::RESTORE_SCRIPT,
            [$site, $targetSite, $relocation->unix_user, $this->phpBinary($relocation)], $out,
            "restore failed on [{$target}]");
        $out("restore done on [{$target}]\n");
        $out("Next: diff the source .env (/tmp/relocate-{$site}/env-{$site}.txt on {$target}) against the Forge Environment tab\n");
    }

    /**
     * @param  callable(string): void  $out
     * @return string one-line result for the step card
     */
    public function verify(Relocation $relocation, callable $out): string
    {
        $site = $relocation->site_name;
        $source = $relocation->sourceServer->name;
        $target = $relocation->targetServer->name;
        $targetSite = $relocation->targetSiteName();
        $this->assertReachable($source);
        $this->assertReachable($target);

        $oldIp = $this->remoteOutput($source, "hostname -I 2>/dev/null | awk '{print \$1}'");
        $oldHost = $this->remoteOutput($source, 'hostname -f 2>/dev/null || hostname');
        $out("old server: host [{$oldHost}] / ip [{$oldIp}]\n");

        // password protection does not travel with the site (Forge: Site → Security).
        // Only ENABLED auth_basic counts — every Forge site has "auth_basic off;"
        // in its letsencrypt challenge config.
        $authGrep = fn (string $siteName) => "grep -rnE '^[[:space:]]*auth_basic(_user_file)?[[:space:]]'"
            ." /etc/nginx/sites-available/{$siteName} /etc/nginx/forge-conf/{$siteName} 2>/dev/null"
            ." | grep -vE 'auth_basic[[:space:]]+off'";
        $authNote = '';
        if ($this->remoteOutput($source, $authGrep($site)) !== '') {
            if ($this->remoteOutput($target, $authGrep($targetSite)) === '') {
                $out("!! the OLD site is password-protected (nginx auth_basic) but the NEW site is NOT - recreate the security rule in Forge (Site → Security) on [{$target}]\n");
                $authNote = ' · recreate the auth_basic security rule!';
            } else {
                $out("password protection (auth_basic) present on both old and new site\n");
            }
        }

        $this->remote($target, self::VERIFY_SCRIPT,
            [$targetSite, $relocation->unix_user, $oldIp, $oldHost], $out,
            "verify found references to the old server on [{$target}]");
        $out("verify done: no hardcoded references to [{$source}] found on [{$target}]\n");

        return "no references to {$oldHost} / {$oldIp} found on {$target}".$authNote;
    }

    /**
     * The site's own PHP binary (e.g. "php8.3") — running artisan with the
     * server's default CLI PHP can be a different major version.
     */
    protected function phpBinary(Relocation $relocation): string
    {
        $version = Site::where('server_id', $relocation->source_server_id)
            ->where('name', $relocation->site_name)
            ->value('php_version');
        $version = trim((string) preg_replace('/^php\s*/i', '', (string) $version));

        return preg_match('/^\d+\.\d+$/', $version) ? "php{$version}" : 'php';
    }

    /** ssh binary + options, as an argv prefix; append host + command. */
    protected function sshCommand(?string $host = null): array
    {
        static $ssh;
        $ssh ??= app(SetupStatus::class)->findBinary('ssh') ?? 'ssh';
        $command = [$ssh, '-o', 'BatchMode=yes', '-o', 'ConnectTimeout=10'];
        if ($host !== null) {
            $command[] = $host;
        }

        return $command;
    }

    /** HOME must be set or ssh can't find ~/.ssh/config with the host aliases. */
    protected function env(): array
    {
        return ['HOME' => app(SetupStatus::class)->home()];
    }

    /** Run a remote command and return its trimmed stdout ('' on failure). */
    protected function remoteOutput(string $host, string $command): string
    {
        $process = new Process([...$this->sshCommand($host), $command], env: $this->env(), timeout: 30);
        $process->run();

        return trim($process->getOutput());
    }

    protected function assertReachable(string $host): void
    {
        $process = new Process([...$this->sshCommand($host), 'true'], env: $this->env(), timeout: 20);
        if ($process->run() !== 0) {
            throw new RuntimeException("cannot reach [{$host}] via passwordless ssh - check ~/.ssh/config");
        }
    }

    /** Run a remote bash script (via stdin) with positional args on a host. */
    protected function remote(string $host, string $script, array $args, callable $out, string $errorMessage): void
    {
        $command = [...$this->sshCommand(), '-T', $host, 'bash', '-s', '--', ...$args];
        $this->run($command, $out, $errorMessage, input: $script, timeout: 7200);
    }

    protected function run(array $command, callable $out, string $errorMessage, ?string $input = null, int $timeout = 300): void
    {
        $process = new Process($command, env: $this->env(), input: $input, timeout: $timeout);
        $exitCode = $process->run(fn (string $type, string $buffer) => $out($buffer));
        if ($exitCode !== 0) {
            throw new RuntimeException($errorMessage);
        }
    }

    /** @return array<string, int> archive filename => bytes, in /tmp/relocate-<site> on the host */
    protected function remoteFileSizes(string $host, string $site): array
    {
        $process = new Process([...$this->sshCommand($host),
            "find /tmp/relocate-{$site} -maxdepth 1 -type f -printf '%f %s\\n'",
        ], env: $this->env(), timeout: 30);
        if ($process->run() !== 0) {
            return [];
        }

        $sizes = [];
        foreach (explode("\n", trim($process->getOutput())) as $line) {
            $parts = explode(' ', trim($line));
            if (count($parts) === 2 && is_numeric($parts[1])) {
                $sizes[$parts[0]] = (int) $parts[1];
            }
        }

        return $sizes;
    }

    /** "db 124 MB · storage/app 3.4 MB" */
    protected function sizesSummary(array $sizes): string
    {
        $sum = fn (string $prefix) => collect($sizes)
            ->filter(fn (int $bytes, string $name) => str_starts_with($name, $prefix))
            ->sum();
        $db = $sum('db-');
        $storage = $sum('storage-app-');

        return implode(' · ', [
            'db '.($db ? $this->human($db) : 'none'),
            'storage/app '.($storage ? $this->human($storage) : 'none'),
        ]);
    }

    protected function human(int $bytes): string
    {
        foreach (['B' => 1, 'kB' => 1024, 'MB' => 1024 ** 2, 'GB' => 1024 ** 3] as $unit => $factor) {
            if ($bytes < $factor * 1024 || $unit === 'GB') {
                $value = $bytes / $factor;

                return ($value >= 100 || $unit === 'B' ? round($value) : round($value, 1))." {$unit}";
            }
        }

        return "{$bytes} B";
    }

    protected function duration(float $seconds): string
    {
        return $seconds < 90
            ? round($seconds).'s'
            : floor($seconds / 60).'m '.round(fmod($seconds, 60)).'s';
    }
}
