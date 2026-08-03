<?php

namespace App\Services;

use App\Models\Setting;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

/**
 * Minimal Laravel Forge API v2 client (org-based; the official SDK only
 * supports v1). Cursor pagination via meta.next_cursor, page[size]=100.
 */
class ForgeClient
{
    public function __construct(protected ?string $token = null)
    {
        $this->token ??= config('forgemover.forge.token');
    }

    public function configured(): bool
    {
        return ! empty($this->token);
    }

    protected function http(): PendingRequest
    {
        return Http::baseUrl(config('forgemover.forge.api_url'))
            ->withToken((string) $this->token)
            ->acceptJson()
            ->timeout(20);
    }

    /** @return list<array{id: string, slug: string, name: string}> */
    public function orgs(): array
    {
        return collect($this->paginate('/orgs'))
            ->map(fn (array $org) => [
                'id' => (string) $org['id'],
                'slug' => $org['attributes']['slug'] ?? '',
                'name' => $org['attributes']['name'] ?? '',
            ])->all();
    }

    /** The configured org slug, auto-detected when the token sees exactly one org. */
    public function org(): ?string
    {
        $org = Setting::get('forge_org') ?? config('forgemover.forge.org');
        if ($org) {
            return $org;
        }
        $orgs = $this->orgs();
        if (count($orgs) === 1) {
            Setting::set('forge_org', $orgs[0]['slug']);

            return $orgs[0]['slug'];
        }

        return null;
    }

    public function servers(?string $org = null): array
    {
        $org ??= $this->org();

        return collect($this->paginate("/orgs/{$org}/servers"))
            ->map(fn (array $server) => [
                'forge_id' => (string) $server['id'],
                'name' => $server['attributes']['name'] ?? '',
                'slug' => $server['attributes']['slug'] ?? '',
                'ip_address' => $server['attributes']['ip_address'] ?? '',
                'region' => $server['attributes']['region'] ?? '',
                'size' => $server['attributes']['size'] ?? '',
            ])->all();
    }

    public function sites(string $serverId, ?string $org = null): array
    {
        $org ??= $this->org();

        return collect($this->paginate("/orgs/{$org}/servers/{$serverId}/sites"))
            ->map(fn (array $site) => [
                'forge_site_id' => (string) $site['id'],
                'name' => $site['attributes']['name'] ?? '',
                'repo_url' => $site['attributes']['repository']['url'] ?? '',
                'repo_branch' => $site['attributes']['repository']['branch'] ?? '',
                'repo_provider' => $site['attributes']['repository']['provider'] ?? '',
                'php_version' => $site['attributes']['php_version'] ?? '',
                'site_user' => $site['attributes']['user'] ?? '',
                'isolated' => (bool) ($site['attributes']['isolated'] ?? false),
                'zero_downtime' => (bool) ($site['attributes']['zero_downtime_deployments'] ?? false),
            ])->all();
    }

    protected function paginate(string $path): array
    {
        $items = [];
        $cursor = null;
        do {
            $query = ['page[size]' => 100];
            if ($cursor) {
                $query['page[cursor]'] = $cursor;
            }
            $response = $this->http()->get($path, $query)->throw()->json();
            $items = array_merge($items, $response['data'] ?? []);
            $cursor = $response['meta']['next_cursor'] ?? null;
        } while ($cursor);

        return $items;
    }
}
