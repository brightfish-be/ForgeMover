<?php

namespace App\Http\Controllers;

use App\Jobs\ProbeSiteTypes;
use App\Jobs\RefreshServerFacts;
use App\Models\Server;
use App\Models\Setting;
use App\Models\User;
use App\Services\EnvWriter;
use App\Services\ForgeSync;
use App\Services\SetupStatus;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;

/**
 * The /setup web wizard. Open while setup is incomplete; once an admin user
 * exists it requires that user to be logged in (no takeover after install).
 */
class SetupController extends Controller
{
    public function index(SetupStatus $status)
    {
        return view('setup', [
            'checks' => $status->checks(),
            'complete' => $status->complete(),
            'hasUsers' => $this->hasUsers(),
            'hasToken' => ! empty(config('forgemover.forge.token')),
            'org' => Setting::get('forge_org') ?? config('forgemover.forge.org'),
            'serverCount' => $this->hasUsers() ? Server::count() : 0,
        ]);
    }

    public function migrate()
    {
        Artisan::call('migrate', ['--force' => true]);
        if (! config('app.key')) {
            Artisan::call('key:generate', ['--force' => true]);
        }

        return back()->with('status', 'Database migrated.');
    }

    public function secrets(Request $request, SetupStatus $status, EnvWriter $env)
    {
        $data = $request->validate([
            'token' => ['required', 'string'],
            'gandi' => ['nullable', 'string', 'max:100'],
            'cloudflare' => ['nullable', 'string', 'max:100'],
        ]);

        $orgs = $status->validateForgeToken($data['token']);
        if ($orgs === null) {
            return back()->withErrors(['token' => 'That token was rejected by the Forge API.']);
        }

        $env->set(['FORGE_API_TOKEN' => $data['token']]);
        if (count($orgs) === 1) {
            Setting::set('forge_org', $orgs[0]['slug']);
            $env->set(['FORGE_ORG' => $orgs[0]['slug']]);
        }
        Setting::set('dns_account:gandi', $data['gandi'] ?: null);
        Setting::set('dns_account:cloudflare', $data['cloudflare'] ?: null);

        return back()->with('status', 'Forge token validated and saved'
            .(count($orgs) === 1 ? " — organization: {$orgs[0]['slug']}" : ' — pick the organization via FORGE_ORG in .env'));
    }

    public function admin(Request $request)
    {
        if ($this->hasUsers()) {
            return back()->withErrors(['email' => 'An admin user already exists.']);
        }
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'email' => ['required', 'email'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        $user = User::create([
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => Hash::make($data['password']),
        ]);
        auth()->login($user);

        return back()->with('status', 'Admin user created — you are now logged in.');
    }

    public function sync(ForgeSync $sync)
    {
        try {
            $result = $sync->syncAll();
        } catch (\Throwable $e) {
            return back()->withErrors(['token' => 'Sync failed: '.$e->getMessage()]);
        }
        Server::all()->each(function (Server $server) {
            RefreshServerFacts::dispatch($server);
            ProbeSiteTypes::dispatch($server);
        });

        return back()->with('status',
            "Synced {$result['servers']} servers and {$result['sites']} sites; ssh probes queued.");
    }

    protected function hasUsers(): bool
    {
        try {
            return User::count() > 0;
        } catch (\Throwable) {
            return false;
        }
    }
}
