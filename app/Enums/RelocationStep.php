<?php

namespace App\Enums;

enum RelocationStep: string
{
    case Preflight = 'preflight';
    case ForgeSite = 'forge_site';
    case Collect = 'collect';
    case Transfer = 'transfer';
    case Restore = 'restore';
    case DeployTest = 'deploy_test';
    case Verify = 'verify';
    case Cutover = 'cutover';

    /** All 8 steps, in wizard order. */
    public static function ordered(): array
    {
        return [
            self::Preflight, self::ForgeSite, self::Collect, self::Transfer,
            self::Restore, self::DeployTest, self::Verify, self::Cutover,
        ];
    }

    /** @return list<self> steps executed remotely by App\Services\RelocationSteps */
    public static function scriptSteps(): array
    {
        return [self::Collect, self::Transfer, self::Restore, self::Verify];
    }

    public function isScript(): bool
    {
        return in_array($this, self::scriptSteps(), true);
    }

    /** Sub-checks that must pass (or be hand-ticked) before a manual step can be confirmed. */
    public function subChecks(): array
    {
        return match ($this) {
            self::Preflight => ['php', 'key', 'dns', 'jobs'],
            self::ForgeSite => ['source', 'repo', 'db', 'domain', 'composer', 'deploykey', 'webdir', 'php', 'isolation', 'zdd', 'env'],
            self::DeployTest => ['storagelink'],
            self::Cutover => ['dnslive'],
            default => [],
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Preflight => 'Preflight checks',
            self::ForgeSite => 'Create Forge site',
            self::Collect => 'Collect archives',
            self::Transfer => 'Transfer to target',
            self::Restore => 'Restore on target',
            self::DeployTest => 'Deploy & test',
            self::Verify => 'Verify',
            self::Cutover => 'Cutover',
        };
    }
}
