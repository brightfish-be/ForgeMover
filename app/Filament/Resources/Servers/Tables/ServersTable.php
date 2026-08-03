<?php

namespace App\Filament\Resources\Servers\Tables;

use App\Jobs\ProbeSiteTypes;
use App\Jobs\RefreshServerFacts;
use App\Models\Server;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

class ServersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('os_version')
            ->columns([
                TextColumn::make('name')
                    ->searchable()
                    ->sortable()
                    ->weight('bold')
                    ->icon(fn (Server $record) => $record->compromised ? 'fas-radiation' : 'fas-server')
                    ->iconColor(fn (Server $record) => $record->compromised ? 'danger' : 'gray')
                    ->description(fn (Server $record) => $record->compromised ? 'COMPROMISED' : null),
                TextColumn::make('os_version')
                    ->label('Ubuntu')
                    ->sortable()
                    ->badge()
                    ->color(fn (Server $record) => match (true) {
                        ($record->osEol()['eol'] ?? false) => 'danger',
                        ($record->osEol()['date'] ?? '9999') < now()->addYear()->toDateString() => 'warning',
                        default => 'success',
                    })
                    ->tooltip(fn (Server $record) => ($eol = $record->osEol())
                        ? ($eol['eol'] ? "EOL since {$eol['date']}" : "EOL {$eol['date']}") : null),
                TextColumn::make('php_default')
                    ->label('PHP')
                    ->badge()
                    ->color('warning'),
                TextColumn::make('db_version')
                    ->label('Database')
                    ->formatStateUsing(fn (Server $record) => $record->db_version
                        .($record->db_version && ! $record->has_db ? ' (stopped)' : ''))
                    ->color(fn (Server $record) => $record->db_version && ! $record->has_db ? 'danger' : null)
                    ->toggleable(),
                TextColumn::make('redis_version')
                    ->label('Redis')
                    ->formatStateUsing(fn (Server $record) => $record->redis_version
                        .($record->redis_version && ! $record->has_redis ? ' (stopped)' : ''))
                    ->color(fn (Server $record) => $record->redis_version && ! $record->has_redis ? 'danger' : null)
                    ->toggleable(),
                TextColumn::make('cpus')
                    ->label('CPU · RAM')
                    ->formatStateUsing(fn (Server $record) => "{$record->cpus} CPU · {$record->ram_gb} GB"),
                TextColumn::make('disk_pct')
                    ->label('Disk')
                    ->formatStateUsing(fn (Server $record) => $record->disk_total
                        ? "{$record->disk_used}/{$record->disk_total} ({$record->disk_pct}%)" : '')
                    ->badge()
                    ->color(fn (Server $record) => match (true) {
                        $record->disk_pct >= 90 => 'danger',
                        $record->disk_pct >= 75 => 'warning',
                        default => 'gray',
                    })
                    ->sortable(),
                TextColumn::make('uptime_s')
                    ->label('Uptime')
                    ->formatStateUsing(fn (Server $record) => $record->uptimePretty())
                    ->sortable()
                    ->toggleable(),
                TextColumn::make('sites_count')
                    ->label('Sites')
                    ->counts('sites')
                    ->sortable(),
                IconColumn::make('reachable')
                    ->label('ssh')
                    ->boolean()
                    ->trueIcon('fas-plug-circle-check')
                    ->falseIcon('fas-plug-circle-xmark'),
                TextColumn::make('facts_fetched_at')
                    ->label('Probed')
                    ->since()
                    ->sortable()
                    ->toggleable(),
            ])
            ->filters([
                TernaryFilter::make('compromised'),
                TernaryFilter::make('reachable'),
            ])
            ->recordActions([
                Action::make('refresh')
                    ->label('Probe')
                    ->icon('fas-rotate')
                    ->action(function (Server $record) {
                        RefreshServerFacts::dispatch($record);
                        ProbeSiteTypes::dispatch($record);
                        Notification::make()->title("Probing {$record->name}…")
                            ->body('Facts refresh queued; reload in a few seconds.')->info()->send();
                    }),
                Action::make('forge')
                    ->label('Forge')
                    ->icon('fas-fire')
                    ->url(fn (Server $record) => $record->forgeUrl(), shouldOpenInNewTab: true)
                    ->visible(fn (Server $record) => $record->forgeUrl() !== null),
                ViewAction::make(),
                EditAction::make(),
            ])
            ->toolbarActions([]);
    }
}
