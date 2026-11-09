<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Server extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'compromised' => 'boolean',
            'destination_only' => 'boolean',
            'reachable' => 'boolean',
            'has_db' => 'boolean',
            'has_redis' => 'boolean',
            'php_versions' => 'array',
            'facts_fetched_at' => 'datetime',
            'sites_synced_at' => 'datetime',
        ];
    }

    public function sites(): HasMany
    {
        return $this->hasMany(Site::class);
    }

    public function forgeUrl(): ?string
    {
        $org = Setting::get('forge_org') ?? config('forgemover.forge.org');
        if (! $org) {
            return null;
        }

        return config('forgemover.forge.base_url')."/{$org}/".($this->slug ?: $this->name);
    }

    public function osEol(): ?array
    {
        // Ubuntu LTS end-of-standard-support dates
        $eol = [
            '16.04' => '2021-04-30', '18.04' => '2023-05-31', '20.04' => '2025-05-31',
            '22.04' => '2027-04-30', '24.04' => '2029-04-30', '26.04' => '2031-04-30',
        ];
        $date = $eol[$this->os_version] ?? null;

        return $date ? ['date' => $date, 'eol' => $date < now()->toDateString()] : null;
    }

    /** True when the Ubuntu release is unknown or reaches EOL within $years — a poor relocation target. */
    public function osEolSoon(int $years = 2): bool
    {
        $date = $this->osEol()['date'] ?? null;

        return $date === null || $date < now()->addYears($years)->toDateString();
    }

    public function uptimePretty(): string
    {
        $s = (int) $this->uptime_s;
        if ($s <= 0) {
            return '';
        }
        $d = intdiv($s, 86400);
        $h = intdiv($s % 86400, 3600);

        return $d > 0 ? "{$d}d {$h}h" : "{$h}h ".intdiv($s % 3600, 60).'m';
    }
}
