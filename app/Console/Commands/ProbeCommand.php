<?php

namespace App\Console\Commands;

use App\Models\Server;
use App\Services\FactsService;
use Illuminate\Console\Command;

class ProbeCommand extends Command
{
    protected $signature = 'forgemover:probe {server? : ssh host alias; omit for all servers}';

    protected $description = 'ssh-probe server facts (OS, PHP, DB, disk, uptime) and store them';

    public function handle(FactsService $facts): int
    {
        $servers = $this->argument('server')
            ? Server::where('name', $this->argument('server'))->get()
            : Server::all();

        if ($servers->isEmpty()) {
            $this->error('No matching servers. Run forgemover:sync first.');

            return self::FAILURE;
        }

        foreach ($servers as $server) {
            $ok = $facts->refresh($server);
            $server->refresh();
            $this->line(sprintf('%-15s %s', $server->name, $ok
                ? "{$server->os} · PHP {$server->php_default} · {$server->disk_used}/{$server->disk_total} ({$server->disk_pct}%)"
                : '<fg=red>unreachable via ssh</>'));
        }

        return self::SUCCESS;
    }
}
