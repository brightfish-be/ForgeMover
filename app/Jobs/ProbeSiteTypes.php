<?php

namespace App\Jobs;

use App\Models\Server;
use App\Services\RemoteProbe;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/** ssh-probes what actually runs in each /home/<user>/<site> (laravel N, wordpress, ...). */
class ProbeSiteTypes implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $timeout = 120;

    public function __construct(public Server $server) {}

    public function uniqueId(): string
    {
        return (string) $this->server->id;
    }

    public function handle(RemoteProbe $probe): void
    {
        $users = $this->server->sites()->pluck('site_user')->filter()->unique()
            ->whenEmpty(fn ($c) => collect([config('forgemover.default_user')]));

        $types = [];
        foreach ($users as $user) {
            $types += $probe->siteTypes($this->server->name, $user);
        }

        foreach ($this->server->sites as $site) {
            if (isset($types[$site->name]) && $types[$site->name] !== $site->app_type) {
                $site->update(['app_type' => $types[$site->name]]);
            }
        }
    }
}
