<?php

namespace App\Filament\Resources\Servers\Schemas;

use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class ServerForm
{
    /** Forge-style settings rows: label + helper text left, control right (DESIGN.md §3.4). */
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->components([
                Section::make('Name')
                    ->description('The ssh host alias used to identify and reach this server. Must match a Host entry in ~/.ssh/config with passwordless key auth.')
                    ->aside()
                    ->schema([
                        TextInput::make('name')->hiddenLabel()->required(),
                    ]),
                Section::make('Forge slug')
                    ->description('Synced from the Forge API — only override when it differs from the name.')
                    ->aside()
                    ->schema([
                        TextInput::make('slug')->hiddenLabel(),
                    ]),
                Section::make('Compromised')
                    ->description('Flags the server everywhere; its secrets must be rotated, never copied.')
                    ->aside()
                    ->schema([
                        Toggle::make('compromised')->hiddenLabel(),
                    ]),
                Section::make('Destination only')
                    ->description('Fresh server, only used as a relocation target.')
                    ->aside()
                    ->schema([
                        Toggle::make('destination_only')->hiddenLabel(),
                    ]),
            ]);
    }
}
