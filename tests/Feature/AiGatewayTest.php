<?php

use App\Models\AiCall;
use App\Models\Tenant;
use App\Services\Ai\AiGateway;
use App\Services\Ai\AiGatewayException;
use App\Services\Ai\AiProviderException as PE;
use App\Services\Ai\AiRequest;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\Fixtures\ScriptedProvider as P;

function gw(array $providers, array $o = []): AiGateway
{
    return new AiGateway(
        providers: $providers,
        maxOutputTokens: $o['max'] ?? 500,
        temperature: 0.3,
        retries: $o['retries'] ?? 1,
        retryDelayMs: 0,
        budgetSeconds: $o['budget'] ?? 45,
        pricing: $o['pricing'] ?? [],
    );
}

function req(?int $max = null): AiRequest
{
    return new AiRequest('sistem', [['role' => 'user', 'content' => 'rahasia pelanggan: 0812']], $max);
}

// ---- Alur dasar ----
it('returns the first provider answer', function () {
    $a = new P('gemini', [P::ok('gemini', 'jawaban A')]);
    $b = new P('groq', [P::ok('groq')]);

    $r = gw([$a, $b])->generate(req());

    expect($r->text)->toBe('jawaban A')->and($r->provider)->toBe('gemini')
        ->and($r->attempts)->toBe(1)->and($r->fallbackUsed)->toBeFalse()
        ->and($b->calls)->toBe(0);
});

it('retries a transient error on the same provider before falling back', function () {
    $a = new P('gemini', [PE::fromStatus('gemini', 503), P::ok('gemini', 'pulih')]);
    $b = new P('groq', [P::ok('groq')]);

    $r = gw([$a, $b])->generate(req());

    expect($r->provider)->toBe('gemini')->and($r->text)->toBe('pulih')
        ->and($r->attempts)->toBe(2)->and($r->fallbackUsed)->toBeFalse()->and($b->calls)->toBe(0);
});

it('falls back to the next provider after retries are exhausted', function () {
    $a = new P('gemini', [PE::timeout('gemini')]);
    $b = new P('groq', [P::ok('groq', 'dari groq')]);

    $r = gw([$a, $b])->generate(req());

    expect($a->calls)->toBe(2) // 1 percobaan + 1 retry
        ->and($r->provider)->toBe('groq')->and($r->fallbackUsed)->toBeTrue()->and($r->attempts)->toBe(3);
});

it('does not retry non-retryable errors but still falls back', function (PE $error) {
    $a = new P('gemini', [$error]);
    $b = new P('groq', [P::ok('groq')]);

    $r = gw([$a, $b])->generate(req());

    expect($a->calls)->toBe(1)->and($r->provider)->toBe('groq')->and($r->fallbackUsed)->toBeTrue();
})->with([
    'auth' => fn () => PE::fromStatus('gemini', 401),
    'bad request' => fn () => PE::fromStatus('gemini', 400),
    'blocked' => fn () => PE::blocked('gemini'),
]);

it('fails with a summary of every attempt when all providers fail', function () {
    $a = new P('gemini', [PE::fromStatus('gemini', 500)]);
    $b = new P('groq', [PE::fromStatus('groq', 429)]);

    try {
        gw([$a, $b])->generate(req());
        $this->fail('Expected exception');
    } catch (AiGatewayException $e) {
        expect($e->attempts)->toHaveCount(3) // gemini 500 diulang 1x; groq 429 tidak diulang
            ->and(array_column($e->attempts, 'provider'))->toBe(['gemini', 'gemini', 'groq'])
            ->and($e->lastType())->toBe('rate_limit')
            ->and($e->getMessage())->toContain('gemini:server_error')->not->toContain('rahasia');
    }
});

it('never returns a truncated answer: falls back to the next provider without retrying', function () {
    $a = new P('gemini', [PE::truncated('gemini')]);
    $b = new P('groq', [P::ok('groq', 'jawaban utuh')]);

    $r = gw([$a, $b])->generate(req());

    expect($a->calls)->toBe(1)->and($r->text)->toBe('jawaban utuh')->and($r->fallbackUsed)->toBeTrue();
});

it('does not retry a rate-limited provider on the same provider', function () {
    $a = new P('gemini', [PE::fromStatus('gemini', 429)]);
    $b = new P('groq', [P::ok('groq')]);

    $r = gw([$a, $b])->generate(req());

    expect($a->calls)->toBe(1)->and($r->provider)->toBe('groq');
});

it('fails clearly when no provider is configured', function () {
    expect(fn () => gw([])->generate(req()))->toThrow(AiGatewayException::class, 'No AI provider');
});

// ---- Batas ----
it('caps output tokens at the platform limit and applies defaults', function () {
    $a = new P('gemini', [P::ok('gemini')]);

    gw([$a], ['max' => 500])->generate(req(9999));
    expect($a->lastRequest->maxOutputTokens)->toBe(500)->and($a->lastRequest->temperature)->toBe(0.3);

    gw([$a], ['max' => 500])->generate(req(120));
    expect($a->lastRequest->maxOutputTokens)->toBe(120);

    gw([$a], ['max' => 500])->generate(req());
    expect($a->lastRequest->maxOutputTokens)->toBe(500);
});

it('stops trying once the total time budget is spent', function () {
    $a = new P('gemini', [PE::timeout('gemini')]);
    $b = new P('groq', [P::ok('groq')]);

    try {
        gw([$a, $b], ['budget' => 0])->generate(req());
        $this->fail('Expected exception');
    } catch (AiGatewayException $e) {
        expect($e->lastType())->toBe('budget_exceeded')->and($b->calls)->toBe(0);
    }
});

