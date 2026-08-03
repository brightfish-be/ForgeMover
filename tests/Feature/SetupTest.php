<?php

use App\Models\User;

it('redirects the root to setup on a fresh install', function () {
    $this->get('/')->assertRedirect('/setup');
});

it('shows the setup wizard with requirement checks', function () {
    $this->get('/setup')
        ->assertOk()
        ->assertSee('ForgeMover')
        ->assertSee('Requirements')
        ->assertSee('Forge API token');
});

it('creates the admin user and logs in', function () {
    $this->post('/setup/admin', [
        'name' => 'Admin',
        'email' => 'admin@example.com',
        'password' => 'secret-password',
        'password_confirmation' => 'secret-password',
    ])->assertRedirect();

    expect(User::count())->toBe(1);
    $this->assertAuthenticated();
});

it('refuses a second admin user', function () {
    User::factory()->create();

    $this->actingAs(User::first())->post('/setup/admin', [
        'name' => 'Evil',
        'email' => 'evil@example.com',
        'password' => 'secret-password',
        'password_confirmation' => 'secret-password',
    ])->assertSessionHasErrors('email');

    expect(User::count())->toBe(1);
});

it('locks setup behind login once a user exists', function () {
    User::factory()->create();

    $this->get('/setup')->assertRedirect('/admin/login');
    $this->actingAs(User::first())->get('/setup')->assertOk();
});
