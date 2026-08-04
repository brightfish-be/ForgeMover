<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Single source of truth for "is ForgeMover ready to run?" — shared by
 * `php artisan forgemover:setup` and the /setup web wizard.
 */
class SetupStatus
{
    /** @return list<array{key: string, label: string, ok: bool, required: bool, detail: string}> */
    public function checks(): array
    {
        $checks = [
            [
                'key' => 'php',
                'label' => 'PHP ≥ 8.2 with pdo_sqlite, curl, openssl',
                'ok' => PHP_VERSION_ID >= 80200
                    && extension_loaded('pdo_sqlite') && extension_loaded('curl') && extension_loaded('openssl'),
                'required' => true,
                'detail' => 'PHP '.PHP_VERSION,
            ],
            [
                'key' => 'env',
                'label' => '.env with APP_KEY',
                'ok' => is_file(app()->environmentFilePath()) && config('app.key') !== null,
                'required' => true,
                'detail' => 'run: php artisan key:generate',
            ],
            [
                'key' => 'database',
                'label' => 'Database migrated',
                'ok' => $this->databaseReady(),
                'required' => true,
                'detail' => 'run: php artisan migrate',
            ],
        ];

        foreach (config('forgemover.required_binaries') as $binary) {
            $checks[] = $this->binaryCheck($binary, required: true);
        }
        foreach (config('forgemover.optional_binaries') as $binary) {
            $checks[] = $this->binaryCheck($binary, required: false);
        }

        $checks[] = [
            'key' => 'ssh_config',
            'label' => '~/.ssh/config with server host aliases',
            'ok' => is_file($this->home().'/.ssh/config'),
            'required' => true,
            'detail' => 'ssh probes use BatchMode host aliases with passwordless keys',
        ];

        $checks[] = [
            'key' => 'forge_token',
            'label' => 'Forge API token configured',
            'ok' => ! empty(config('forgemover.forge.token')),
            'required' => true,
            'detail' => 'https://forge.laravel.com/profile/api',
        ];

        $checks[] = [
            'key' => 'admin',
            'label' => 'Admin user created',
            'ok' => $this->databaseReady() && User::count() > 0,
            'required' => true,
            'detail' => 'needed to log in to /admin',
        ];

        return $checks;
    }

    public function complete(): bool
    {
        return collect($this->checks())
            ->filter(fn (array $check) => $check['required'])
            ->every(fn (array $check) => $check['ok']);
    }

    /** Validate a Forge token live against the API; returns orgs or null. */
    public function validateForgeToken(string $token): ?array
    {
        try {
            return (new ForgeClient($token))->orgs();
        } catch (\Throwable) {
            return null;
        }
    }

    protected function databaseReady(): bool
    {
        try {
            DB::connection()->getPdo();

            return Schema::hasTable('settings') && Schema::hasTable('servers');
        } catch (\Throwable) {
            return false;
        }
    }

    protected function binaryCheck(string $binary, bool $required): array
    {
        $found = $this->findBinary($binary);

        return [
            'key' => "bin_{$binary}",
            'label' => "binary: {$binary}",
            'ok' => $found !== null,
            'required' => $required,
            'detail' => $found ?? 'not found in PATH',
        ];
    }

    /** Home dir even when the web server strips $HOME from the environment. */
    public function home(): string
    {
        return (string) (getenv('HOME')
            ?: ($_SERVER['HOME'] ?? null)
            ?: (function_exists('posix_getpwuid') ? (posix_getpwuid(posix_geteuid())['dir'] ?? '') : ''));
    }

    /**
     * PATH-independent lookup: the web server process often runs with a
     * stripped PATH, so also scan the usual locations.
     */
    public function findBinary(string $binary): ?string
    {
        $directories = array_unique(array_merge(
            explode(':', (string) getenv('PATH')),
            ['/usr/bin', '/bin', '/usr/sbin', '/sbin', '/usr/local/bin',
                '/opt/homebrew/bin', '/opt/homebrew/opt/mysql-client/bin', '/opt/local/bin'],
        ));
        foreach ($directories as $dir) {
            if ($dir !== '' && is_executable("{$dir}/{$binary}")) {
                return "{$dir}/{$binary}";
            }
        }

        return null;
    }
}
