<?php

namespace App\Filament\Resources\Relocations\Pages;

use App\Filament\Resources\Relocations\RelocationResource;
use Filament\Resources\Pages\CreateRecord;

class CreateRelocation extends CreateRecord
{
    protected static string $resource = RelocationResource::class;

    protected function getRedirectUrl(): string
    {
        return RelocationResource::getUrl('view', ['record' => $this->getRecord()]);
    }
}
