<?php

namespace App\Console\Commands;

use App\Jobs\ProbeSiteTypes;
use App\Jobs\RefreshServerFacts;
use App\Models\Server;
use App\Models\Setting;
use App\Models\User;
use App\Services\EnvWriter;
use App\Services\ForgeSync;
use App\Services\SetupStatus;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\password;
use function Laravel\Prompts\select;
use function Laravel\Prompts\text;

/**
 * Fool-proof interactive setup: checks binaries, generates the app key,
 * migrates the database, configures & validates the Forge API token,
 * creates the admin user and runs the first sync.
 */
class SetupCommand extends Command
{
    protected $signature = 'forgemover:setup
        {--check : only report the setup status, change nothing}';

    protected $description = 'Interactive first-run setup for ForgeMover';

    public function handle(SetupStatus $status, EnvWriter $env): int
    {
        $this->components->info('ForgeMover setup');

        // 1. requirements table
        $failedRequired = $this->printChecks($status);
        if ($this->option('check')) {
            return $status->complete() ? self::SUCCESS : self::FAILURE;
        }

        // 2. app key + migrations (safe to run repeatedly)
        if (! config('app.key')) {
            Artisan::call('key:generate', ['--force' => true], $this->output);
            $this->components->task('Generated APP_KEY');
        }
        Artisan::call('migrate', ['--force' => true], $this->output);

        // 3. Forge API token (validated live before saving)
        if (! $this->configureForgeToken($status, $env)) {
            return self::FAILURE;
        }

        // 4. admin user
        $this->createAdminUser();

        // 5. first sync + probes
        if (confirm('Run the first Forge sync now (pulls all servers & sites)?', default: true)) {
            try {
                $result = app(ForgeSync::class)->syncAll();
                $this->components->task("Synced {$result['servers']} servers, {$result['sites']} sites");
                if (confirm('Queue ssh probes for all servers (facts + site types)?', default: true)) {
                    Server::all()->each(function (Server $server) {
                        RefreshServerFacts::dispatch($server);
                        ProbeSiteTypes::dispatch($server);
                    });
                    $this->components->task('Probes queued — they run once the queue worker starts');
                }
            } catch (\Throwable $e) {
                $this->components->error('Sync failed: '.$e->getMessage());
            }
        }

        // 6. wrap-up
        $this->newLine();
        $this->printChecks(app(SetupStatus::class));
        $this->components->info('Next steps:');
        $this->line('  gloncher gloncher.ini     # web + queue worker + scheduler in one terminal');
        $this->line('  …or run them separately:');
        $this->line('  php artisan serve --port=8600');
        $this->line('  php artisan queue:work');
        $this->line('  php artisan schedule:work');
        $this->line('  then open http://127.0.0.1:8600/admin');

        return self::SUCCESS;
    }

    protected function printChecks(SetupStatus $status): int
    {
        $failed = 0;
        foreach ($status->checks() as $check) {
            $icon = $check['ok'] ? '<fg=green>✓</>' : ($check['required'] ? '<fg=red>✗</>' : '<fg=yellow>○</>');
            $this->line(sprintf('  %s %-45s <fg=gray>%s</>', $icon, $check['label'], $check['ok'] ? '' : $check['detail']));
            if (! $check['ok'] && $check['required']) {
                $failed++;
            }
        }
        $this->newLine();

        return $failed;
    }

    protected function configureForgeToken(SetupStatus $status, EnvWriter $env): bool
    {
        $token = config('forgemover.forge.token');
        if ($token && $status->validateForgeToken($token) !== null) {
            $this->components->task('Forge API token is valid');
        } else {
            if ($token) {
                $this->components->warn('The configured FORGE_API_TOKEN is invalid.');
            }
            do {
                $token = password(
                    label: 'Forge API token',
                    hint: 'Create one at https://forge.laravel.com/profile/api — leave empty to abort',
                );
                if ($token === '') {
                    $this->components->error('A Forge API token is required.');

                    return false;
                }
                $orgs = $status->validateForgeToken($token);
                if ($orgs === null) {
                    $this->components->error('That token was rejected by the Forge API — try again.');
                }
            } while ($orgs === null);
            $env->set(['FORGE_API_TOKEN' => $token]);
            $this->components->task('Token validated and saved to .env');
        }

        // org slug
        $orgs ??= $status->validateForgeToken($token) ?? [];
        $current = Setting::get('forge_org') ?? config('forgemover.forge.org');
        if (! $current && $orgs !== []) {
            $slug = count($orgs) === 1
                ? $orgs[0]['slug']
                : select('Forge organization', collect($orgs)->pluck('name', 'slug')->all());
            Setting::set('forge_org', $slug);
            $env->set(['FORGE_ORG' => $slug]);
            $this->components->task("Organization: {$slug}");
        }

        return true;
    }

    protected function createAdminUser(): void
    {
        if (User::count() > 0) {
            $this->components->task('Admin user exists');

            return;
        }
        $this->components->info('Create the admin user for /admin');
        User::create([
            'name' => text('Name', default: 'Admin', required: true),
            'email' => text('Email', required: true, validate: fn (string $value) => filter_var($value, FILTER_VALIDATE_EMAIL) ? null : 'invalid email'),
            'password' => Hash::make(password('Password', required: true, validate: fn (string $value) => strlen($value) >= 8 ? null : 'minimum 8 characters')),
        ]);
        $this->components->task('Admin user created');
    }
}
