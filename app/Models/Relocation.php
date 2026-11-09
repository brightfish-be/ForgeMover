<?php

namespace App\Models;

use App\Enums\RelocationStep;
use App\Enums\RunState;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Relocation extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'finished_at' => 'datetime',
        ];
    }

    public function sourceServer(): BelongsTo
    {
        return $this->belongsTo(Server::class, 'source_server_id');
    }

    public function targetServer(): BelongsTo
    {
        return $this->belongsTo(Server::class, 'target_server_id');
    }

    public function runs(): HasMany
    {
        return $this->hasMany(RelocationRun::class);
    }

    public function checks(): HasMany
    {
        return $this->hasMany(RelocationCheck::class);
    }

    /** Site name on the target while DNS still points at the source. */
    public function targetSiteName(): string
    {
        return $this->temp_domain ?: $this->site_name;
    }

    public function label(): string
    {
        return "{$this->sourceServer->name} → {$this->targetServer->name} · {$this->site_name}";
    }

    /** @return array<string, RunState> latest state per script step */
    public function scriptStates(): array
    {
        $states = [];
        foreach (RelocationStep::scriptSteps() as $step) {
            $latest = $this->runs->where('step', $step->value)->sortByDesc('id')->first();
            $states[$step->value] = $latest ? RunState::from($latest->state) : RunState::Idle;
        }

        return $states;
    }

    public function runningStep(): ?RelocationStep
    {
        $run = $this->runs->where('state', RunState::Running->value)->sortByDesc('id')->first();

        return $run ? RelocationStep::from($run->step) : null;
    }

    public function checkDone(string $stepKey): bool
    {
        return $this->checks->firstWhere('step_key', $stepKey)?->done_at !== null;
    }

    public function stepDone(RelocationStep $step): bool
    {
        if ($step->isScript()) {
            // a check row on a script step = manually marked done ("skipped",
            // e.g. nothing to transfer for an HTML-only site)
            return $this->scriptStates()[$step->value] === RunState::Success
                || $this->checkDone($step->value);
        }

        return $this->checkDone($step->value);
    }

    /** The first step whose predecessors are all done. */
    public function currentStep(): ?RelocationStep
    {
        foreach (RelocationStep::ordered() as $step) {
            if (! $this->stepDone($step)) {
                return $step;
            }
        }

        return null;
    }

    public function logDirectory(): string
    {
        return storage_path('app/relocations/'.
            "{$this->sourceServer->name}__{$this->site_name}__{$this->targetServer->name}");
    }
}
