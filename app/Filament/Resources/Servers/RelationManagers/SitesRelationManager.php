<?php

namespace App\Filament\Resources\Servers\RelationManagers;

use App\Filament\Resources\Sites\Tables\SitesTable;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Table;

class SitesRelationManager extends RelationManager
{
    protected static string $relationship = 'sites';

    public function table(Table $table): Table
    {
        return SitesTable::configure($table, showServer: false);
    }
}
