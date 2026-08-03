<?php

namespace App\Jobs;

use App\Services\ForgeClient;
use App\Services\ForgeSync;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/** Pulls all servers + their site lists from the Forge API. */
class SyncForgeData implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $timeout = 300;

    public function handle(ForgeClient $forge, ForgeSync $sync): void
    {
        if (! $forge->configured()) {
            return;
        }
        $sync->syncAll();
    }
}
