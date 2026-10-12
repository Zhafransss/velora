<?php

namespace App\Services\Security;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Verifikasi Google reCAPTCHA v2 di server (FR-001). Satu implementasi dipakai form daftar dan form kontak.
 *
 * Gagal-tertutup (fail closed): bila diaktifkan tetapi secret belum diisi, atau Google tidak dapat dihubungi, token
 * DITOLAK. Alternatifnya (meloloskan) membuat captcha bisa dilewati hanya dengan memutus koneksi/menghapus konfigurasi.
 * Harga yang dibayar: bila Google down, pendaftaran ikut tertunda sebentar — wajar untuk form publik.
 */
class RecaptchaVerifier
{
    private const ENDPOINT = 'https://www.google.com/recaptcha/api/siteverify';

    public function enabled(): bool
    {
        return (bool) config('services.recaptcha.enabled');
    }

    /** Widget hanya ditampilkan bila diaktifkan DAN site key tersedia. */
    public function siteKey(): ?string
    {
        return $this->enabled() ? (config('services.recaptcha.site_key') ?: null) : null;
    }

    public function verify(?string $token, ?string $ip = null): bool
    {
        if (! $this->enabled()) {
            return true;
        }

        $secret = config('services.recaptcha.secret_key');

        if (! $secret) {
            Log::error('recaptcha.misconfigured', ['reason' => 'secret key missing while enabled']);

            return false;
        }

        if (! is_string($token) || $token === '') {
            return false;
        }

        try {
            $result = Http::asForm()->timeout(5)->post(self::ENDPOINT, array_filter([
                'secret' => $secret,
                'response' => $token,
                'remoteip' => $ip,
            ]))->json();
        } catch (ConnectionException $e) {
            Log::warning('recaptcha.unreachable', ['error' => $e::class]);

            return false;
        }

        // Jangan mencatat token atau respons penuh; cukup hasil dan kode galat Google.
        if (! data_get($result, 'success')) {
            Log::info('recaptcha.rejected', ['codes' => data_get($result, 'error-codes', [])]);

            return false;
        }

        return true;
    }
}
