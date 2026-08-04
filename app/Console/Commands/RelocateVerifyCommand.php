<?php

namespace App\Console\Commands;

use App\Enums\RelocationStep;

class RelocateVerifyCommand extends RelocateStepCommand
{
    protected $signature = 'forgemover:verify
        {relocation : relocation ID}
        {--now : run synchronously in this process instead of queueing}';

    protected $description = 'Check that the target site holds no references to the old server (IPs, hostnames, stale config)';

    protected RelocationStep $step = RelocationStep::Verify;
}
