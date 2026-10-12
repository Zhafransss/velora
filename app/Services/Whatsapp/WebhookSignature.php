<?php

namespace App\Services\Whatsapp;

/**
 * Verifikasi webhook dari konektor: signature = HMAC-SHA256(secret, "<timestamp>.<body-mentah>"), header
 * X-Velora-Timestamp dan X-Velora-Signature. Menolak timestamp kedaluwarsa (anti replay) dan membandingkan
 * dengan waktu-konstan. Tanpa secret terkonfigurasi, SEMUA webhook ditolak (gagal tertutup).
 */
class WebhookSignature
{
    public function __construct(private ?string $secret, private int $toleranceSeconds)
    {
    }

    public static function fromConfig(): self
    {
        return new self(config('whatsapp.webhook_secret'), (int) config('whatsapp.webhook_tolerance_seconds'));
    }

    public function valid(string $body, ?string $timestamp, ?string $signature, ?int $now = null): bool
    {
        if (! $this->secret || $timestamp === null || $signature === null || ! ctype_digit($timestamp)) {
            return false;
        }

        if (abs(($now ?? time()) - (int) $timestamp) > $this->toleranceSeconds) {
            return false;
        }

        return hash_equals(hash_hmac('sha256', $timestamp . '.' . $body, $this->secret), strtolower($signature));
    }

    /** Dipakai tes dan alat uji untuk membuat tanda tangan yang sama dengan konektor. */
    public function sign(string $body, string $timestamp): string
    {
        return hash_hmac('sha256', $timestamp . '.' . $body, (string) $this->secret);
    }
}
