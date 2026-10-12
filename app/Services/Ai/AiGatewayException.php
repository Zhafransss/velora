<?php

namespace App\Services\Ai;

use RuntimeException;

/** Semua provider gagal. Pemanggil memakai fallback_message bot; jangan tampilkan galat ini ke pelanggan. */
class AiGatewayException extends RuntimeException
{
    /** @param list<array{provider: string, type: string, retryable: bool}> $attempts */
    public function __construct(string $message, public readonly array $attempts = [])
    {
        parent::__construct($message);
    }

    public static function noProviderConfigured(): self
    {
        return new self('No AI provider is configured (missing API keys).');
    }

    /** @param list<array{provider: string, type: string, retryable: bool}> $attempts */
    public static function allFailed(array $attempts): self
    {
        $summary = collect($attempts)->map(fn ($a) => "{$a['provider']}:{$a['type']}")->implode(', ');

        return new self("All AI providers failed ({$summary}).", $attempts);
    }

    public function lastType(): ?string
    {
        return $this->attempts === [] ? null : $this->attempts[array_key_last($this->attempts)]['type'];
    }
}
