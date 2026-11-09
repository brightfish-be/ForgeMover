<?php

namespace App\Services;

use App\Models\Server;

class FactsService
{
    // PHP security-support EOL dates; unknown versions count as EOL.
    public const PHP_EOL = [
        '5.6' => '2018-12-31', '7.0' => '2019-01-10', '7.1' => '2019-12-01', '7.2' => '2020-11-30',
        '7.3' => '2021-12-06', '7.4' => '2022-11-28', '8.0' => '2023-11-26', '8.1' => '2025-12-31',
        '8.2' => '2026-12-31', '8.3' => '2027-12-31', '8.4' => '2028-12-31', '8.5' => '2029-12-31',
    ];

    public function __construct(protected RemoteProbe $probe) {}

    /** ssh-probe a server and persist the facts; safe to call from a queued job. */
    public function refresh(Server $server): bool
    {
        $facts = $this->probe->facts($server->name);
        if ($facts === null) {
            $server->update(['reachable' => false, 'facts_fetched_at' => now()]);

            return false;
        }
        $server->update([...$facts, 'reachable' => true, 'facts_fetched_at' => now()]);

        return true;
    }

    public function refreshIfStale(Server $server, ?int $maxAge = null): void
    {
        $maxAge ??= config('forgemover.ttl.facts');
        if (! $server->facts_fetched_at || $server->facts_fetched_at->lt(now()->subSeconds($maxAge))) {
            $this->refresh($server);
        }
    }

    public static function phpIsEol(string $version): bool
    {
        $date = self::PHP_EOL[$version] ?? null;

        return $date === null || $date < now()->toDateString();
    }

    /** EOL date for a PHP version ("8.3" or "8.3.36"), null when unknown. */
    public static function phpEolDate(string $version): ?string
    {
        return self::PHP_EOL[implode('.', array_slice(explode('.', $version), 0, 2))] ?? null;
    }

    /** True when the PHP version is unknown or reaches EOL within $years. */
    public static function phpEolSoon(string $version, int $years = 2): bool
    {
        $date = self::phpEolDate($version);

        return $date === null || $date < now()->addYears($years)->toDateString();
    }
}
