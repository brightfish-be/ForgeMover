<?php

namespace App\Jobs;

use App\Enums\RelocationStep;
use App\Enums\RunState;
use App\Models\RelocationRun;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Symfony\Component\Process\Process;

/**
 * Executes one script step of bin/relocate-laravel.sh for a relocation,
 * streaming output to the run's log file. Long-running: collect/transfer of
 * a big site can take an hour or more.
 */
class RunRelocationStep implements ShouldQueue
{
    use Queueable;

    public int $timeout = 7200;

    public int $tries = 1;

    public function __construct(public RelocationRun $run) {}

    public function handle(): void
    {
        $run = $this->run;
        $relocation = $run->relocation;
        $step = RelocationStep::from($run->step);

        $script = rtrim(config('forgemover.bin_path'), '/').'/relocate-laravel.sh';
        if (! is_file($script)) {
            $this->finish(RunState::Failed, null, "relocate-laravel.sh not found at {$script}\n");

            return;
        }

        $command = ['bash', $script, '-f', '-u', $relocation->unix_user];
        if ($relocation->temp_domain) {
            $command = [...$command, '-n', $relocation->temp_domain];
        }
        $command = [...$command, $step->value,
            $relocation->sourceServer->name, $relocation->targetServer->name, $relocation->site_name];

        $log = fopen($run->log_file, 'ab');
        fwrite($log, '$ '.implode(' ', $command)."\n\n");

        $process = new Process($command, timeout: $this->timeout - 60);
        $exitCode = null;
        try {
            $exitCode = $process->run(function (string $type, string $buffer) use ($log) {
                fwrite($log, $buffer);
                fflush($log);
            });
        } catch (\Throwable $e) {
            fwrite($log, "\n!! ".$e->getMessage()."\n");
        } finally {
            fclose($log);
        }

        // bashew scripts always exit 0, so the log marker is the real signal.
        $success = $exitCode === 0 && str_contains(file_get_contents($run->log_file), $step->doneMarker());
        $this->finish($success ? RunState::Success : RunState::Failed, $exitCode);
    }

    public function failed(?\Throwable $exception): void
    {
        $this->finish(RunState::Failed, null, "\n!! job failed: ".$exception?->getMessage()."\n");
    }

    protected function finish(RunState $state, ?int $exitCode, string $appendLog = ''): void
    {
        if ($appendLog !== '' && is_file($this->run->log_file)) {
            file_put_contents($this->run->log_file, $appendLog, FILE_APPEND);
        }
        $this->run->update([
            'state' => $state->value,
            'exit_code' => $exitCode,
            'finished_at' => now(),
        ]);
    }
}
