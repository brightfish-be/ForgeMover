<?php

namespace App\Services;

use Illuminate\Process\Exceptions\ProcessTimedOutException;
use Illuminate\Support\Facades\Process;

/**
 * Read-only ssh probes against Forge servers. All connections use the local
 * ~/.ssh/config host aliases with BatchMode (passwordless keys required).
 */
class RemoteProbe
{
    /** Full path to ssh — the web server often runs with a stripped PATH. */
    protected function ssh(): string
    {
        static $ssh;

        return $ssh ??= app(SetupStatus::class)->findBinary('ssh') ?? 'ssh';
    }

    /** HOME must be set or ssh can't find ~/.ssh/config with the host aliases. */
    protected function env(): array
    {
        return ['HOME' => app(SetupStatus::class)->home()];
    }

    /** Run a command over ssh; returns trimmed combined output, or null on failure. */
    public function run(string $host, string $command, ?int $timeout = null): ?string
    {
        $timeout ??= config('forgemover.ssh_timeout');
        try {
            $result = Process::timeout($timeout + 2)->env($this->env())->run([
                $this->ssh(), '-o', 'BatchMode=yes', '-o', "ConnectTimeout={$timeout}", $host, $command,
            ]);
        } catch (ProcessTimedOutException) {
            return null;
        }

        return $result->successful() ? trim($result->output()) : null;
    }

    public function reachable(string $host): bool
    {
        return $this->run($host, 'true', 6) !== null;
    }

    /** One-shot key=value fact probe (OS, PHP, DB, Redis, CPU, RAM, disk, uptime). */
    public function facts(string $host): ?array
    {
        $script = <<<'SH'
        . /etc/os-release 2>/dev/null; echo "os=$PRETTY_NAME"; echo "osver=$VERSION_ID"
        echo "php=$(ls /usr/bin/php[0-9]* 2>/dev/null | sed 's|/usr/bin/php||' | tr '\n' ' ')"
        echo "phpdefault=$(php -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;' 2>/dev/null)"
        echo "dbver=$(mysqld --version 2>/dev/null || mariadbd --version 2>/dev/null)"
        echo "redisver=$(redis-server --version 2>/dev/null)"
        echo "cpus=$(nproc 2>/dev/null)"
        echo "ramkb=$(awk '/MemTotal/{print $2}' /proc/meminfo 2>/dev/null)"
        echo "disk=$(df -h --output=used,size,pcent / 2>/dev/null | tail -1)"
        echo "uptime=$(cut -d. -f1 /proc/uptime 2>/dev/null)"
        echo "db=$(pgrep -x mysqld >/dev/null || pgrep -x mariadbd >/dev/null && echo 1 || echo 0)"
        echo "redis=$(pgrep -x redis-server >/dev/null && echo 1 || echo 0)"
        SH;

        $output = $this->run($host, $script, 6);
        if ($output === null) {
            return null;
        }

        $raw = [];
        foreach (explode("\n", $output) as $line) {
            if (str_contains($line, '=')) {
                [$key, $value] = explode('=', $line, 2);
                $raw[trim($key)] = trim($value);
            }
        }

        $disk = preg_split('/\s+/', trim($raw['disk'] ?? ''));

        return [
            'os' => $raw['os'] ?? '',
            'os_version' => $raw['osver'] ?? '',
            'php_versions' => array_values(array_filter(explode(' ', $raw['php'] ?? ''))),
            'php_default' => $raw['phpdefault'] ?? '',
            'db_version' => $this->prettyVersion($raw['dbver'] ?? ''),
            'redis_version' => $this->prettyVersion($raw['redisver'] ?? ''),
            'cpus' => (int) ($raw['cpus'] ?? 0),
            'ram_gb' => isset($raw['ramkb']) && $raw['ramkb'] !== ''
                ? (string) round(((int) $raw['ramkb']) / 1048576, 1) : '',
            'disk_used' => $disk[0] ?? '',
            'disk_total' => $disk[1] ?? '',
            'disk_pct' => (int) rtrim($disk[2] ?? '0', '%'),
            'uptime_s' => (int) ($raw['uptime'] ?? 0),
            'has_db' => ($raw['db'] ?? '0') === '1',
            'has_redis' => ($raw['redis'] ?? '0') === '1',
        ];
    }

