<?php

namespace App\Filament\Resources\Relocations\Pages;

use App\Filament\Resources\Relocations\RelocationResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListRelocations extends ListRecords
{
    protected static string $resource = RelocationResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
