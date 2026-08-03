<?php

use App\Jobs\ProbeSiteTypes;
use App\Jobs\RefreshServerFacts;
use App\Jobs\SyncForgeData;
use App\Models\Server;
use Illuminate\Support\Facades\Schedule;

// Pull servers + site lists from the Forge API.
Schedule::job(new SyncForgeData)->everySixHours()->name('forge-sync');

// ssh-probe facts (OS, PHP, DB, disk, uptime) for every server.
Schedule::call(function () {
    Server::all()->each(fn (Server $server) => RefreshServerFacts::dispatch($server));
})->hourly()->name('refresh-server-facts');

// Detect what actually runs in each site directory.
Schedule::call(function () {
    Server::all()->each(fn (Server $server) => ProbeSiteTypes::dispatch($server));
})->daily()->name('probe-site-types');

// Queue hygiene: prune failed/finished jobs.
Schedule::command('queue:prune-failed')->daily();