    protected function prettyVersion(string $raw): string
    {
        if (preg_match('/mariadb.*?(\d+\.\d+\.\d+)/i', $raw, $m)) {
            return "MariaDB {$m[1]}";
        }
        if (preg_match('/ver\s+(\d+\.\d+\.\d+)/i', $raw, $m)) {
            return "MySQL {$m[1]}";
        }
        if (preg_match('/v=(\d+\.\d+\.\d+)/', $raw, $m)) {
            return "Redis {$m[1]}";
        }

        return '';
    }

    /** @return array<string, string> site => app type (laravel 11|wordpress|php|static|empty) */
    public function siteTypes(string $host, string $user = 'forge'): array
    {
        $script = <<<SH
        for d in /home/{$user}/*/; do
          s=\$(basename "\$d"); dir="\$d"
          [ -d "\${d}current" ] && dir="\${d}current/"
          if [ -f "\${dir}artisan" ]; then
            v=\$(grep -o '"laravel/framework": *"[^"]*"' "\${dir}composer.lock" 2>/dev/null | grep -o '[0-9]*' | head -1)
            [ -z "\$v" ] && v=\$(grep -A2 '"name": "laravel/framework"' "\${dir}composer.lock" 2>/dev/null | grep -o 'v[0-9]*' | head -1 | tr -d v)
            echo "\$s=laravel \$v"
          elif [ -f "\${dir}wp-config.php" ]; then echo "\$s=wordpress"
          elif [ -f "\${dir}composer.json" ] || ls "\${dir}"*.php >/dev/null 2>&1; then echo "\$s=php"
          elif [ -f "\${dir}index.html" ]; then echo "\$s=static"
          else echo "\$s=empty"
          fi
        done
        SH;

        $output = $this->run($host, $script, 12) ?? '';
        $types = [];
        foreach (explode("\n", $output) as $line) {
            if (str_contains($line, '=')) {
                [$site, $type] = explode('=', $line, 2);
                $types[trim($site)] = trim($type);
            }
        }

        return $types;
    }

    /** Names of .env keys with non-empty values on the source site. */
    public function envKeys(string $host, string $site, string $user = 'forge'): array
    {
        $output = $this->run($host,
            "awk -F= '/^[A-Z][A-Z0-9_]*=./ {print \$1}' /home/{$user}/{$site}/.env 2>/dev/null", 10);

        return $output === null ? [] : array_values(array_filter(explode("\n", $output)));
    }

    /** Paste-ready redacted .env template for the target's Forge site. */
    public function envTemplate(string $host, string $site, string $user = 'forge'): ?string
    {
        $drop = 'APP_KEY|APP_ENV|APP_DEBUG|APP_LOCALE|APP_FALLBACK_LOCALE|APP_FAKER_LOCALE|'
            .'APP_MAINTENANCE_DRIVER|APP_MAINTENANCE_STORE|BCRYPT_ROUNDS|LOG_DEPRECATIONS_CHANNEL|'
            .'SESSION_DRIVER|SESSION_LIFETIME|SESSION_ENCRYPT|SESSION_PATH|SESSION_DOMAIN|VITE_APP_NAME';
        $script = <<<SH
        awk -F= '
          /^\s*#/ {next}
          /^\s*\$/ {print ""; next}
          /^(DB_|({$drop})=)/ {next}
          /^[A-Z][A-Z0-9_]*=./ {
            key=\$1
            if (key ~ /(PASSWORD|SECRET|KEY|TOKEN|DSN|SALT|AUTH|PRIVATE|LICENSE|WEBHOOK)/)
              print key "=<insert secret>"
            else
              print \$0
          }
        ' /home/{$user}/{$site}/.env 2>/dev/null | cat -s
        SH;

        return $this->run($host, $script, 10);
    }

    /** DB name/user/connection/host + size and table count. Never returns the password. */
    public function dbInfo(string $host, string $site, string $user = 'forge'): ?array
    {
        $script = <<<SH
        env=/home/{$user}/{$site}/.env
        [ -f "\$env" ] || exit 1
        get() { grep "^\$1=" "\$env" | head -1 | cut -d= -f2- | tr -d '"'; }
        db=\$(get DB_DATABASE); dbu=\$(get DB_USERNAME); conn=\$(get DB_CONNECTION); dbh=\$(get DB_HOST)
        echo "database=\$db"; echo "username=\$dbu"; echo "connection=\$conn"; echo "host=\$dbh"
        pw=\$(get DB_PASSWORD)
        if [ -n "\$db" ] && [ -n "\$pw" ]; then
          MYSQL_PWD="\$pw" mysql -u "\$dbu" -N -e \
            "SELECT ROUND(SUM(data_length+index_length)/1048576,1), COUNT(*) FROM information_schema.tables WHERE table_schema='\$db'" 2>/dev/null \
            | awk '{print "size_mb=" \$1; print "tables=" \$2}'
        fi
        SH;

        $output = $this->run($host, $script, 10);
        if ($output === null) {
            return null;
        }
        $info = [];
        foreach (explode("\n", $output) as $line) {
            if (str_contains($line, '=')) {
                [$key, $value] = explode('=', $line, 2);
                $info[trim($key)] = trim($value);
            }
        }

        return $info;
    }

    /** Supervisor programs + crontab + schedule:list for a site. */
    public function inventory(string $host, string $site, string $user = 'forge'): ?string
    {
        $script = <<<SH
        echo "## supervisor"
        grep -l "{$site}" /etc/supervisor/conf.d/*.conf 2>/dev/null | while read -r f; do
          echo "# \$f"; grep -E '^\[program|^command|^directory|^user|^numprocs|^autostart|^stopwaitsecs' "\$f"
        done
        echo; echo "## crontab"
        crontab -l 2>/dev/null
        echo; echo "## schedule:list"
        cd /home/{$user}/{$site} 2>/dev/null && timeout 15 php artisan schedule:list --no-interaction 2>/dev/null | head -40
        SH;

        return $this->run($host, $script, 20);
    }

    /** State of public/storage symlink on the target site. */
    public function storageLink(string $host, array $candidates, string $user = 'forge'): array
    {
        foreach ($candidates as $site) {
            $script = <<<SH
            d=/home/{$user}/{$site}
            [ -d "\$d" ] || exit 1
            [ -d "\${d}/current" ] && d="\${d}/current"
            echo "site={$site}"
            if [ -L "\$d/public/storage" ]; then
              echo "exists=1"; echo "target=\$(readlink "\$d/public/storage")"
              [ -e "\$d/public/storage" ] && echo "valid=1" || echo "valid=0"
            else
              echo "exists=0"
            fi
            SH;
            $output = $this->run($host, $script);
            if ($output !== null) {
                $result = ['ok' => true, 'site' => $site, 'exists' => false, 'valid' => false, 'target' => ''];
                foreach (explode("\n", $output) as $line) {
                    if (str_contains($line, '=')) {
                        [$key, $value] = explode('=', $line, 2);
                        match (trim($key)) {
                            'exists' => $result['exists'] = trim($value) === '1',
                            'valid' => $result['valid'] = trim($value) === '1',
                            'target' => $result['target'] = trim($value),
                            default => null,
                        };
                    }
                }

                return $result;
            }
        }

        return ['ok' => false];
    }

    /** du of site dir + archive listing in /tmp/relocate-<site>. */
    public function sizes(string $host, array $sites, string $user, string $archiveSite): ?string
    {
        $duCommands = collect($sites)->unique()->map(
            fn (string $site) => "du -sh /home/{$user}/{$site} /home/{$user}/{$site}/storage/app 2>/dev/null"
        )->implode('; ');

        return $this->run($host, "{$duCommands}; ls -lh /tmp/relocate-{$archiveSite} 2>/dev/null");
    }

    /** Public IP of the host (via ssh config HostName, falling back to hostname -I). */
    public function publicIp(string $host): ?string
    {
        $config = Process::env($this->env())->run([$this->ssh(), '-G', $host])->output();
        if (preg_match('/^hostname\s+(\S+)/mi', $config, $m)) {
            $resolved = gethostbyname($m[1]);
            if (filter_var($resolved, FILTER_VALIDATE_IP)
                && ! preg_match('/^(10\.|172\.(1[6-9]|2\d|3[01])\.|192\.168\.)/', $resolved)) {
                return $resolved;
            }
        }
        $remote = $this->run($host, 'hostname -I');
        foreach (preg_split('/\s+/', (string) $remote) as $ip) {
            if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)
                && ! preg_match('/^(10\.|172\.(1[6-9]|2\d|3[01])\.|192\.168\.)/', $ip)) {
                return $ip;
            }
        }

        return null;
    }

