<?php

namespace App\Services\Ai;

class GeminiProvider extends HttpAiProvider
{
    public function name(): string
    {
        return 'gemini';
    }

    protected function endpoint(): string
    {
        return "/v1beta/models/{$this->model}:generateContent";
    }

    protected function headers(): array
    {
        return ['x-goog-api-key' => $this->apiKey];
    }

    protected function payload(AiRequest $request): array
    {
        return [
            'systemInstruction' => ['parts' => [['text' => $request->system]]],
            'contents' => array_map(fn (array $m) => [
                'role' => $m['role'] === 'assistant' ? 'model' : 'user',
                'parts' => [['text' => $m['content']]],
            ], $request->messages),
            'generationConfig' => [
                'maxOutputTokens' => $request->maxOutputTokens,
                'temperature' => $request->temperature,
            ],
        ];
    }

    protected function parse(array $json): array
    {
        // Diblokir filter keamanan: tidak ada kandidat, ada promptFeedback.blockReason.
        if (! empty($json['promptFeedback']['blockReason'])) {
            throw AiProviderException::blocked($this->name());
        }

        $candidate = $json['candidates'][0] ?? null;

        if ($candidate === null) {
            throw AiProviderException::invalidResponse($this->name());
        }

        if (($candidate['finishReason'] ?? null) === 'SAFETY') {
            throw AiProviderException::blocked($this->name());
        }

        $text = collect($candidate['content']['parts'] ?? [])->pluck('text')->filter()->implode('');

        return [
            'text' => $text,
            'input' => $json['usageMetadata']['promptTokenCount'] ?? null,
            'output' => $json['usageMetadata']['candidatesTokenCount'] ?? null,
            'finish' => $candidate['finishReason'] ?? null,
        ];
    }
}
