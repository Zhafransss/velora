<?php

use App\Services\Ai\AiProviderException;
use App\Services\Ai\AiRequest;
use App\Services\Ai\GeminiProvider;
use App\Services\Ai\GroqProvider;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

function aiRequest(): AiRequest
{
    return new AiRequest(
        system: 'Kamu asisten toko.',
        messages: [
            ['role' => 'user', 'content' => 'Halo'],
            ['role' => 'assistant', 'content' => 'Hai, ada yang bisa dibantu?'],
            ['role' => 'user', 'content' => 'Berapa harga kopi?'],
        ],
        maxOutputTokens: 300,
        temperature: 0.2,
    );
}

function gemini(): GeminiProvider
{
    return new GeminiProvider('SECRET-GEMINI-KEY', 'gemini-test', 'https://gemini.test', 5);
}

function groq(): GroqProvider
{
    return new GroqProvider('SECRET-GROQ-KEY', 'groq-test', 'https://groq.test', 5);
}

function geminiOk(string $text = 'Kopi Rp25.000'): array
{
    return [
        'candidates' => [['content' => ['parts' => [['text' => $text]]], 'finishReason' => 'STOP']],
        'usageMetadata' => ['promptTokenCount' => 120, 'candidatesTokenCount' => 15],
    ];
}

function groqOk(string $text = 'Kopi Rp25.000'): array
{
    return [
        'choices' => [['message' => ['content' => $text], 'finish_reason' => 'stop']],
        'usage' => ['prompt_tokens' => 130, 'completion_tokens' => 17],
    ];
}

// ---- AiRequest ----
it('validates AiRequest messages', function () {
    expect(fn () => new AiRequest('s', []))->toThrow(InvalidArgumentException::class)
        ->and(fn () => new AiRequest('s', [['role' => 'system', 'content' => 'x']]))->toThrow(InvalidArgumentException::class)
        ->and(fn () => new AiRequest('s', [['role' => 'user', 'content' => 'a'], ['role' => 'assistant', 'content' => 'b']]))
        ->toThrow(InvalidArgumentException::class);
});

// ---- Gemini ----
describe('Gemini adapter', function () {
    it('sends a correctly shaped request with the key in a header, not the URL', function () {
        Http::fake(['gemini.test/*' => Http::response(geminiOk())]);

        gemini()->generate(aiRequest());

        Http::assertSent(function (Request $r) {
            $body = $r->data();

            return $r->url() === 'https://gemini.test/v1beta/models/gemini-test:generateContent'
                && $r->hasHeader('x-goog-api-key', 'SECRET-GEMINI-KEY')
                && ! str_contains($r->url(), 'SECRET')
                && $body['systemInstruction']['parts'][0]['text'] === 'Kamu asisten toko.'
                && array_column($body['contents'], 'role') === ['user', 'model', 'user']
                && $body['generationConfig']['maxOutputTokens'] === 300
                && $body['generationConfig']['temperature'] === 0.2;
        });
    });

    it('parses text, tokens and finish reason', function () {
        Http::fake(['gemini.test/*' => Http::response(geminiOk('  Jawaban  '))]);

        $r = gemini()->generate(aiRequest());

        expect($r->text)->toBe('Jawaban')->and($r->provider)->toBe('gemini')->and($r->model)->toBe('gemini-test')
            ->and($r->inputTokens)->toBe(120)->and($r->outputTokens)->toBe(15)->and($r->finishReason)->toBe('STOP');
    });

    it('maps failures to normalized errors', function (int $status, string $type, bool $retryable) {
        Http::fake(['gemini.test/*' => Http::response(['error' => ['message' => 'SECRET-GEMINI-KEY bocor?']], $status)]);

        try {
            gemini()->generate(aiRequest());
            $this->fail('Expected exception');
        } catch (AiProviderException $e) {
            expect($e->type)->toBe($type)->and($e->retryable)->toBe($retryable)->and($e->httpStatus)->toBe($status)
                ->and($e->getMessage())->not->toContain('SECRET')->not->toContain('bocor');
        }
    })->with([
        [400, 'bad_request', false],
        [401, 'auth', false],
        [403, 'auth', false],
        [429, 'rate_limit', true],
        [500, 'server_error', true],
        [503, 'server_error', true],
    ]);

    it('maps a connection failure to timeout', function () {
        Http::fake(fn () => throw new ConnectionException('cURL error 28 https://gemini.test?key=SECRET'));

        try {
            gemini()->generate(aiRequest());
            $this->fail('Expected exception');
        } catch (AiProviderException $e) {
            expect($e->type)->toBe('timeout')->and($e->retryable)->toBeTrue()->and($e->getMessage())->not->toContain('SECRET');
        }
    });

    it('treats safety blocks as non-retryable content_blocked', function (array $payload) {
        Http::fake(['gemini.test/*' => Http::response($payload)]);

        expect(fn () => gemini()->generate(aiRequest()))
            ->toThrow(fn (AiProviderException $e) => $e->type === 'content_blocked' && ! $e->retryable);
    })->with([
        'prompt blocked' => [['promptFeedback' => ['blockReason' => 'SAFETY']]],
        'candidate blocked' => [['candidates' => [['finishReason' => 'SAFETY']]]],
    ]);

    it('treats empty or malformed bodies as retryable invalid_response', function (mixed $body) {
        Http::fake(['gemini.test/*' => Http::response($body)]);

        expect(fn () => gemini()->generate(aiRequest()))
            ->toThrow(fn (AiProviderException $e) => $e->type === 'invalid_response' && $e->retryable);
    })->with([
        'no candidates' => [['candidates' => []]],
        'empty text' => [['candidates' => [['content' => ['parts' => [['text' => '  ']]]]]]],
        'not json' => ['<html>oops</html>'],
    ]);
});

