<?php

use App\Models\User;
use Filament\Auth\MultiFactor\App\AppAuthentication;
use Filament\Auth\Pages\Login;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

// ---- Temuan 1: header keamanan (anti clickjacking dll.) ----

it('sends anti-framing and hardening headers on public, tenant and admin pages', function (string $path) {
    $response = $this->get($path);

    $response->assertHeader('X-Frame-Options', 'SAMEORIGIN')
        ->assertHeader('Content-Security-Policy', "frame-ancestors 'self'")
        ->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin');
})->with(['/', '/login', '/admin/login']);

it('sends HSTS only over HTTPS in production', function () {
    $this->get('http://localhost/login')->assertHeaderMissing('Strict-Transport-Security');
    $this->get('https://localhost/login')->assertHeaderMissing('Strict-Transport-Security'); // bukan produksi

    $this->app['env'] = 'production';

    $this->get('https://localhost/login')->assertHeader('Strict-Transport-Security', 'max-age=31536000');
    $this->get('http://localhost/login')->assertHeaderMissing('Strict-Transport-Security');
});

// ---- Cookie sesi Secure ----
// Tanpa SESSION_SECURE_COOKIE, Laravel meneruskan secure=null dan Symfony (Response::prepare) menandai cookie Secure
// untuk request HTTPS. Tes ini menjaga perilaku itu (mis. agar tidak ada yang mengisi false/string kosong di produksi).

function sessionCookie($response)
{
    return collect($response->headers->getCookies())->firstWhere(fn ($c) => $c->getName() === config('session.cookie'));
}

it('marks the session cookie Secure for HTTPS requests when not configured', function () {
    config(['session.secure' => null]);

    expect(sessionCookie($this->get('https://localhost/login'))->isSecure())->toBeTrue();
});

it('keeps the session cookie usable over plain HTTP for local development', function () {
    config(['session.secure' => null]);

    expect(sessionCookie($this->get('http://localhost/login'))->isSecure())->toBeFalse();
});

it('respects an explicit SESSION_SECURE_COOKIE value', function () {
    config(['session.secure' => false]);

    expect(sessionCookie($this->get('https://localhost/login'))->isSecure())->toBeFalse();
});

// ---- Temuan 8: level log produksi ----

it('defaults the log level to info in production when LOG_LEVEL is not set', function () {
    $saved = ['APP_ENV' => getenv('APP_ENV'), 'LOG_LEVEL' => getenv('LOG_LEVEL')];
    $savedServer = array_intersect_key($_SERVER, $saved);
    $savedEnv = array_intersect_key($_ENV, $saved);

    try {
        putenv('APP_ENV=production');
        putenv('LOG_LEVEL');
        $_SERVER['APP_ENV'] = $_ENV['APP_ENV'] = 'production';
        unset($_SERVER['LOG_LEVEL'], $_ENV['LOG_LEVEL']);

        $config = require config_path('logging.php');

        expect($config['channels']['single']['level'])->toBe('info')
            ->and($config['channels']['daily']['level'])->toBe('info');
    } finally {
        foreach ($saved as $key => $value) {
            $value === false ? putenv($key) : putenv("{$key}={$value}");
        }
        unset($_SERVER['APP_ENV'], $_ENV['APP_ENV'], $_SERVER['LOG_LEVEL'], $_ENV['LOG_LEVEL']);
        $_SERVER = $savedServer + $_SERVER;
        $_ENV = $savedEnv + $_ENV;
    }
});

// ---- Temuan 3: MFA wajib untuk panel admin ----

it('sends a platform admin without MFA to the MFA set-up page before anything else', function (string $path) {
    $admin = User::factory()->platformAdmin()->withoutMfa()->create();

    $this->actingAs($admin)->get($path)->assertRedirectContains('/admin/multi-factor-authentication/set-up');
})->with(['/admin', '/admin/tenants', '/admin/invoices']);

it('does not log an admin in with the password alone', function () {
    $admin = User::factory()->platformAdmin()->create(['email' => 'admin@velora.test']);
    Filament::setCurrentPanel('admin');

    Livewire::test(Login::class)
        ->fillForm(['email' => 'admin@velora.test', 'password' => 'password'])
        ->call('authenticate');

    $this->assertGuest();
});

it('logs an admin in only after a valid authenticator code', function () {
    $admin = User::factory()->platformAdmin()->create(['email' => 'admin@velora.test']);
    Filament::setCurrentPanel('admin');

    $page = Livewire::test(Login::class)
        ->fillForm(['email' => 'admin@velora.test', 'password' => 'password'])
        ->call('authenticate');

    $page->set('data.multiFactor.app.code', '000000')->call('authenticate');
    $this->assertGuest();

    $page->set('data.multiFactor.app.code', AppAuthentication::make()->getCurrentCode($admin))->call('authenticate');
    $this->assertAuthenticatedAs($admin);
});

it('stores the MFA secret encrypted and never serializes it', function () {
    $admin = User::factory()->platformAdmin()->create();
    $secret = $admin->getAppAuthenticationSecret();

    expect(DB::table('users')->where('id', $admin->id)->value('app_authentication_secret'))->not->toBe($secret)
        ->and($admin->toArray())->not->toHaveKey('app_authentication_secret')
        ->and($admin->toArray())->not->toHaveKey('app_authentication_recovery_codes');
});

it('does not require MFA from tenant users, who never use the admin panel', function () {
    $user = User::factory()->create();

    expect($user->getAppAuthenticationSecret())->toBeNull();
    $this->actingAs($user)->get('/admin')->assertForbidden();
});
