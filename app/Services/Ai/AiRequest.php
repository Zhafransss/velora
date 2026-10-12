<?php

namespace App\Services\Ai;

use InvalidArgumentException;

/**
 * Permintaan netral-provider. `system` adalah satu-satunya tempat instruksi tepercaya;
 * pesan pelanggan hanya masuk lewat `messages` dengan role user (anti prompt injection, FR-007).
 */
final class AiRequest
{
    /** @param list<array{role: 'user'|'assistant', content: string}> $messages */
    public function __construct(
        public readonly string $system,
        public readonly array $messages,
        public readonly ?int $maxOutputTokens = null,
        public readonly ?float $temperature = null,
    ) {
        if ($messages === []) {
            throw new InvalidArgumentException('AiRequest requires at least one message.');
        }

        foreach ($messages as $m) {
            if (! in_array($m['role'] ?? null, ['user', 'assistant'], true) || ! is_string($m['content'] ?? null)) {
                throw new InvalidArgumentException('Each message needs role user|assistant and string content.');
            }
        }

        if (end($messages)['role'] !== 'user') {
            throw new InvalidArgumentException('The last message must come from the user.');
        }
    }

    public function withLimits(int $maxOutputTokens, float $temperature): self
    {
        return new self($this->system, $this->messages, $maxOutputTokens, $temperature);
    }
}
