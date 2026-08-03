<?php

namespace App\Filament\Resources\Sites\Tables;

use App\Filament\Resources\Relocations\RelocationResource;
use App\Models\Relocation;
use App\Models\Server;
use App\Models\Site;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class SitesTable
{
    public static function configure(Table $table, bool $showServer = true): Table
    {
        return $table
            ->defaultSort('name')
            ->columns(array_values(array_filter([
                TextColumn::make('name')
                    ->label('Site')
                    ->searchable()
                    ->sortable()
                    ->weight('bold')
                    ->icon('fas-globe')
                    ->iconColor('gray'),
                $showServer ? TextColumn::make('server.name')
                    ->label('Server')
                    ->sortable()
                    ->searchable() : null,
                TextColumn::make('app_type')
                    ->label('Type')
                    ->badge()
                    ->color(fn (?string $state) => match (true) {
                        str_starts_with((string) $state, 'laravel') => 'info',
                        $state === 'wordpress' => 'primary',
                        $state === 'php' => 'warning',
                        $state === 'static' => 'gray',
                        default => 'gray',
                    })
                    ->placeholder('not probed'),
                TextColumn::make('php_version')
                    ->label('PHP')
                    ->badge()
                    ->color('warning'),
                TextColumn::make('repo_url')
                    ->label('Repo')
                    ->formatStateUsing(fn (Site $record) => str($record->repo_url)
                        ->afterLast(':')->beforeLast('.git')->limit(40))
                    ->url(fn (Site $record) => $record->repoBrowseUrl(), shouldOpenInNewTab: true)
                    ->tooltip(fn (Site $record) => trim("{$record->repo_provider} · {$record->repo_branch}"))
                    ->placeholder('—'),
                IconColumn::make('isolated')
                    ->boolean()
                    ->trueIcon('fas-user-shield')
                    ->tooltip(fn (Site $record) => $record->isolated ? "isolated as {$record->site_user}" : 'not isolated'),
                IconColumn::make('zero_downtime')
                    ->label('ZDD')
                    ->boolean(),
                ImageColumn::make('badge_uuid')
                    ->label('Badge')
                    ->getStateUsing(fn (Site $record) => $record->badgeImgUrl())
                    ->height(20),
            ])))
            ->filters(array_values(array_filter([
                $showServer ? SelectFilter::make('server_id')
                    ->label('Server')
                    ->options(fn () => Server::orderBy('name')->pluck('name', 'id')) : null,
            ])))
            ->recordActions([
                Action::make('relocate')
                    ->label('Relocate')
                    ->icon('fas-truck-arrow-right')
                    ->schema(fn (Site $record) => [
                        Select::make('target_server_id')
                            ->label('Destination server')
                            ->options(fn () => Server::where('id', '!=', $record->server_id)
                                ->orderBy('name')->pluck('name', 'id'))
                            ->required(),
                        TextInput::make('unix_user')
                            ->default($record->site_user ?: config('forgemover.default_user'))
                            ->required(),
                    ])
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
            ])
            ->toolbarActions([]);
    }
}
