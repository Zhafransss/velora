<?php

use App\Models\ContactMessage;
use App\Models\User;
use App\Services\Security\RecaptchaVerifier;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

const RC_URL = 'https://www.google.com/recaptcha/api/siteverify';

beforeEach(function () {
    config(['services.recaptcha.enabled' => true, 'services.recaptcha.site_key' => 'SITE-KEY', 'services.recaptcha.secret_key' => 'SECRET-KEY']);
});

function rcForm(array $over = []): array
{
    return array_merge([
        'name' => 'Budi', 'workspace_name' => 'Kopi Budi', 'email' => 'budi@example.com',
        'password' => 'rahasia-banget-1', 'password_confirmation' => 'rahasia-banget-1', 'g-recaptcha-response' => 'token-ok',
    ], $over);
}

describe('verifier', function () {
    it('accepts a token Google approves, sending secret, token and ip as a form', function () {
        Http::fake([RC_URL => Http::response(['success' => true])]);

        expect(app(RecaptchaVerifier::class)->verify('token-ok', '203.0.113.7'))->toBeTrue();

        Http::assertSent(fn (Request $r) => $r->url() === RC_URL && $r['secret'] === 'SECRET-KEY' && $r['response'] === 'token-ok' && $r['remoteip'] === '203.0.113.7');
    });

    it('rejects a token Google refuses and logs only the error codes, never the token', function () {
        Http::fake([RC_URL => Http::response(['success' => false, 'error-codes' => ['invalid-input-response']])]);
        Log::spy();

        expect(app(RecaptchaVerifier::class)->verify('rahasia-token'))->toBeFalse();

        Log::shouldHaveReceived('info')->withArgs(fn ($m, $ctx) => $m === 'recaptcha.rejected' && $ctx === ['codes' => ['invalid-input-response']] && ! str_contains(json_encode($ctx), 'rahasia-token'))->once();
    });

    it('rejects an empty or missing token without calling Google', function () {
        Http::fake();

        foreach ([null, ''] as $token) {
            expect(app(RecaptchaVerifier::class)->verify($token))->toBeFalse();
        }
        Http::assertNothingSent();
    });

    it('fails closed when Google cannot be reached', function () {
        Http::fake([RC_URL => fn () => throw new ConnectionException('timeout')]);

        expect(app(RecaptchaVerifier::class)->verify('token-ok'))->toBeFalse();
    });

    it('fails closed when enabled but the secret key is missing, and says so in the log', function () {
        config(['services.recaptcha.secret_key' => null]);
        Http::fake();
        Log::spy();

        expect(app(RecaptchaVerifier::class)->verify('token-ok'))->toBeFalse();

        Http::assertNothingSent();
        Log::shouldHaveReceived('error')->withArgs(fn ($m) => $m === 'recaptcha.misconfigured')->once();
    });

    it('passes everything and never calls Google when disabled (local development only)', function () {
        config(['services.recaptcha.enabled' => false]);
        Http::fake();

        expect(app(RecaptchaVerifier::class)->verify(null))->toBeTrue()->and(app(RecaptchaVerifier::class)->siteKey())->toBeNull();
        Http::assertNothingSent();
    });

    it('only exposes the site key when enabled', function () {
        expect(app(RecaptchaVerifier::class)->siteKey())->toBe('SITE-KEY');
    });
});

describe('registration form', function () {
    it('shows the widget and loads the Google script when enabled', function () {
        $this->get(route('register'))->assertOk()->assertSee('g-recaptcha', false)->assertSee('data-sitekey="SITE-KEY"', false)
            ->assertSee('https://www.google.com/recaptcha/api.js', false);
    });

    it('does not load anything from Google when disabled', function () {
        config(['services.recaptcha.enabled' => false]);

        $this->get(route('register'))->assertOk()->assertDontSee('google.com/recaptcha', false);
    });

    it('creates the account when the captcha passes', function () {
        Http::fake([RC_URL => Http::response(['success' => true])]);

        $this->post(route('register'), rcForm())->assertRedirect(route('verification.notice'));

        expect(User::where('email', 'budi@example.com')->exists())->toBeTrue();
    });

    it('creates NOTHING when the captcha is missing', function () {
        Http::fake();

        $this->post(route('register'), rcForm(['g-recaptcha-response' => '']))
            ->assertSessionHasErrors(['g-recaptcha-response' => 'Silakan centang "Saya bukan robot" terlebih dahulu.']);

        expect(User::where('email', 'budi@example.com')->exists())->toBeFalse();
        Http::assertNothingSent();
    });

    it('creates NOTHING when Google rejects the token', function () {
        Http::fake([RC_URL => Http::response(['success' => false, 'error-codes' => ['timeout-or-duplicate']])]);

        $this->post(route('register'), rcForm())->assertSessionHasErrors(['g-recaptcha-response' => 'Verifikasi reCAPTCHA gagal. Silakan coba lagi.']);

        expect(User::where('email', 'budi@example.com')->exists())->toBeFalse();
    });

    it('creates NOTHING when Google is unreachable (fail closed) and keeps the typed values', function () {
        Http::fake([RC_URL => fn () => throw new ConnectionException('down')]);

        $this->from(route('register'))->post(route('register'), rcForm())->assertRedirect(route('register'))->assertSessionHasErrors('g-recaptcha-response');

        expect(User::count())->toBe(0);
    });

    it('does not spend a token on a form that is invalid anyway', function () {
        Http::fake();

        $this->post(route('register'), rcForm(['email' => 'bukan-email']))->assertSessionHasErrors('email');

        Http::assertNothingSent();
    });

    it('registers without a token only when the captcha is disabled', function () {
        config(['services.recaptcha.enabled' => false]);
        Http::fake();

        $this->post(route('register'), rcForm(['g-recaptcha-response' => null]))->assertRedirect(route('verification.notice'));

        expect(User::where('email', 'budi@example.com')->exists())->toBeTrue();
    });
});

describe('contact form (shared verifier)', function () {
    function rcContact(array $over = []): array
    {
        return array_merge(['name' => 'Siti', 'phone' => '081234567890', 'type' => 'Landing Page', 'message' => 'Halo', 'g-recaptcha-response' => 'tok'], $over);
    }

    it('stores the message when the captcha passes and rejects when it does not', function () {
        Http::fake([RC_URL => Http::sequence()->push(['success' => true])->push(['success' => false])]);

        $this->post(route('contact-messages.store'), rcContact());
        expect(ContactMessage::count())->toBe(1);

        $this->post(route('contact-messages.store'), rcContact())->assertSessionHasErrors('g-recaptcha-response');
        expect(ContactMessage::count())->toBe(1);
    });

    it('no longer crashes with a server error when Google is down', function () {
        Http::fake([RC_URL => fn () => throw new ConnectionException('down')]);

        $this->post(route('contact-messages.store'), rcContact())->assertSessionHasErrors('g-recaptcha-response');

        expect(ContactMessage::count())->toBe(0);
    });
});
