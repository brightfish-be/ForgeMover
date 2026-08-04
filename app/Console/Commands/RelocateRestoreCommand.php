<?php

namespace App\Console\Commands;

use App\Enums\RelocationStep;
use App\Models\Relocation;

class RelocateRestoreCommand extends RelocateStepCommand
{
    protected $signature = 'forgemover:restore
        {relocation : relocation ID}
        {--now : run synchronously in this process instead of queueing}
        {--force : do not ask for confirmation}';

    protected $description = 'Restore storage/app and database on the target server (code comes from git via Forge)';

    protected RelocationStep $step = RelocationStep::Restore;

    protected function confirmStep(Relocation $relocation): bool
    {
        return $this->option('force') || $this->confirm(
            "Restore into [{$relocation->targetSiteName()}] on [{$relocation->targetServer->name}]? This overwrites existing storage and database data."
        );
    }
}
