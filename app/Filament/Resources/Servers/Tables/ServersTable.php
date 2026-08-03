<?php

namespace App\Filament\Resources\Servers\Tables;

use App\Jobs\ProbeSiteTypes;
use App\Jobs\RefreshServerFacts;
use App\Jobs\SyncServerSites;
use App\Models\Server;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Notifications\Notification;
use Filament\Support\Enums\FontWeight;
use Filament\Support\Enums\TextSize;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\Layout\Split;
use Filament\Tables\Columns\Layout\Stack;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ViewColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

class ServersTable
{
    /** Forge-style header-less rows: tile · name + specs subtitle · health badges · meta (DESIGN.md §3.3). */
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('name')
            ->columns([
                Split::make([
                    ViewColumn::make('tile')
                        ->view('filament.tables.columns.server-tile')
                        ->grow(false),
                    Stack::make([
                        TextColumn::make('name')
                            ->weight(FontWeight::SemiBold)
                            ->searchable()
                            ->sortable()
                            ->formatStateUsing(fn (Server $record) => $record->name
                                .($record->compromised ? ' · COMPROMISED' : ''))
                            ->color(fn (Server $record) => $record->compromised ? 'danger' : null),
                        TextColumn::make('ip_address')
                            ->getStateUsing(fn (Server $record) => collect([
                                $record->ip_address,
                                $record->region,
                                $record->cpus ? "{$record->cpus} CPU · {$record->ram_gb} GB" : null,
                                $record->uptime_s ? 'up '.$record->uptimePretty() : null,
                            ])->filter()->implode(' · ') ?: null)
                            ->placeholder('not probed')
                            ->size(TextSize::Small)
                            ->color('gray'),
                    ])->space(1),
                    TextColumn::make('os_version')
                        ->badge()
                        ->prefix('Ubuntu ')
                        ->sortable()
                        ->color(fn (Server $record) => match (true) {
                            ($record->osEol()['eol'] ?? false) => 'danger',
                            ($record->osEol()['date'] ?? '9999') < now()->addYear()->toDateString() => 'warning',
                            default => 'success',
                        })
                        ->tooltip(fn (Server $record) => ($eol = $record->osEol())
                            ? ($eol['eol'] ? "EOL since {$eol['date']}" : "EOL {$eol['date']}") : null)
                        ->grow(false),
                    TextColumn::make('php_default')
                        ->badge()
                        ->prefix('PHP ')
                        ->color('warning')
                        ->grow(false),
                    TextColumn::make('db_version')
                        ->badge()
                        ->formatStateUsing(fn (Server $record) => $record->db_version
                            .($record->db_version && ! $record->has_db ? ' (stopped)' : ''))
                        ->color(fn (Server $record) => $record->db_version && ! $record->has_db ? 'danger' : 'gray')
                        ->grow(false),
                    TextColumn::make('disk_pct')
                        ->badge()
                        ->formatStateUsing(fn (Server $record) => $record->disk_total
                            ? "disk {$record->disk_used}/{$record->disk_total} ({$record->disk_pct}%)" : '')
                        ->color(fn (Server $record) => match (true) {
                            $record->disk_pct >= 90 => 'danger',
                            $record->disk_pct >= 75 => 'warning',
                            default => 'gray',
                        })
                        ->sortable()
                        ->grow(false),
                    TextColumn::make('sites_count')
                        ->counts('sites')
                        ->formatStateUsing(fn (?int $state) => ($state ?? 0).' '.str('site')->plural($state ?? 0))
                        ->size(TextSize::Small)
                        ->color('gray')
                        ->sortable()
                        ->alignEnd(),
                    IconColumn::make('reachable')
                        ->icon(fn (bool $state) => $state ? 'fas-plug-circle-check' : 'fas-plug-circle-xmark')
                        ->color(fn (bool $state) => $state ? 'success' : 'danger')
                        ->tooltip(fn (Server $record) => $record->facts_fetched_at
                            ? 'probed '.$record->facts_fetched_at->diffForHumans() : 'never probed')
                        ->grow(false),
                ]),
            ])
            ->filters([
                TernaryFilter::make('compromised'),
                TernaryFilter::make('reachable'),
            ])
            ->recordActions([
                ActionGroup::make([
                    Action::make('refresh')
                        ->label('Probe')
                        ->icon('fas-rotate')
                        ->action(function (Server $record) {
                            RefreshServerFacts::dispatch($record);
                            SyncServerSites::dispatch($record);
                            ProbeSiteTypes::dispatch($record);
                            Notification::make()->title("Probing {$record->name}…")
                                ->body('Facts refresh and Forge site sync queued; reload in a few seconds.')->info()->send();
                        }),
                    Action::make('forge')
                        ->label('Forge')
                        ->icon('fas-fire')
                        ->url(fn (Server $record) => $record->forgeUrl(), shouldOpenInNewTab: true)
                        ->visible(fn (Server $record) => $record->forgeUrl() !== null),
                    ViewAction::make(),
                    EditAction::make(),
                ]),
            ])
            ->toolbarActions([]);
    }
}
