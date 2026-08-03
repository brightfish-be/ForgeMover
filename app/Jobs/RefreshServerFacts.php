<?php

namespace App\Jobs;

use App\Models\Server;
use App\Services\FactsService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class RefreshServerFacts implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $timeout = 60;

    public function __construct(public Server $server) {}

    public function uniqueId(): string
    {
        return (string) $this->server->id;
    }

    public function handle(FactsService $facts): void
    {
        $facts->refresh($this->server);
    }
}
