<?php

namespace App\Filament\Resources\Relocations;

use App\Filament\Resources\Relocations\Pages\CreateRelocation;
use App\Filament\Resources\Relocations\Pages\EditRelocation;
use App\Filament\Resources\Relocations\Pages\ListRelocations;
use App\Filament\Resources\Relocations\Pages\RelocationWizard;
use App\Filament\Resources\Relocations\Schemas\RelocationForm;
use App\Filament\Resources\Relocations\Tables\RelocationsTable;
use App\Models\Relocation;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Table;

class RelocationResource extends Resource
{
    protected static ?string $model = Relocation::class;

    protected static string|BackedEnum|null $navigationIcon = 'fas-truck-arrow-right';

    protected static ?int $navigationSort = 3;

    public static function form(Schema $schema): Schema
    {
        return RelocationForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return RelocationsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListRelocations::route('/'),
            'create' => CreateRelocation::route('/create'),
            'view' => RelocationWizard::route('/{record}'),
            'edit' => EditRelocation::route('/{record}/edit'),
        ];
    }
}
