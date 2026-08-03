<?php

namespace App\Filament\Widgets;

use App\Models\Relocation;
use App\Models\Server;
use App\Models\Site;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class FleetStats extends StatsOverviewWidget
{
    protected function getStats(): array
    {
        $eolServers = Server::all()->filter(fn (Server $server) => $server->osEol()['eol'] ?? false);
        $compromised = Server::where('compromised', true)->count();
        $inProgress = Relocation::whereNull('finished_at')->count();

        return [
            Stat::make('Servers', Server::count())
                ->description($compromised
                    ? "{$compromised} compromised"
                    : (Server::where('reachable', false)->count().' unreachable'))
                ->descriptionIcon($compromised ? 'fas-radiation' : 'fas-plug')
                ->color($compromised ? 'danger' : 'success'),
            Stat::make('Sites', Site::count())
                ->description(Site::where('app_type', 'like', 'laravel%')->count().' Laravel')
                ->descriptionIcon('fas-globe'),
            Stat::make('Ubuntu EOL', $eolServers->count())
                ->description($eolServers->pluck('name')->implode(', ') ?: 'all supported')
                ->descriptionIcon('fas-triangle-exclamation')
                ->color($eolServers->isEmpty() ? 'success' : 'danger'),
            Stat::make('Relocations in progress', $inProgress)
                ->description(Relocation::whereNotNull('finished_at')->count().' finished')
                ->descriptionIcon('fas-truck-arrow-right')
                ->color($inProgress ? 'warning' : 'gray'),
        ];
    }
}