    /** Masked composer/npm credential inventory (never returns secrets). */
    public function packagesInfo(string $host, string $user = 'forge'): array
    {
        $composerRaw = $this->run($host, 'cat ~/.config/composer/auth.json 2>/dev/null');
        $npmRaw = $this->run($host, 'cat ~/.npmrc 2>/dev/null');

        $composer = [];
        $auth = json_decode((string) $composerRaw, true) ?: [];
        foreach ($auth as $section => $hosts) {
            foreach ((array) $hosts as $hostName => $cred) {
                $composer[] = [
                    'section' => $section,
                    'host' => $hostName,
                    'username' => is_array($cred) ? ($cred['username'] ?? '') : '',
                ];
            }
        }

        $npm = [];
        foreach (explode("\n", (string) $npmRaw) as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#') || ! str_contains($line, '=')) {
                continue;
            }
            [$key] = explode('=', $line, 2);
            $npm[] = match (true) {
                str_contains($key, ':_authToken') => ['kind' => 'auth token', 'registry' => trim($key)],
                str_starts_with($key, '@') => ['kind' => 'scoped registry', 'registry' => trim($key)],
                trim($key) === 'registry' => ['kind' => 'default registry', 'registry' => 'registry'],
                default => ['kind' => 'config', 'registry' => trim($key)],
            };
        }

