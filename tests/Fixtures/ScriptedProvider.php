<?php

namespace Tests\Fixtures;

use App\Services\Ai\AiProvider;
use App\Services\Ai\AiProviderException;
use App\Services\Ai\AiRequest;
use App\Services\Ai\AiResponse;

/** Provider palsu: mengembalikan/melempar hasil sesuai skrip, berurutan per panggilan. */
class ScriptedProvider implements AiProvider
{
    public int $calls = 0;
    public ?AiRequest $lastRequest = null;

    /** @param list<AiResponse|AiProviderException> $script */
    public function __construct(private string $name, private array $script)
    {
    }

    public static function ok(string $provider, string $text = 'halo', int $in = 100, int $out = 20): AiResponse
    {
        return new AiResponse($text, $provider, "{$provider}-model", $in, $out, 5, 'STOP');
    }

    public function name(): string
    {
        return $this->name;
    }

    public function model(): string
    {
        return "{$this->name}-model";
    }

    public function generate(AiRequest $request): AiResponse
    {
        $this->lastRequest = $request;
        $outcome = $this->script[min($this->calls, count($this->script) - 1)];
        $this->calls++;

        if ($outcome instanceof AiProviderException) {
            throw $outcome;
        }

        return $outcome;
    }
}
