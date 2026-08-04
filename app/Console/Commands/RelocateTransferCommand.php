<?php

namespace App\Console\Commands;

use App\Enums\RelocationStep;

class RelocateTransferCommand extends RelocateStepCommand
{
    protected $signature = 'forgemover:transfer
        {relocation : relocation ID}
        {--now : run synchronously in this process instead of queueing}';

    protected $description = 'Move the collected archives from the source to the target server and verify checksums';

    protected RelocationStep $step = RelocationStep::Transfer;
}
