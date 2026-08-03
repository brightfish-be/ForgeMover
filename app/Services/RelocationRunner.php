<?php

namespace App\Services;

use App\Enums\RelocationStep;
use App\Enums\RunState;
use App\Jobs\RunRelocationStep;
use App\Models\Relocation;
use App\Models\RelocationRun;
use Illuminate\Support\Facades\File;
use InvalidArgumentException;

/**
 * Orchestrates relocation script steps as queued jobs (the legacy app used
 * nohup + pid polling) and owns the checklist / retarget / delete rules.
 */
class RelocationRunner
{
    /** Start a script step; throws when another step is running or the step is manual. */
    public function start(Relocation $relocation, RelocationStep $step): RelocationRun
    {
        if (! $step->isScript()) {
            throw new InvalidArgumentException("{$step->value} is a manual step");
        }
        $relocation->load('runs');
        if ($running = $relocation->runningStep()) {
            throw new InvalidArgumentException("step {$running->value} is still running");
        }

        $dir = $relocation->logDirectory();
        File::ensureDirectoryExists($dir);
        $logFile = $dir.'/'.$step->value.'.'.now()->format('Ymd-His').'.log';
        File::put($logFile, '# '.$relocation->label()." · {$step->value} · ".now()->toDateTimeString()."\n");

        $run = $relocation->runs()->create([
            'step' => $step->value,
            'state' => RunState::Running->value,
            'log_file' => $logFile,
            'started_at' => now(),
        ]);

        RunRelocationStep::dispatch($run);

        return $run;
    }

    /** Tick/untick a manual step or sub-check; forge_site's note is the temp domain. */
    public function setCheck(Relocation $relocation, string $stepKey, bool $done, ?string $note = null): void
    {
        if ($note !== null && ! preg_match('/^[A-Za-z0-9][A-Za-z0-9_.-]{0,63}$/', $note)) {
            throw new InvalidArgumentException('invalid note');
        }

        $relocation->checks()->updateOrCreate(
            ['step_key' => $stepKey],
            ['done_at' => $done ? now() : null, 'note' => $note],
        );

        if ($stepKey === RelocationStep::ForgeSite->value) {
            $relocation->update(['temp_domain' => $done ? $note : null]);
        }
        if ($stepKey === RelocationStep::Cutover->value) {
            $relocation->update(['finished_at' => $done ? now() : null]);
        }
    }

    /** Cancel a relocation. Nothing on the servers is touched. */
    public function delete(Relocation $relocation): void
    {
        $relocation->load('runs');
        if ($relocation->runningStep()) {
            throw new InvalidArgumentException('a step is still running');
        }
        if ($relocation->finished_at) {
            throw new InvalidArgumentException('cutover already confirmed');
        }
        File::deleteDirectory($relocation->logDirectory());
        $relocation->delete();
    }

    /**
     * Point the relocation at a different destination server. Source-side
     * work (preflight, collect) is kept; target-side progress is reset.
     */
    public function retarget(Relocation $relocation, int $newTargetId): Relocation
    {
        $relocation->load('runs');
        if ($relocation->runningStep()) {
            throw new InvalidArgumentException('a step is still running');
        }
        if ($newTargetId === $relocation->target_server_id || $newTargetId === $relocation->source_server_id) {
            throw new InvalidArgumentException('pick a different server');
        }

        $existing = Relocation::where([
            'source_server_id' => $relocation->source_server_id,
            'site_name' => $relocation->site_name,
            'target_server_id' => $newTargetId,
        ])->first();
        if ($existing) {
            return $existing;
        }

        $resetSteps = [RelocationStep::ForgeSite, RelocationStep::DeployTest, RelocationStep::Cutover];
        foreach ($resetSteps as $step) {
            $relocation->checks()
                ->where('step_key', $step->value)
                ->orWhere(fn ($q) => $q->where('relocation_id', $relocation->id)
                    ->where('step_key', 'like', $step->value.':%'))
                ->delete();
        }
        $relocation->runs()->whereIn('step', ['transfer', 'restore', 'verify'])->delete();
        $relocation->update(['target_server_id' => $newTargetId, 'temp_domain' => null, 'finished_at' => null]);

        return $relocation->refresh();
    }
}
