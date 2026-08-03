<?php

namespace App\Filament\Resources\Relocations\Tables;

use App\Enums\RunState;
use App\Models\Relocation;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

class RelocationsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with(['sourceServer', 'targetServer', 'runs', 'checks']))
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('site_name')
                    ->label('Site')
                    ->weight('bold')
                    ->searchable(),
                TextColumn::make('sourceServer.name')
                    ->label('From → to')
                    ->formatStateUsing(fn (Relocation $record) => "{$record->sourceServer->name} → {$record->targetServer->name}"),
                TextColumn::make('steps')
                    ->label('C T R V')
                    ->badge()
                    ->getStateUsing(fn (Relocation $record) => collect($record->scriptStates())
                        ->map(fn (RunState $state, string $step) => strtoupper($step[0]).':'.$state->value)
                        ->values()->all())
                    ->formatStateUsing(fn (string $state) => explode(':', $state)[0])
                    ->color(fn (string $state) => match (explode(':', $state)[1]) {
                        'success' => 'success',
                        'running' => 'warning',
                        'failed' => 'danger',
                        default => 'gray',
                    }),
                TextColumn::make('temp_domain')
                    ->placeholder('—')
                    ->toggleable(),
                TextColumn::make('created_at')
                    ->label('Started')
                    ->since()
                    ->sortable(),
                TextColumn::make('finished_at')
                    ->label('Cutover')
                    ->since()
                    ->placeholder('—')
                    ->sortable(),
            ])
            ->filters([
                TernaryFilter::make('finished_at')
                    ->label('Finished')
                    ->nullable(),
            ])
            ->recordActions([
                ViewAction::make()->label('Wizard')->icon('fas-wand-magic-sparkles'),
            ])
            ->toolbarActions([]);
    }
}