// ---- Biaya & pencatatan ----
it('estimates cost only when pricing is configured', function () {
    $a = new P('gemini', [P::ok('gemini', 'x', 1_000_000, 500_000)]);

    $with = gw([$a], ['pricing' => ['gemini' => ['input' => 0.30, 'output' => 2.50]]])->generate(req());
    $without = gw([$a])->generate(req());

    expect($with->costUsd)->toBe(1.55)->and($without->costUsd)->toBeNull();
});

describe('call log', function () {
    it('records tokens, latency and outcome per tenant — never the message content', function () {
        $tenant = Tenant::factory()->create();
        app(CurrentTenant::class)->set($tenant);
        $a = new P('gemini', [PE::fromStatus('gemini', 500)]);
        $b = new P('groq', [P::ok('groq', 'jawaban rahasia', 111, 22)]);

        gw([$a, $b], ['pricing' => ['groq' => ['input' => 1.0, 'output' => 2.0]]])->generate(req(), 'test');

        $row = AiCall::sole();
        expect($row->tenant_id)->toBe($tenant->id)->and($row->purpose)->toBe('test')
            ->and($row->provider)->toBe('groq')->and($row->status)->toBe('success')
            ->and($row->input_tokens)->toBe(111)->and($row->output_tokens)->toBe(22)
            ->and($row->attempts)->toBe(3)->and($row->fallback_used)->toBeTrue()
            ->and($row->cost_usd)->toBe('0.000155');

        $dump = json_encode($row->getAttributes());
        expect($dump)->not->toContain('rahasia')->not->toContain('0812');
    });

    it('records a failed call with the last error type', function () {
        $tenant = Tenant::factory()->create();
        app(CurrentTenant::class)->set($tenant);

        try {
            gw([new P('gemini', [PE::fromStatus('gemini', 401)])])->generate(req());
        } catch (AiGatewayException) {
        }

        $row = AiCall::sole();
        expect($row->status)->toBe('failed')->and($row->error_type)->toBe('auth')->and($row->input_tokens)->toBeNull();
    });

    it('keeps each tenant log separate', function () {
        [$a, $b] = [Tenant::factory()->create(), Tenant::factory()->create()];
        $ctx = app(CurrentTenant::class);

        $ctx->runAs($a, fn () => gw([new P('gemini', [P::ok('gemini')])])->generate(req()));
        $ctx->runAs($b, fn () => gw([new P('gemini', [P::ok('gemini')])])->generate(req()));

        $ctx->set($a);
        expect(AiCall::count())->toBe(1)->and(AiCall::withoutTenancy()->count())->toBe(2);
    });

    it('logs to the app log without a tenant context and writes no DB row or content', function () {
        Log::spy();

        gw([new P('gemini', [P::ok('gemini', 'jawaban rahasia')])])->generate(req());

        expect(AiCall::withoutTenancy()->count())->toBe(0);
        Log::shouldHaveReceived('info')->withArgs(fn ($m, $ctx) => $m === 'ai.call'
            && ! str_contains(json_encode($ctx), 'rahasia'))->once();
    });
});

// ---- Terintegrasi lewat container (config + Http) ----
describe('container wiring', function () {
    beforeEach(function () {
        config([
            'ai.order' => ['gemini', 'groq'],
            'ai.retries' => 0,
            'ai.retry_delay_ms' => 0,
            'ai.providers.gemini.api_key' => 'K-G',
            'ai.providers.gemini.base_url' => 'https://gemini.test',
            'ai.providers.groq.api_key' => 'K-Q',
            'ai.providers.groq.base_url' => 'https://groq.test',
        ]);
    });

    it('falls back from Gemini to Groq end-to-end', function () {
        Http::fake([
            'gemini.test/*' => Http::response('', 500),
            'groq.test/*' => Http::response(['choices' => [['message' => ['content' => 'dari groq'], 'finish_reason' => 'stop']], 'usage' => ['prompt_tokens' => 5, 'completion_tokens' => 2]]),
        ]);

        $r = app(AiGateway::class)->generate(req());

        expect($r->provider)->toBe('groq')->and($r->text)->toBe('dari groq')->and($r->fallbackUsed)->toBeTrue();
    });

    it('skips providers without an API key', function () {
        config(['ai.providers.gemini.api_key' => null]);
        Http::fake(['groq.test/*' => Http::response(['choices' => [['message' => ['content' => 'ok'], 'finish_reason' => 'stop']]])]);

        $r = app(AiGateway::class)->generate(req());

        expect($r->provider)->toBe('groq')->and($r->fallbackUsed)->toBeFalse();
        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'gemini.test'));
    });

    it('reports a missing configuration', function () {
        config(['ai.providers.gemini.api_key' => null, 'ai.providers.groq.api_key' => null]);

        expect(fn () => app(AiGateway::class)->generate(req()))->toThrow(AiGatewayException::class, 'No AI provider');
    });

    it('verifies configuration with the ai:ping command', function () {
        Http::fake(['gemini.test/*' => Http::response([
            'candidates' => [['content' => ['parts' => [['text' => 'siap']]], 'finishReason' => 'STOP']],
            'usageMetadata' => ['promptTokenCount' => 9, 'candidatesTokenCount' => 1],
        ])]);

        $this->artisan('ai:ping')->expectsOutputToContain('OK via gemini')->assertSuccessful();
    });

    it('makes ai:ping fail visibly when providers are down', function () {
        Http::fake(['*' => Http::response('', 500)]);

        $this->artisan('ai:ping')->assertFailed();
    });
});
