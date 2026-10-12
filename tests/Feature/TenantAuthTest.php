<?php

use App\Models\Tenant;
use App\Models\TenantMember;
use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;

uses(RefreshDatabase::class);

function registrationPayload(array $override = []): array
{
    return array_merge([
        'name' => 'Budi',
        'workspace_name' => 'Toko Maju',
        'email' => 'budi@example.com',
        'password' => 'rahasia-123',
        'password_confirmation' => 'rahasia-123',
    ], $override);
}

// TC-AUTH-01: registrasi
it('registers a user with an owned workspace and no subscription', function () {
    Notification::fake();

    $this->post('/register', registrationPayload())->assertRedirect(route('verification.notice'));

    $user = User::where('email', 'budi@example.com')->firstOrFail();
    $tenant = $user->tenants()->firstOrFail();

    expect($tenant->name)->toBe('Toko Maju')
        ->and($tenant->pivot->role)->toBe(TenantMember::ROLE_OWNER)
        ->and($tenant->hasActiveSubscription())->toBeFalse()
        ->and($user->is_platform_admin)->toBeFalse()
        ->and(Hash::check('rahasia-123', $user->password))->toBeTrue()
        ->and($user->email_verified_at)->toBeNull();

    $this->assertAuthenticatedAs($user);
    Notification::assertSentTo($user, VerifyEmail::class);
});

it('gives each workspace a unique slug', function () {
    Notification::fake();
    Tenant::factory()->create(['slug' => 'toko-maju']);

    $this->post('/register', registrationPayload())->assertRedirect();

    expect(Tenant::pluck('slug')->unique())->toHaveCount(2);
});

it('validates registration input', function () {
    User::factory()->create(['email' => 'budi@example.com']);

    $this->post('/register', registrationPayload())->assertSessionHasErrors('email');
    $this->post('/register', registrationPayload(['email' => 'lain@example.com', 'password_confirmation' => 'beda']))->assertSessionHasErrors('password');
    $this->post('/register', registrationPayload(['email' => 'lain@example.com', 'password' => 'pendek', 'password_confirmation' => 'pendek']))->assertSessionHasErrors('password');
    $this->post('/register', registrationPayload(['email' => 'lain@example.com', 'workspace_name' => '']))->assertSessionHasErrors('workspace_name');
});

it('cannot self-assign platform admin or a role at registration', function () {
    Notification::fake();

    $this->post('/register', registrationPayload(['is_platform_admin' => 1, 'role' => 'admin']));

    $user = User::where('email', 'budi@example.com')->firstOrFail();
    expect($user->is_platform_admin)->toBeFalse()
        ->and($user->tenants()->first()->pivot->role)->toBe('owner');
    $this->actingAs($user)->get('/admin')->assertForbidden();
});

// TC-AUTH-02: login/logout
it('logs in and logs out', function () {
    $user = User::factory()->create(['password' => 'rahasia-123']);

    $this->post('/login', ['email' => $user->email, 'password' => 'rahasia-123'])->assertRedirect(route('app.dashboard'));
    $this->assertAuthenticatedAs($user);

    $this->post('/logout')->assertRedirect(route('login'));
    $this->assertGuest();
});

it('rejects a wrong password and throttles repeated attempts', function () {
    $user = User::factory()->create(['password' => 'rahasia-123']);

    foreach (range(1, 5) as $i) {
        $this->post('/login', ['email' => $user->email, 'password' => 'salah'])->assertSessionHasErrors('email');
    }

    // Percobaan ke-6 diblokir meski password benar.
    $this->post('/login', ['email' => $user->email, 'password' => 'rahasia-123'])->assertSessionHasErrors('email');
    $this->assertGuest();
});

// TC-AUTH-03: reset password
it('resets a password through the emailed link', function () {
    Notification::fake();
    $user = User::factory()->create();

    $this->post('/forgot-password', ['email' => $user->email])->assertSessionHas('status');

    Notification::assertSentTo($user, ResetPassword::class, function ($notification) use ($user) {
        $this->post('/reset-password', [
            'token' => $notification->token,
            'email' => $user->email,
            'password' => 'password-baru-1',
            'password_confirmation' => 'password-baru-1',
        ])->assertRedirect(route('login'));

        return true;
    });

    expect(Hash::check('password-baru-1', $user->fresh()->password))->toBeTrue();
});

it('does not reveal whether an email is registered', function () {
    Notification::fake();

    $this->post('/forgot-password', ['email' => 'tidak-ada@example.com'])
        ->assertSessionHas('status')->assertSessionHasNoErrors();

    Notification::assertNothingSent();
});

it('rejects an invalid reset token', function () {
    $user = User::factory()->create();

    $this->post('/reset-password', [
        'token' => 'salah', 'email' => $user->email,
        'password' => 'password-baru-1', 'password_confirmation' => 'password-baru-1',
    ])->assertSessionHasErrors('email');
});

// TC-AUTH-04: verifikasi email
it('verifies email through a signed link and rejects a tampered one', function () {
    $user = User::factory()->unverified()->create();
    $url = URL::temporarySignedRoute('verification.verify', now()->addMinutes(60), ['id' => $user->id, 'hash' => sha1($user->email)]);

    $this->actingAs($user)->get($url . 'x')->assertForbidden();
    expect($user->fresh()->hasVerifiedEmail())->toBeFalse();

    $this->actingAs($user)->get($url)->assertRedirect(route('app.dashboard'));
    expect($user->fresh()->hasVerifiedEmail())->toBeTrue();
});

it('blocks /app until the email is verified', function () {
    $user = User::factory()->unverified()->create();

    $this->actingAs($user)->get('/app')->assertRedirect(route('verification.notice'));
});

// TC-AUTH-05: akses
it('redirects guests from /app to login', function () {
    $this->get('/app')->assertRedirect(route('login'));
});

it('redirects logged-in users away from guest pages', function () {
    $this->actingAs(User::factory()->create())->get('/login')->assertRedirect('/app');
});
