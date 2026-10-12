<?php

namespace App\Services\Ai;

interface AiProvider
{
    public function name(): string;

    public function model(): string;

    /** @throws AiProviderException galat yang sudah dinormalisasi (tanpa secret) */
    public function generate(AiRequest $request): AiResponse;
}
