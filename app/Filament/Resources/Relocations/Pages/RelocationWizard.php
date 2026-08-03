<?php

namespace App\Filament\Resources\Relocations\Pages;

use App\Enums\RelocationStep;
use App\Filament\Resources\Relocations\RelocationResource;
use App\Models\Server;
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

    public function mount(int|string $record): void
    {
        $this->record = $this->resolveRecord($record);
        $this->tempDomain = (string) $this->record->temp_domain;
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
