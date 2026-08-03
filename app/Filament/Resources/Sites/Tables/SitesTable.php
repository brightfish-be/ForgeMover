<?php

namespace App\Filament\Resources\Sites\Tables;

use App\Filament\Resources\Relocations\RelocationResource;
use App\Models\Relocation;
use App\Models\Server;
use App\Models\Site;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\TextInput;
use Filament\Support\Enums\FontWeight;
use Filament\Support\Enums\TextSize;
use Filament\Support\Enums\Width;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\Layout\Split;
use Filament\Tables\Columns\Layout\Stack;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ViewColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class SitesTable
{
    /** Forge-style header-less rows: tile · name + repo subtitle · badges · meta (DESIGN.md §3.3). */
    /** $compact drops the sort-by toolbar row — used when embedded next to the sidebar. */
    public static function configure(Table $table, bool $showServer = true, bool $compact = false): Table
    {
        return $table
            ->defaultSort('name')
            ->columns([
                Split::make(array_values(array_filter([
                    ViewColumn::make('tile')
                        ->view('filament.tables.columns.site-tile')
                        ->grow(false),
                    Stack::make([
                        TextColumn::make('name')
                            ->weight(FontWeight::SemiBold)
                            ->searchable()
                            ->sortable(! $compact),
                        TextColumn::make('subtitle')
                            ->getStateUsing(fn (Site $record) => collect([
                                $record->repo_url
                                    ? str($record->repo_url)
                                        ->replaceMatches('~^(https?://[^/]+/|\w+@[^:]+:)~', '')
                                        ->beforeLast('.git')
                                        .($record->repo_branch ? ":{$record->repo_branch}" : '')
                                    : null,
                                $record->php_version
                                    ? 'PHP '.preg_replace('/^PHP\s+/i', '', $record->php_version)
                                    : null,
                            ])->filter()->implode(' · ') ?: null)
                            ->placeholder('not probed')
                            ->size(TextSize::Small)
                            ->color('gray')
                            ->url(fn (Site $record) => $record->repoBrowseUrl(), shouldOpenInNewTab: true)
                            ->tooltip(fn (Site $record) => $record->repo_provider ?: null),
                    ])->space(1),
                    TextColumn::make('app_type')
                        ->badge()
                        ->color(fn (?string $state) => match (true) {
                            str_starts_with((string) $state, 'laravel') => 'success',
                            $state === 'wordpress' => 'info',
                            $state === 'php' => 'warning',
                            default => 'gray',
                        })
                        ->grow(false),
                    IconColumn::make('isolated')
                        ->getStateUsing(fn (Site $record) => $record->isolated ?: null)
                        ->icon('fas-user-shield')
                        ->color('gray')
                        ->tooltip(fn (Site $record) => $record->isolated ? "isolated as {$record->site_user}" : null)
                        ->grow(false),
                    IconColumn::make('zero_downtime')
                        ->getStateUsing(fn (Site $record) => $record->zero_downtime ?: null)
                        ->icon('fas-bolt')
                        ->color('gray')
                        ->tooltip(fn (Site $record) => $record->zero_downtime ? 'zero-downtime deploys' : null)
                        ->grow(false),
                    $showServer ? TextColumn::make('server.name')
                        ->size(TextSize::Small)
                        ->color('gray')
                        ->icon('fas-server')
                        ->sortable()
                        ->searchable()
                        ->alignEnd() : null,
                    ImageColumn::make('badge_uuid')
                        ->getStateUsing(fn (Site $record) => $record->badgeImgUrl())
                        ->height(20)
                        ->grow(false),
                ]))),
            ])
            ->filters(array_values(array_filter([
                $showServer ? SelectFilter::make('server_id')
                    ->label('Server')
                    ->options(fn () => Server::orderBy('name')->pluck('name', 'id')) : null,
            ])))
            ->recordActions([
                ActionGroup::make([
                    Action::make('relocate')
                        ->label('Relocate')
                        ->icon('fas-truck-arrow-right')
                        ->modalWidth(Width::ExtraLarge)
                        ->schema(function (Site $record) {
                            $servers = Server::withCount('sites')
                                ->where('id', '!=', $record->server_id)
                                ->orderBy('name')
                                ->get();

                            return [
                                Radio::make('target_server_id')
                                    ->label('Destination server')
                                    ->options($servers->mapWithKeys(fn (Server $server) => [
                                        $server->id => $server->name.($server->compromised ? ' ☢ COMPROMISED' : ''),
                                    ]))
                                    ->descriptions($servers->mapWithKeys(fn (Server $server) => [
                                        $server->id => collect([
                                            $server->os_version ? "Ubuntu {$server->os_version}" : null,
                                            $server->php_default ? "PHP {$server->php_default}" : null,
                                            "{$server->sites_count} ".str('site')->plural($server->sites_count),
                                            $server->disk_pct ? "disk {$server->disk_pct}%" : null,
                                            $server->destination_only ? 'destination-only' : null,
                                        ])->filter()->implode(' · '),
                                    ])->all())
                                    ->required()
                                    ->extraAttributes(['class' => 'server-cards']),
                                TextInput::make('unix_user')
                                    ->default($record->site_user ?: config('forgemover.default_user'))
                                    ->required(),
                            ];
                        })
                        ->action(function (Site $record, array $data) {
                            $relocation = Relocation::firstOrCreate([
                                'source_server_id' => $record->server_id,
                                'site_name' => $record->name,
                                'target_server_id' => (int) $data['target_server_id'],
                            ], [
                                'unix_user' => $data['unix_user'],
                            ]);

                            return redirect(RelocationResource::getUrl('view', ['record' => $relocation]));
                        }),
                    Action::make('forge')
                        ->label('Forge')
                        ->icon('fas-fire')
                        ->url(fn (Site $record) => $record->forgeUrl(), shouldOpenInNewTab: true)
                        ->visible(fn (Site $record) => $record->forgeUrl() !== null),
                    EditAction::make(),
                ]),
            ])
            ->toolbarActions([]);
    }
}
