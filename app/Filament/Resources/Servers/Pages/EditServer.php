<?php

namespace App\Filament\Resources\Servers\Pages;

use App\Filament\Resources\Servers\ServerResource;
use Filament\Actions\DeleteAction;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;

class EditServer extends EditRecord
{
    protected static string $resource = ServerResource::class;

    protected static ?string $title = 'Settings';

    protected function getHeaderActions(): array
    {
        return [
            ViewAction::make()->color('gray'),
            DeleteAction::make(),
        ];
    }
}
