<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Site extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'isolated' => 'boolean',
            'zero_downtime' => 'boolean',
        ];
    }

    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
    }

    public function forgeUrl(): ?string
    {
        $serverUrl = $this->server->forgeUrl();
        if (! $serverUrl || ! $this->forge_site_id) {
            return $serverUrl;
        }

        return "{$serverUrl}/{$this->forge_site_id}";
    }

    public function repoBrowseUrl(): ?string
    {
        if (! $this->repo_url) {
            return null;
        }
        $path = preg_replace('/\.git$/', '', $this->repo_url);
        if (str_contains($path, '@')) {
            $path = preg_replace('/^.*@([^:]+):/', 'https://$1/', $path);
        }

        return match ($this->repo_provider) {
            'bitbucket' => rtrim($path, '/').'/src/'.($this->repo_branch ?: 'main').'/',
            default => $path,
        };
    }

    public function badgeImgUrl(): ?string
    {
        if (! $this->badge_uuid) {
            return null;
        }
        $endpoint = config('forgemover.forge.base_url')."/site-badges/{$this->badge_uuid}?date=1&label=1";

        return 'https://img.shields.io/endpoint?url='.urlencode($endpoint).'&style=plastic';
    }
}
