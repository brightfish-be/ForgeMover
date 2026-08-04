<?php

namespace App\Console\Commands;

use App\Enums\RelocationStep;
use App\Enums\RunState;
use App\Models\Relocation;
use App\Services\RelocationRunner;
use Illuminate\Console\Command;
use InvalidArgumentException;

/**
 * Base for the per-step relocation commands: resolves the relocation, then
 * queues a RunRelocationStep job (or runs it synchronously with --now).
 */
abstract class RelocateStepCommand extends Command
{
    protected RelocationStep $step;

    public function handle(RelocationRunner $runner): int
    {
        $relocation = Relocation::find($this->argument('relocation'));
        if (! $relocation) {
            $this->error('relocation not found — list them with: php artisan tinker --execute="App\Models\Relocation::all(\'id\',\'site_name\')"');

            return self::FAILURE;
        }
        $this->line("{$relocation->label()} · {$this->step->value}");

        if (! $this->confirmStep($relocation)) {
            return self::FAILURE;
        }

        try {
            $run = $runner->start($relocation, $this->step, now: $this->option('now'));
        } catch (InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        if (! $this->option('now')) {
            $this->info("queued — follow with: tail -f {$run->log_file}");

            return self::SUCCESS;
        }

        $this->output->write(file_get_contents($run->log_file));

        return $run->state === RunState::Success->value ? self::SUCCESS : self::FAILURE;
    }

    /** Override for steps that need an explicit go-ahead. */
    protected function confirmStep(Relocation $relocation): bool
    {
        return true;
    }
}
