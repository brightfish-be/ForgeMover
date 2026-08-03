<?php

namespace App\Filament\Resources\Relocations\Schemas;

use App\Models\Server;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

class RelocationForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('source_server_id')
                    ->label('Source server')
                    ->options(fn () => Server::orderBy('name')->pluck('name', 'id'))
                    ->live()
                    ->required(),
                Select::make('site_name')
                    ->label('Site')
                    ->options(fn (callable $get) => Server::find($get('source_server_id'))
                        ?->sites()->orderBy('name')->pluck('name', 'name') ?? collect())
                    ->searchable()
                    // a site missing from Forge can still be typed in
                    ->createOptionUsing(fn (array $data) => $data['name'] ?? null)
                    ->required(),
                Select::make('target_server_id')
                    ->label('Destination server')
                    ->options(fn (callable $get) => Server::where('id', '!=', $get('source_server_id'))
                        ->orderBy('name')->pluck('name', 'id'))
                    ->required()
                    ->different('source_server_id'),
                TextInput::make('unix_user')
                    ->label('Unix user')
                    ->default('forge')
                    ->helperText('Owner of /home/<user>/<site> on both servers.')
                    ->required(),
            ]);
    }
}
