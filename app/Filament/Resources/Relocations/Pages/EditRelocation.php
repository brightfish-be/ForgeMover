<?php

namespace App\Filament\Resources\Relocations\Pages;

use App\Filament\Resources\Relocations\RelocationResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditRelocation extends EditRecord
{
    protected static string $resource = RelocationResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
