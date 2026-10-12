<?php

namespace App\Services\Ai;

/** Groq memakai API kompatibel OpenAI (chat completions). */
class GroqProvider extends HttpAiProvider
{
    public function name(): string
    {
        return 'groq';
    }

    protected function endpoint(): string
    {
        return '/v1/chat/completions';
    }

    protected function headers(): array
    {
        return ['Authorization' => 'Bearer ' . $this->apiKey];
    }

    protected function payload(AiRequest $request): array
    {
        $payload = [
            'model' => $this->model,
            'messages' => array_merge(
                [['role' => 'system', 'content' => $request->system]],
                $request->messages,
            ),
            'max_tokens' => $request->maxOutputTokens,
            'temperature' => $request->temperature,
        ];

        if ($this->reasoning) {
            $payload['reasoning_effort'] = $this->reasoning;
        }

        return $payload;
    }

    protected function parse(array $json): array
    {
        $choice = $json['choices'][0] ?? null;

        if ($choice === null) {
            throw AiProviderException::invalidResponse($this->name());
        }

        if (($choice['finish_reason'] ?? null) === 'content_filter') {
            throw AiProviderException::blocked($this->name());
        }

        if (($choice['finish_reason'] ?? null) === 'length') {
            throw AiProviderException::truncated($this->name());
        }

        return [
            'text' => (string) ($choice['message']['content'] ?? ''),
            'input' => $json['usage']['prompt_tokens'] ?? null,
            'output' => $json['usage']['completion_tokens'] ?? null,
            'finish' => $choice['finish_reason'] ?? null,
        ];
    }
}
