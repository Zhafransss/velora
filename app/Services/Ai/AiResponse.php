<?php

namespace App\Services\Ai;

final class AiResponse
{
    public function __construct(
        public readonly string $text,
        public readonly string $provider,
        public readonly string $model,
        public readonly ?int $inputTokens,
        public readonly ?int $outputTokens,
        public readonly int $latencyMs,
        public readonly ?string $finishReason = null,
        public readonly int $attempts = 1,
        public readonly bool $fallbackUsed = false,
        public readonly ?float $costUsd = null,
    ) {
    }

    public function with(int $attempts, bool $fallbackUsed, ?float $costUsd, int $totalLatencyMs): self
    {
        return new self(
            $this->text, $this->provider, $this->model, $this->inputTokens, $this->outputTokens,
            $totalLatencyMs, $this->finishReason, $attempts, $fallbackUsed, $costUsd,
        );
    }
}
