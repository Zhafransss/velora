<?php

use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/** Email yang benar-benar dikirim lewat mailer "array" (tanpa jaringan), lengkap dengan header. */
function sentMails(): array
{
    return Mail::mailer('array')->getSymfonyTransport()->messages()->map(fn ($m) => $m->getOriginalMessage())->all();
}

beforeEach(function () {
    config([
        'mail.default' => 'array',
        'mail.from.address' => 'team@veloradigitalworks.com',
        'mail.from.name' => 'Velora Digital',
    ]);
});

function extractUrl(string $body, string $needle): string
{
    preg_match('#https?://[^\s"<>\]\)]+' . preg_quote($needle, '#') . '[^\s"<>\]\)]*#', html_entity_decode($body), $m);

    return $m[0] ?? '';
}

// ---- Isi & pengirim ----
describe('verification email', function () {
    it('is sent from the configured sender to the new user, in Indonesian', function () {
        $this->post('/register', [
            'name' => 'Budi', 'workspace_name' => 'Toko Maju', 'email' => 'budi@example.com',
            'password' => 'rahasia-123', 'password_confirmation' => 'rahasia-123',
        ])->assertRedirect(route('verification.notice'));

        $mails = sentMails();
        expect($mails)->toHaveCount(1);

        $mail = $mails[0];
        expect($mail->getFrom()[0]->getAddress())->toBe('team@veloradigitalworks.com')
            ->and($mail->getFrom()[0]->getName())->toBe('Velora Digital')
            ->and($mail->getTo()[0]->getAddress())->toBe('budi@example.com')
            ->and($mail->getSubject())->toBe('Verifikasi email Anda — Velora Digital');

        $html = (string) $mail->getHtmlBody();
        expect($html)->toContain('Halo, Budi!')->toContain('Verifikasi Email')->toContain('Salam,')->toContain('Hak cipta dilindungi.')
            ->not->toContain('Hello')->not->toContain('Regards')->not->toContain('trouble clicking')->not->toContain('All rights reserved');
    });

    it('contains a working signed link that verifies the account', function () {
        $this->post('/register', [
            'name' => 'Budi', 'workspace_name' => 'Toko Maju', 'email' => 'budi@example.com',
            'password' => 'rahasia-123', 'password_confirmation' => 'rahasia-123',
        ]);
        $user = User::where('email', 'budi@example.com')->firstOrFail();
        expect($user->hasVerifiedEmail())->toBeFalse();

        $url = extractUrl((string) sentMails()[0]->getHtmlBody(), '/email/verify/');
        expect($url)->toContain("/email/verify/{$user->id}/")->toContain('signature=')->toContain('expires=');

        $this->actingAs($user)->get($url)->assertRedirect(route('app.dashboard'));
        expect($user->fresh()->hasVerifiedEmail())->toBeTrue();

        // Tautan yang diubah satu karakter ditolak.
        $other = User::factory()->unverified()->create();
        $this->actingAs($other)->get(str_replace("/{$user->id}/", "/{$other->id}/", $url))->assertForbidden();
    });

    it('also has a plain-text part', function () {
        (new User(['name' => 'Siti', 'email' => 's@example.com']))->forceFill(['id' => 5])->sendEmailVerificationNotification();

        expect((string) sentMails()[0]->getTextBody())->toContain('Verifikasi Email')->toContain('Hak cipta dilindungi.');
    });

    it('uses an Indonesian reset-password email with a token link', function () {
        $user = User::factory()->create(['name' => 'Siti', 'email' => 'siti@example.com']);

        $this->post('/forgot-password', ['email' => 'siti@example.com'])->assertSessionHas('status');

        $mail = sentMails()[0];
        $html = (string) $mail->getHtmlBody();

        expect($mail->getSubject())->toBe('Atur ulang password Anda — Velora Digital')
            ->and($mail->getFrom()[0]->getAddress())->toBe('team@veloradigitalworks.com')
            ->and($mail->getTo()[0]->getAddress())->toBe('siti@example.com')
            ->and($html)->toContain('Atur Ulang Password')->toContain('Halo, Siti!')->not->toContain('Reset Password');

        $url = extractUrl($html, '/reset-password/');
        expect($url)->toContain('email=siti%40example.com');

        // Tautan membuka form reset.
        $this->get($url)->assertOk()->assertSee('Atur password baru');
    });
});

