<?php

namespace App\Services\Ai;

/**
 * Hasil penyusunan prompt. `stats` dipakai UI tes chatbot untuk memperingatkan tenant bila data terpotong.
 *
 * @phpstan-type Counts array{included: int, omitted: int}
 */
final class BuiltPrompt
{
    /** @param array{faqs: array{included: int, omitted: int}, services: array{included: int, omitted: int}, products: array{included: int, omitted: int}, chars: int, truncated: bool} $stats */
    public function __construct(
        public readonly AiRequest $request,
        public readonly array $stats,
    ) {
    }
}