        return [
            'ok' => $composerRaw !== null || $npmRaw !== null,
            'composer' => $composer,
            'npm' => $npm,
        ];
    }

    /**
     * Copy composer/npm credentials from one server to another (merge, source
     * wins). Secrets travel via stdin, never argv.
     */
    public function copyPackages(string $source, string $destination, bool $composer, bool $npm, string $user = 'forge'): array
    {
        $results = [];

        if ($composer) {
            $sourceAuth = json_decode((string) $this->run($source, 'cat ~/.config/composer/auth.json 2>/dev/null'), true);
            if (! is_array($sourceAuth)) {
                $results['composer'] = 'source has no composer auth.json';
            } else {
                $destAuth = json_decode((string) $this->run($destination, 'cat ~/.config/composer/auth.json 2>/dev/null'), true) ?: [];
                $merged = json_encode(array_replace_recursive($destAuth, $sourceAuth),
                    JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
                $results['composer'] = $this->writeRemoteFile($destination,
                    '~/.config/composer/auth.json', $merged, 'mkdir -p ~/.config/composer');
            }
        }

        if ($npm) {
            $sourceRc = $this->run($source, 'cat ~/.npmrc 2>/dev/null');
            if ($sourceRc === null || trim($sourceRc) === '') {
                $results['npm'] = 'source has no .npmrc';
            } else {
                $destRc = (string) $this->run($destination, 'cat ~/.npmrc 2>/dev/null');
                $lines = [];
                foreach ([...explode("\n", $destRc), ...explode("\n", $sourceRc)] as $line) {
                    if (trim($line) === '') {
                        continue;
                    }
                    $key = str_contains($line, '=') ? trim(explode('=', $line, 2)[0]) : $line;
                    $lines[$key] = $line; // source lines come last and win
                }
                $results['npm'] = $this->writeRemoteFile($destination, '~/.npmrc', implode("\n", $lines)."\n");
            }
        }

        return $results;
    }

    protected function writeRemoteFile(string $host, string $path, string $content, string $prepare = ''): string
    {
        $timestamp = now()->format('YmdHis');
        $prefix = $prepare ? "{$prepare}; " : '';
        $script = "{$prefix}[ -f {$path} ] && cp {$path} {$path}.bak.{$timestamp}; "
            ."cat > {$path}.tmp && chmod 600 {$path}.tmp && mv {$path}.tmp {$path} && echo COPIED";

        $result = Process::timeout(12)
            ->env($this->env())
            ->input($content)
            ->run([$this->ssh(), '-o', 'BatchMode=yes', '-o', 'ConnectTimeout=8', $host, $script]);

        return str_contains($result->output(), 'COPIED') ? 'copied' : 'failed: '.trim($result->errorOutput());
    }
}
