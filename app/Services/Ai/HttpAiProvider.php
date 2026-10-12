<?php

namespace App\Services\Ai;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/** Kerangka bersama adapter HTTP: pengiriman, pemetaan galat, dan pengukuran latensi. */
abstract class HttpAiProvider implements AiProvider
{
    public function __construct(
        protected string $apiKey,
        protected string $model,
        protected string $baseUrl,
        protected int $timeout,
    ) {
    }

    abstract protected function endpoint(): string;

    /** @return array<string, string> header autentikasi — jangan taruh secret di URL/query. */
    abstract protected function headers(): array;

    abstract protected function payload(AiRequest $request): array;

    /**
     * @return array{text: string, input: ?int, output: ?int, finish: ?string}
     *
     * @throws AiProviderException
     */
    abstract protected function parse(array $json): array;

    public function model(): string
    {
        return $this->model;
    }

    public function generate(AiRequest $request): AiResponse
    {
        $started = hrtime(true);

        try {
            $response = Http::baseUrl($this->baseUrl)
                ->withHeaders($this->headers())
                ->acceptJson()
                ->asJson()
                ->connectTimeout(5)
                ->timeout($this->timeout)
                ->post($this->endpoint(), $this->payload($request));
        } catch (ConnectionException) {
            throw AiProviderException::timeout($this->name());
        }

        if ($response->failed()) {
            throw AiProviderException::fromStatus($this->name(), $response->status());
        }

        $json = $response->json();

        if (! is_array($json)) {
            throw AiProviderException::invalidResponse($this->name());
        }

        $parsed = $this->parse($json);

        if (trim($parsed['text']) === '') {
            throw AiProviderException::invalidResponse($this->name());
        }

        return new AiResponse(
            text: trim($parsed['text']),
            provider: $this->name(),
            model: $this->model,
            inputTokens: $parsed['input'],
            outputTokens: $parsed['output'],
            latencyMs: (int) ((hrtime(true) - $started) / 1_000_000),
            finishReason: $parsed['finish'],
        );
    }
}
