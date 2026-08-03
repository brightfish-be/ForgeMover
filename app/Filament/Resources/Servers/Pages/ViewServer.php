<?php

namespace App\Filament\Resources\Servers\Pages;

use App\Filament\Resources\Servers\ServerResource;
use App\Models\Server;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Contracts\View\View;

class ViewServer extends ViewRecord
{
    protected static string $resource = ServerResource::class;

    protected static ?string $title = 'Overview';

    public function getHeader(): ?View
    {
        return view('filament.servers.header', ['server' => $this->getRecord()]);
    }

    /** The sites relation manager is embedded in the infolist's main column instead. */
    public function getRelationManagers(): array
    {
        return [];
    }

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make()->color('gray'),
            Action::make('forge')
                ->label('Open in Forge')
                ->icon('fas-fire')
                ->url(fn (Server $record) => $record->forgeUrl(), shouldOpenInNewTab: true)
                ->visible(fn (Server $record) => $record->forgeUrl() !== null),
        ];
    }
}
