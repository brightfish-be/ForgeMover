<?php

namespace App\Filament\Resources\Servers\Schemas;

use App\Models\Server;
use App\Services\FactsService;
use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class ServerInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Server')
                    ->columns(4)
                    ->schema([
                        TextEntry::make('name')->label('ssh alias'),
                        TextEntry::make('ip_address')->placeholder('—'),
                        TextEntry::make('region')->placeholder('—'),
                        TextEntry::make('size')->placeholder('—'),
                        IconEntry::make('compromised')->boolean()
                            ->trueIcon('fas-radiation')->trueColor('danger'),
                        IconEntry::make('reachable')->label('ssh reachable')->boolean(),
                        TextEntry::make('sites_synced_at')->since()->placeholder('never')
                            ->label('Forge sites synced'),
                        TextEntry::make('facts_fetched_at')->since()->placeholder('never')
                            ->label('Facts probed'),
                    ]),
                Section::make('Facts')
                    ->columns(4)
                    ->schema([
                        TextEntry::make('os')->placeholder('—'),
                        TextEntry::make('os_version')->label('Ubuntu')->badge()
                            ->color(fn (Server $record) => ($record->osEol()['eol'] ?? false) ? 'danger' : 'success')
                            ->tooltip(fn (Server $record) => ($eol = $record->osEol())
                                ? ($eol['eol'] ? "EOL since {$eol['date']}" : "EOL {$eol['date']}") : null),
                        TextEntry::make('php_default')->label('default PHP')->badge()->color('warning'),
                        TextEntry::make('php_versions')
                            ->label('installed PHP')
                            ->badge()
                            ->color(fn (string $state) => FactsService::phpIsEol($state) ? 'danger' : 'gray'),
                        TextEntry::make('db_version')->label('Database')->placeholder('—')
                            ->formatStateUsing(fn (Server $record) => $record->db_version
                                .($record->has_db ? '' : ' (stopped)')),
                        TextEntry::make('redis_version')->label('Redis')->placeholder('—')
                            ->formatStateUsing(fn (Server $record) => $record->redis_version
                                .($record->has_redis ? '' : ' (stopped)')),
                        TextEntry::make('cpus')
                            ->label('CPU · RAM')
                            ->formatStateUsing(fn (Server $record) => "{$record->cpus} CPU · {$record->ram_gb} GB"),
                        TextEntry::make('disk_pct')
                            ->label('Disk')
                            ->badge()
                            ->color(fn (Server $record) => match (true) {
                                $record->disk_pct >= 90 => 'danger',
                                $record->disk_pct >= 75 => 'warning',
                                default => 'success',
                            })
                            ->formatStateUsing(fn (Server $record) => "{$record->disk_used}/{$record->disk_total} ({$record->disk_pct}%) · up {$record->uptimePretty()}"),
                    ]),
            ]);
    }
}
