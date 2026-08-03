<?php

namespace App\Filament\Resources\Servers\Schemas;

use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;

class ServerForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')
                    ->label('ssh host alias')
                    ->helperText('Must match a Host entry in ~/.ssh/config with passwordless key auth.')
                    ->required(),
                TextInput::make('slug')
                    ->label('Forge slug')
                    ->helperText('Synced from the Forge API — only override when it differs from the name.'),
                Toggle::make('compromised')
                    ->helperText('Flags the server everywhere; its secrets must be rotated, never copied.'),
                Toggle::make('destination_only')
                    ->helperText('Fresh server, only used as a relocation target.'),
            ]);
    }
}
