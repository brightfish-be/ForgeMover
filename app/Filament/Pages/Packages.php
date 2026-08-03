<?php

namespace App\Filament\Pages;

use App\Models\Server;
use App\Services\RemoteProbe;
use BackedEnum;
use Filament\Notifications\Notification;
use Filament\Pages\Page;

/**
 * Copy composer/npm package-manager credentials from one server to another
 * (merge, source wins). Secrets are never displayed — only masked summaries.
 */
class Packages extends Page
{
    protected string $view = 'filament.pages.packages';

    protected static string|BackedEnum|null $navigationIcon = 'fas-box-open';

    protected static ?int $navigationSort = 4;

    public ?int $from = null;

    public ?int $to = null;

    public bool $copyComposer = true;

    public bool $copyNpm = true;

    public array $result = [];

    public function getServersProperty()
    {
        return Server::orderBy('name')->get();
    }

    public function getSourceInfoProperty(): ?array
    {
        if (! $this->from) {
            return null;
        }
        $server = Server::find($this->from);

        return $server ? app(RemoteProbe::class)->packagesInfo($server->name) : null;
    }

    public function getDestinationInfoProperty(): ?array
    {
        if (! $this->to) {
            return null;
        }
        $server = Server::find($this->to);

        return $server ? app(RemoteProbe::class)->packagesInfo($server->name) : null;
    }

    public function copy(): void
    {
        $source = Server::find($this->from);
        $destination = Server::find($this->to);

        if (! $source || ! $destination || $source->is($destination)) {
            Notification::make()->title('Pick two different servers')->danger()->send();

            return;
        }
        if ($source->compromised) {
            Notification::make()
                ->title("{$source->name} is flagged COMPROMISED")
                ->body('Rotate these credentials instead of copying them.')
                ->danger()->send();

            return;
        }

        $this->result = app(RemoteProbe::class)->copyPackages(
            $source->name, $destination->name, $this->copyComposer, $this->copyNpm,
        );

        Notification::make()->title('Copy finished')->body(collect($this->result)
            ->map(fn ($status, $kind) => "{$kind}: {$status}")->implode(' · '))->success()->send();
    }
}