// ---- Groq ----
describe('Groq adapter', function () {
    it('sends an OpenAI-style request with a bearer token and system message first', function () {
        Http::fake(['groq.test/*' => Http::response(groqOk())]);

        groq()->generate(aiRequest());

        Http::assertSent(function (Request $r) {
            $b = $r->data();

            return $r->url() === 'https://groq.test/v1/chat/completions'
                && $r->hasHeader('Authorization', 'Bearer SECRET-GROQ-KEY')
                && $b['model'] === 'groq-test'
                && $b['messages'][0] === ['role' => 'system', 'content' => 'Kamu asisten toko.']
                && array_column($b['messages'], 'role') === ['system', 'user', 'assistant', 'user']
                && $b['max_tokens'] === 300;
        });
    });

    it('parses text and tokens', function () {
        Http::fake(['groq.test/*' => Http::response(groqOk())]);

        $r = groq()->generate(aiRequest());

        expect($r->text)->toBe('Kopi Rp25.000')->and($r->provider)->toBe('groq')
            ->and($r->inputTokens)->toBe(130)->and($r->outputTokens)->toBe(17)->and($r->finishReason)->toBe('stop');
    });

    it('maps failures to normalized errors', function (int $status, string $type, bool $retryable) {
        Http::fake(['groq.test/*' => Http::response('SECRET-GROQ-KEY body', $status)]);

        try {
            groq()->generate(aiRequest());
            $this->fail('Expected exception');
        } catch (AiProviderException $e) {
            expect($e->type)->toBe($type)->and($e->retryable)->toBe($retryable)->and($e->getMessage())->not->toContain('SECRET');
        }
    })->with([
        [400, 'bad_request', false],
        [401, 'auth', false],
        [429, 'rate_limit', true],
        [502, 'server_error', true],
    ]);

    it('handles content filter and empty responses', function () {
        Http::fake(['groq.test/*' => Http::sequence()
            ->push(['choices' => [['message' => ['content' => ''], 'finish_reason' => 'content_filter']]])
            ->push(['choices' => []])]);

        expect(fn () => groq()->generate(aiRequest()))->toThrow(fn (AiProviderException $e) => $e->type === 'content_blocked');
        expect(fn () => groq()->generate(aiRequest()))->toThrow(fn (AiProviderException $e) => $e->type === 'invalid_response');
    });

    it('maps a connection failure to timeout', function () {
        Http::fake(fn () => throw new ConnectionException('timed out'));

        expect(fn () => groq()->generate(aiRequest()))->toThrow(fn (AiProviderException $e) => $e->type === 'timeout');
    });
});
