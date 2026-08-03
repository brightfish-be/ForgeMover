<?php

namespace App\Jobs;

use App\Models\Server;
use App\Services\ForgeClient;
use App\Services\ForgeSync;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/** Pulls one server's site list from the Forge API. */
class SyncServerSites implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $timeout = 120;

    public function __construct(public Server $server) {}

    public function uniqueId(): string
    {
        return (string) $this->server->id;
    }

    public function handle(ForgeClient $forge, ForgeSync $sync): void
    {
        if (! $forge->configured()) {
            return;
        }
        $sync->syncSites($this->server);
    }
}
