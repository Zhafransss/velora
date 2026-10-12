<?php

namespace App\Services\Ai;

use RuntimeException;

/**
 * Galat provider yang dinormalisasi. Pesan SENGAJA tidak memuat body respons, URL, atau header
 * (bisa mengandung secret atau isi pesan pelanggan).
 */
class AiProviderException extends RuntimeException
{
    public const TIMEOUT = 'timeout';
    public const RATE_LIMIT = 'rate_limit';
    public const AUTH = 'auth';
    public const BAD_REQUEST = 'bad_request';
    public const SERVER_ERROR = 'server_error';
    public const INVALID_RESPONSE = 'invalid_response';
    public const CONTENT_BLOCKED = 'content_blocked';
    public const TRUNCATED = 'truncated';

    public function __construct(
        public readonly string $provider,
        public readonly string $type,
        public readonly bool $retryable,
        public readonly ?int $httpStatus = null,
    ) {
        parent::__construct("{$provider}: {$type}" . ($httpStatus ? " (HTTP {$httpStatus})" : ''));
    }

    public static function timeout(string $provider): self
    {
        return new self($provider, self::TIMEOUT, true);
    }

    public static function invalidResponse(string $provider): self
    {
        return new self($provider, self::INVALID_RESPONSE, true);
    }

    public static function blocked(string $provider): self
    {
        return new self($provider, self::CONTENT_BLOCKED, false);
    }

    /** Jawaban terpotong karena batas token (mis. token berpikir menghabiskan anggaran). Jangan kirim ke pelanggan. */
    public static function truncated(string $provider): self
    {
        return new self($provider, self::TRUNCATED, false);
    }

    public static function fromStatus(string $provider, int $status): self
    {
        return match (true) {
            // Kuota habis tidak pulih dalam hitungan ms: jangan diulang di provider yang sama, langsung fallback.
            $status === 429 => new self($provider, self::RATE_LIMIT, false, $status),
            $status === 401 || $status === 403 => new self($provider, self::AUTH, false, $status),
            $status >= 500 => new self($provider, self::SERVER_ERROR, true, $status),
            default => new self($provider, self::BAD_REQUEST, false, $status),
        };
    }
}
