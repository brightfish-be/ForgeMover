<?php

namespace App\Jobs;

use App\Enums\RelocationStep;
use App\Enums\RunState;
use App\Models\RelocationRun;
use App\Services\RelocationSteps;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Executes one step of a relocation natively via RelocationSteps, streaming
 * output to the run's log file. Long-running: collect/transfer of a big site
 * can take an hour or more.
 */
class RunRelocationStep implements ShouldQueue
{
    use Queueable;

    public int $timeout = 7200;

    public int $tries = 1;

    public function __construct(public RelocationRun $run) {}

    public function handle(RelocationSteps $steps): void
    {
        $run = $this->run;
        $step = RelocationStep::from($run->step);

        $log = fopen($run->log_file, 'ab');
        $out = function (string $buffer) use ($log) {
            fwrite($log, $buffer);
            fflush($log);
        };

        try {
            $summary = match ($step) {
                RelocationStep::Collect => $steps->collect($run->relocation, $out),
                RelocationStep::Transfer => $steps->transfer($run->relocation, $out),
                RelocationStep::Restore => $steps->restore($run->relocation, $out),
                RelocationStep::Verify => $steps->verify($run->relocation, $out),
            };
            $this->finish(RunState::Success, 0, summary: is_string($summary) ? $summary : null);
        } catch (\Throwable $e) {
            $out("\n!! ".$e->getMessage()."\n");
            $this->finish(RunState::Failed, null);
        } finally {
            fclose($log);
        }
    }

    public function failed(?\Throwable $exception): void
    {
        $this->finish(RunState::Failed, null, "\n!! job failed: ".$exception?->getMessage()."\n");
    }

    protected function finish(RunState $state, ?int $exitCode, string $appendLog = '', ?string $summary = null): void
    {
        if ($appendLog !== '' && is_file($this->run->log_file)) {
            file_put_contents($this->run->log_file, $appendLog, FILE_APPEND);
        }
        $this->run->update([
            'state' => $state->value,
            'exit_code' => $exitCode,
            'summary' => $summary,
            'finished_at' => now(),
        ]);
    }
}
