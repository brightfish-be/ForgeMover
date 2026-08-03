<?php

namespace App\Services;

use App\Models\Server;
use App\Models\Site;

class ForgeSync
{
    public function __construct(protected ForgeClient $forge) {}

    /** Upsert all Forge servers; returns number synced. */
    public function syncServers(): int
    {
        $count = 0;
        foreach ($this->forge->servers() as $data) {
            if ($data['name'] === '') {
                continue;
            }
            Server::updateOrCreate(['name' => $data['name']], $data);
            $count++;
        }

        return $count;
    }

    /** Upsert one server's site list; returns number synced. */
    public function syncSites(Server $server): int
    {
        if (! $server->forge_id) {
            return 0;
        }
        $sites = $this->forge->sites($server->forge_id);
        $seen = [];
        foreach ($sites as $data) {
            if ($data['name'] === '') {
                continue;
            }
            $seen[] = $data['name'];
            $server->sites()->updateOrCreate(['name' => $data['name']], $data);
        }
        // Sites deleted in Forge disappear here too — but keep manual columns safe:
        // only remove rows that came from Forge (they always have a forge_site_id).
        $server->sites()->whereNotIn('name', $seen)->where('forge_site_id', '!=', '')->delete();
        $server->update(['sites_synced_at' => now()]);

        return count($seen);
    }

    public function syncAll(): array
    {
        $servers = $this->syncServers();
        $sites = 0;
        foreach (Server::all() as $server) {
            $sites += $this->syncSites($server);
        }

        return ['servers' => $servers, 'sites' => $sites];
    }
}
