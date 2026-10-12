<?php

use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Filament\Facades\Filament;

it('allows platform admins to access the admin panel', function () {
    $admin = User::factory()->platformAdmin()->create();

    expect($admin->canAccessPanel(Filament::getPanel('admin')))->toBeTrue();
});

it('denies regular users access to the admin panel', function () {
    $user = User::factory()->create();

    expect($user->canAccessPanel(Filament::getPanel('admin')))->toBeFalse();
});

it('forbids a regular user from opening /admin', function () {
    $this->actingAs(User::factory()->create())->get('/admin')->assertForbidden();
});

it('lets a platform admin open /admin', function () {
    $this->actingAs(User::factory()->platformAdmin()->create())->get('/admin')->assertOk();
});

it('does not allow is_platform_admin via mass assignment', function () {
    $user = User::create(['name' => 'X', 'email' => 'x@example.com', 'password' => 'secret', 'is_platform_admin' => true]);

    expect($user->fresh()->is_platform_admin)->toBeFalse();
});

it('does not seed the dummy account in production', function () {
    app()->detectEnvironment(fn () => 'production');

    app(DatabaseSeeder::class)->run();

    expect(User::where('email', 'test@example.com')->exists())->toBeFalse();
});

it('seeds the dummy platform admin outside production', function () {
    app(DatabaseSeeder::class)->run();

    expect(User::where('email', 'test@example.com')->first()?->is_platform_admin)->toBeTrue();
});