// ---- Kegagalan SMTP ----
describe('when the mail server is unavailable', function () {
    beforeEach(function () {
        // Port 1 menolak koneksi seketika: meniru SMTP mati/salah konfigurasi tanpa jaringan sungguhan.
        config([
            'mail.default' => 'smtp',
            'mail.mailers.smtp.host' => '127.0.0.1', 'mail.mailers.smtp.port' => 1,
            'mail.mailers.smtp.scheme' => null, 'mail.mailers.smtp.timeout' => 2,
        ]);
    });

    it('still creates the account and tells the user honestly instead of failing with a 500', function () {
        Log::spy();

        $response = $this->post('/register', [
            'name' => 'Budi', 'workspace_name' => 'Toko Maju', 'email' => 'budi@example.com',
            'password' => 'rahasia-123', 'password_confirmation' => 'rahasia-123',
        ]);

        $response->assertRedirect(route('verification.notice'))->assertSessionHas('mail_error', true);

        $user = User::where('email', 'budi@example.com')->firstOrFail();
        $this->assertAuthenticatedAs($user);
        expect($user->tenants()->count())->toBe(1);

        $this->get(route('verification.notice'))
            ->assertOk()->assertSee('belum dapat dikirim')->assertSee('Kirim ulang')->assertDontSee('Kami telah mengirim');

        Log::shouldHaveReceived('error')->withArgs(fn ($m, $ctx) => $m === 'mail.verification.failed' && isset($ctx['error']))->once();
    });

    it('reports a failed resend without an error page, and shows no false success', function () {
        $user = User::factory()->unverified()->create();

        $this->actingAs($user)->from(route('verification.notice'))->post(route('verification.send'))
            ->assertRedirect(route('verification.notice'))->assertSessionHas('mail_error', true)->assertSessionMissing('status');
    });
});

it('shows the normal notice and a success message when sending works', function () {
    $user = User::factory()->unverified()->create();

    $this->actingAs($user)->get(route('verification.notice'))->assertOk()->assertSee('Kami telah mengirim')->assertSee('Spam');

    $this->actingAs($user)->from(route('verification.notice'))->post(route('verification.send'))
        ->assertSessionHas('status', 'Tautan verifikasi baru telah dikirim.');
    expect(sentMails())->toHaveCount(1);
});

// ---- mail:test ----
describe('mail:test command', function () {
    it('sends a test email and reports the configuration without the password', function () {
        config(['mail.mailers.smtp.password' => 'SUPER-RAHASIA', 'mail.mailers.smtp.username' => 'team@veloradigitalworks.com']);

        $this->artisan('mail:test', ['to' => 'tujuan@example.com'])->expectsOutputToContain('terkirim ke tujuan@example.com')->assertSuccessful();

        $mail = sentMails()[0];
        expect($mail->getTo()[0]->getAddress())->toBe('tujuan@example.com')->and($mail->getFrom()[0]->getAddress())->toBe('team@veloradigitalworks.com')
            ->and($mail->getSubject())->toBe('Tes email — Velora Digital');
    });

    it('fails clearly on an invalid address', function () {
        $this->artisan('mail:test', ['to' => 'bukan-email'])->assertFailed();
        expect(sentMails())->toHaveCount(0);
    });

    it('warns when the mailer is only logging', function () {
        config(['mail.default' => 'log']);

        $this->artisan('mail:test', ['to' => 'a@example.com'])->expectsOutputToContain('TIDAK dikirim sungguhan');
    });

    it('never prints the password, even when the server error contains it', function () {
        config([
            'mail.default' => 'smtp', 'mail.mailers.smtp.host' => '127.0.0.1', 'mail.mailers.smtp.port' => 1,
            'mail.mailers.smtp.scheme' => null, 'mail.mailers.smtp.timeout' => 2, 'mail.mailers.smtp.password' => 'SUPER-RAHASIA',
        ]);

        $this->artisan('mail:test', ['to' => 'a@example.com'])->doesntExpectOutputToContain('SUPER-RAHASIA')->assertFailed();
    });

    it('says when the password is not filled in', function () {
        config(['mail.default' => 'smtp', 'mail.mailers.smtp.host' => '127.0.0.1', 'mail.mailers.smtp.port' => 1, 'mail.mailers.smtp.scheme' => null,
            'mail.mailers.smtp.password' => null, 'mail.mailers.smtp.timeout' => 2]);

        $this->artisan('mail:test', ['to' => 'a@example.com'])->expectsOutputToContain('BELUM DIISI')->assertFailed();
    });
});
