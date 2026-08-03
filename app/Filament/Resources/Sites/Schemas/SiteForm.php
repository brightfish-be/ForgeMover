<?php

namespace App\Filament\Resources\Sites\Schemas;

use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

class SiteForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('server_id')
                    ->relationship('server', 'name')
                    ->required(),
                TextInput::make('name')
                    ->label('Domain')
                    ->required(),
                TextInput::make('badge_uuid')
                    ->label('Forge badge')
                    ->helperText('Badge UUID — paste the UUID, badge URL, shields.io URL or full markdown from Forge.')
                    ->dehydrateStateUsing(function (?string $state): ?string {
                        if (! $state) {
                            return null;
                        }
                        // Accept bare UUID, badge URL, shields.io URL or markdown.
                        if (preg_match('/site-badges%2F([0-9a-f-]{36})/i', urlencode(urldecode($state)), $m)
                            || preg_match('/site-badges\/([0-9a-f-]{36})/i', urldecode($state), $m)
                            || preg_match('/([0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12})/i', $state, $m)) {
                            return strtolower($m[1]);
                        }

                        return null;
                    }),
            ]);
    }
}
