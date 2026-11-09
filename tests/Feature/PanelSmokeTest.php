<?php

use App\Models\Relocation;
use App\Models\Server;
use App\Models\User;

beforeEach(function () {
    $this->actingAs(User::factory()->create());
});

it('renders the dashboard', function () {
    $this->get('/admin')->assertOk()->assertSee('Servers');
});

it('renders the server list and view', function () {
    $server = Server::create(['name' => 'ocean1', 'os_version' => '20.04', 'php_versions' => ['8.3']]);

    $this->get('/admin/servers')->assertOk()->assertSee('ocean1');
    $this->get("/admin/servers/{$server->id}")->assertOk()->assertSee('Connected');
    $this->get("/admin/servers/{$server->id}/edit")->assertOk()->assertSee('Settings');
});

it('renders the site list', function () {
    $server = Server::create(['name' => 'ocean1']);
    $server->sites()->create(['name' => 'data.example.com', 'app_type' => 'laravel 11']);

    $this->get('/admin/sites')->assertOk()->assertSee('data.example.com');
});

it('renders the relocation list, create form and wizard', function () {
    $source = Server::create(['name' => 'ocean1']);
    $target = Server::create(['name' => 'ocean11']);
    $relocation = Relocation::create([
        'source_server_id' => $source->id,
        'target_server_id' => $target->id,
        'site_name' => 'data.example.com',
    ]);

    $this->get('/admin/relocations')->assertOk()->assertSee('data.example.com');
    $this->get('/admin/relocations/create')->assertOk();
    $this->get("/admin/relocations/{$relocation->id}")
        ->assertOk()
        ->assertSee('Preflight checks')
        ->assertSee('Collect archives')
        ->assertSee('Transfer to target')
        ->assertSee('Restore on target')
        ->assertSee('sizes fetched')
        ->assertSee('Cutover');
});

it('renders the packages page', function () {
    $this->get('/admin/packages')->assertOk()->assertSee('Source server');
});
