<?php

use App\Enums\RelocationStep;
use App\Enums\RunState;
use App\Jobs\RunRelocationStep;
use App\Models\Relocation;
use App\Models\Server;
use App\Services\RelocationRunner;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;

function makeRelocation(): Relocation
{
    $source = Server::create(['name' => 'ocean1']);
    $target = Server::create(['name' => 'ocean11']);

    return Relocation::create([
        'source_server_id' => $source->id,
        'target_server_id' => $target->id,
        'site_name' => 'data.example.com',
        'unix_user' => 'forge',
    ]);
}

it('starts a script step as a queued job', function () {
    Queue::fake();
    $relocation = makeRelocation();

    $run = app(RelocationRunner::class)->start($relocation, RelocationStep::Collect);

    Queue::assertPushed(RunRelocationStep::class);
    expect($run->state)->toBe(RunState::Running->value)
        ->and(is_file($run->log_file))->toBeTrue()
        ->and($relocation->refresh()->runningStep())->toBe(RelocationStep::Collect);

    File::deleteDirectory($relocation->logDirectory());
});

it('refuses to start a second step while one is running', function () {
    Queue::fake();
    $relocation = makeRelocation();
    $runner = app(RelocationRunner::class);
    $runner->start($relocation, RelocationStep::Collect);

    $runner->start($relocation->refresh(), RelocationStep::Transfer);
})->throws(InvalidArgumentException::class, 'still running');

it('refuses manual steps as script runs', function () {
    app(RelocationRunner::class)->start(makeRelocation(), RelocationStep::Preflight);
})->throws(InvalidArgumentException::class, 'manual step');

it('stores the temp domain when confirming the forge_site step', function () {
    $relocation = makeRelocation();

    app(RelocationRunner::class)->setCheck($relocation, 'forge_site', true, 'new-data-example-com.on-forge.com');

    expect($relocation->refresh()->temp_domain)->toBe('new-data-example-com.on-forge.com')
        ->and($relocation->targetSiteName())->toBe('new-data-example-com.on-forge.com');
});

it('marks the relocation finished on cutover confirm', function () {
    $relocation = makeRelocation();

    app(RelocationRunner::class)->setCheck($relocation, 'cutover', true);

    expect($relocation->refresh()->finished_at)->not->toBeNull();
});

it('computes wizard step gating', function () {
    $relocation = makeRelocation();

    expect($relocation->currentStep())->toBe(RelocationStep::Preflight)
        ->and($relocation->stepDone(RelocationStep::Preflight))->toBeFalse();

    app(RelocationRunner::class)->setCheck($relocation, 'preflight', true);

    expect($relocation->refresh()->load('checks', 'runs')->currentStep())->toBe(RelocationStep::ForgeSite);
});
