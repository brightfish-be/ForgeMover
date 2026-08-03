<?php

namespace App\Console\Commands;

use App\Services\ForgeClient;
use App\Services\ForgeSync;
use Illuminate\Console\Command;

class ForgeSyncCommand extends Command
{
    protected $signature = 'forgemover:sync';

    protected $description = 'Pull all servers and site lists from the Forge API (synchronously)';

    public function handle(ForgeClient $forge, ForgeSync $sync): int
    {
        if (! $forge->configured()) {
            $this->error('FORGE_API_TOKEN is not configured — run: php artisan forgemover:setup');

            return self::FAILURE;
        }
        $result = $sync->syncAll();
        $this->info("Synced {$result['servers']} servers and {$result['sites']} sites.");

        return self::SUCCESS;
    }
}
