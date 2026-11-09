<?php

namespace App\Filament\Resources\Relocations\Pages;

use App\Enums\RelocationStep;
use App\Enums\RunState;
use App\Filament\Resources\Relocations\RelocationResource;
use App\Models\Server;
use App\Models\Setting;
use App\Services\DnsService;
use App\Services\RelocationRunner;
use App\Services\WizardData;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Concerns\InteractsWithRecord;
use Filament\Resources\Pages\Page;

class RelocationWizard extends Page
{
    use InteractsWithRecord;

    protected static string $resource = RelocationResource::class;

    protected string $view = 'filament.relocations.wizard';

    public string $tempDomain = '';

    public string $dnsManageUrl = '';

    /** "consider this script step handled" ticks for the collect/transfer/restore card */
    public array $skipSteps = [];

    public function mount(int|string $record): void
    {
        $this->record = $this->resolveRecord($record);
        $this->tempDomain = (string) $this->record->temp_domain;
        foreach (['collect', 'transfer', 'restore'] as $key) {
            $this->skipSteps[$key] = $this->record->checkDone($key);
        }
        if ($ns = $this->primaryNameserver()) {
            $this->dnsManageUrl = (string) Setting::get('dns_manage:'.DnsService::nsKey($ns));
        }
    }

    /** The zone's SOA primary nameserver — the key the manage URL is stored under. */
    protected function primaryNameserver(): ?string
    {
        return app(WizardData::class)->dnsInfo($this->record)['manage_ns'] ?? null;
    }

    public function saveDnsManageUrl(): void
    {
        $url = trim($this->dnsManageUrl);
        if ($url !== '' && ! filter_var(str_replace('{zone}', 'zone.tld', $url), FILTER_VALIDATE_URL)) {
            Notification::make()->title('Invalid URL')->danger()->send();

            return;
        }
        $ns = $this->primaryNameserver();
        if (! $ns) {
            Notification::make()->title('No nameservers known for this domain')->danger()->send();

            return;
        }
        Setting::set('dns_manage:'.DnsService::nsKey($ns), $url ?: null);
        Notification::make()->title("DNS management URL saved for {$ns}")->success()->send();
    }

    public function getTitle(): string
    {
        return $this->record->label();
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('retarget')
                ->label('Change destination')
                ->icon('fas-shuffle')
                ->color('gray')
                ->schema([
                    Select::make('target_server_id')
                        ->label('New destination')
                        ->options(fn () => Server::whereNotIn('id', [
                            $this->record->source_server_id, $this->record->target_server_id,
                        ])->orderBy('name')->pluck('name', 'id'))
                        ->required(),
                ])
                ->requiresConfirmation()
                ->modalDescription('Source-side work (preflight, collect) is kept; target-side progress is reset.')
                ->action(function (array $data) {
                    try {
                        $relocation = app(RelocationRunner::class)
                            ->retarget($this->record, (int) $data['target_server_id']);
                    } catch (\InvalidArgumentException $e) {
                        Notification::make()->title($e->getMessage())->danger()->send();

                        return;
                    }

                    $this->redirect(RelocationResource::getUrl('view', ['record' => $relocation]));
                }),
            Action::make('cancel')
                ->label('Cancel relocation')
                ->icon('fas-trash')
                ->color('danger')
                ->requiresConfirmation()
                ->modalDescription('Removes this relocation and its logs. Nothing on the servers is touched.')
                ->visible(fn () => ! $this->record->finished_at)
                ->action(function () {
                    try {
                        app(WizardData::class)->forget($this->record);
                        app(RelocationRunner::class)->delete($this->record);
                    } catch (\InvalidArgumentException $e) {
                        Notification::make()->title($e->getMessage())->danger()->send();

                        return;
                    }
                    $this->redirect(RelocationResource::getUrl('index'));
                }),
        ];
    }

    public function runStep(string $step): void
    {
        try {
            app(RelocationRunner::class)->start($this->record, RelocationStep::from($step));
            Notification::make()->title("Started {$step}")->success()->send();
        } catch (\InvalidArgumentException $e) {
            Notification::make()->title($e->getMessage())->danger()->send();
        }
    }

    /** "Done" on the collect/transfer/restore card: mark unrun-but-ticked steps as skipped. */
    public function confirmScriptSteps(): void
    {
        $record = $this->record->refresh()->load('runs', 'checks');
        $states = $record->scriptStates();
        $keys = ['collect', 'transfer', 'restore'];
        foreach ($keys as $key) {
            if ($states[$key] !== RunState::Success && ! $record->checkDone($key) && ! ($this->skipSteps[$key] ?? false)) {
                Notification::make()->title('Tick all three steps first')->danger()->send();

                return;
            }
        }
        $runner = app(RelocationRunner::class);
        foreach ($keys as $key) {
            if ($states[$key] !== RunState::Success && ! $record->checkDone($key)) {
                $runner->setCheck($record, $key, true);
            }
        }
        Notification::make()->title('Collect / Transfer / Restore confirmed')->success()->send();
    }

    public function toggleCheck(string $key, bool $done): void
    {
        app(RelocationRunner::class)->setCheck($this->record->refresh(), $key, $done);
    }

    public function confirmStep(string $step): void
    {
        $note = null;
        if ($step === RelocationStep::ForgeSite->value) {
            $note = trim($this->tempDomain) ?: null;
            if ($note && ! preg_match('/^[A-Za-z0-9][A-Za-z0-9_.-]{0,63}$/', $note)) {
                Notification::make()->title('Invalid temp domain')->danger()->send();

                return;
            }
        }
        app(RelocationRunner::class)->setCheck($this->record->refresh(), $step, true, $note);
        Notification::make()->title(RelocationStep::from($step)->label().' confirmed')->success()->send();
    }

    public function undoStep(string $step): void
    {
        app(RelocationRunner::class)->setCheck($this->record->refresh(), $step, false);
    }

    protected function getViewData(): array
    {
        $wizard = app(WizardData::class);
        $record = $this->record->refresh();
        $steps = $wizard->steps($record);
        $running = $record->runningStep();

        $activeScript = $running?->value
            ?? collect($steps)->first(fn (array $s) => $s['script'] && $s['current'])['key']
            ?? collect($steps)->reverse()->first(fn (array $s) => $s['script'] && $s['state'] !== 'idle')['key']
            ?? null;
        $latestRun = $activeScript
            ? $record->runs->where('step', $activeScript)->sortByDesc('id')->first()
            : null;

        $currentStep = collect($steps)->first(fn (array $s) => $s['current'])['key'] ?? null;

        return [
            'steps' => $steps,
            'dnsInfo' => $wizard->dnsInfo($record),
            'siteInfo' => $wizard->siteInfo($record),
            'sizes' => $wizard->sizes($record),
            'running' => $running?->value,
            'activeScript' => $activeScript,
            'log' => $latestRun?->logTail() ?? '',
            'history' => $record->runs->sortByDesc('id')->take(8)->values(),
            'envTemplate' => $currentStep === 'forge_site' ? $wizard->envTemplate($record) : null,
            'suggestedDomain' => $wizard->suggestTempDomain($record),
            'sourceForgeUrl' => $record->sourceServer->forgeUrl(),
            'targetForgeUrl' => $record->targetServer->forgeUrl(),
        ];
    }
}
