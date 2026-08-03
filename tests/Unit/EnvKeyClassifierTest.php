<?php

use App\Services\EnvKeyClassifier;

it('classifies env keys for a compromised source', function () {
    $buckets = (new EnvKeyClassifier)->classify([
        'APP_KEY', 'APP_URL', 'DB_HOST', 'DB_PASSWORD',
        'STRIPE_SECRET', 'MAIL_HOST', 'ACME_ENDPOINT', 'AWS_ACCESS_KEY_ID',
    ], compromised: true);

    expect($buckets['change'])->toContain('APP_KEY', 'APP_URL', 'DB_HOST', 'DB_PASSWORD')
        ->and($buckets['rotate'])->toContain('STRIPE_SECRET', 'AWS_ACCESS_KEY_ID')
        ->and($buckets['carry_standard'])->toContain('MAIL_HOST')
        ->and($buckets['carry_custom'])->toContain('ACME_ENDPOINT');
});

it('carries credentials over when the source is not compromised', function () {
    $buckets = (new EnvKeyClassifier)->classify(['STRIPE_SECRET', 'APP_KEY'], compromised: false);

    expect($buckets['carry_secret'])->toContain('STRIPE_SECRET')
        ->and($buckets['change'])->toContain('APP_KEY')
        ->and($buckets['rotate'])->toBeEmpty();
});
