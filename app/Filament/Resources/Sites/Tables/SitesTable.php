<?php

namespace App\Filament\Resources\Sites\Tables;

use App\Filament\Resources\Relocations\RelocationResource;
use App\Models\Relocation;
use App\Models\Server;
use App\Models\Site;
use App\Services\FactsService;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\ViewField;
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
use Illuminate\Support\Carbon;
use Illuminate\Support\HtmlString;

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
                            ->getStateUsing(fn (Site $record) => self::subtitle($record))
                            ->html()
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
                        ->modalWidth(Width::TwoExtraLarge)
                        ->schema(function (Site $record) {
                            $servers = Server::withCount('sites')
                                ->where('id', '!=', $record->server_id)
                                ->orderBy('name')
                                ->get();

                            return [
                                ViewField::make('target_server_id')
                                    ->label('Destination server')
                                    ->view('filament.forms.server-cards')
                                    ->viewData(['servers' => $servers])
                                    ->in($servers->pluck('id')->all())
                                    ->required(),
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

    /** "owner/repo:branch · PHP x.y" — the PHP part is a red pill when that version reaches EOL within 2 years. */
    private static function subtitle(Site $record): ?HtmlString
    {
        $repo = $record->repo_url
            ? str($record->repo_url)
                ->replaceMatches('~^(https?://[^/]+/|\w+@[^:]+:)~', '')
                ->beforeLast('.git')
                .($record->repo_branch ? ":{$record->repo_branch}" : '')
            : null;

        $php = null;
        if ($record->php_version) {
            $version = preg_replace('/^PHP\s+/i', '', $record->php_version);
            $eol = FactsService::phpEolDate($version);
            $php = sprintf(
                '<span class="%s" title="%s">PHP %s</span>',
                FactsService::phpEolSoon($version) ? 'eol-pill' : '',
                e($eol ? 'PHP EOL '.Carbon::parse($eol)->format('M Y') : 'PHP version unknown'),
                e($version),
            );
        }

        $parts = array_filter([$repo !== null ? e($repo) : null, $php]);

        return $parts ? new HtmlString(implode(' · ', $parts)) : null;
    }
}
