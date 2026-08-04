<?php

namespace App\Console\Commands;

use App\Enums\RelocationStep;

class RelocateCollectCommand extends RelocateStepCommand
{
    protected $signature = 'forgemover:collect
        {relocation : relocation ID}
        {--now : run synchronously in this process instead of queueing}';

    protected $description = 'Archive code, storage/app, database and .env of the site on the source server';

    protected RelocationStep $step = RelocationStep::Collect;
}
