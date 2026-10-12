<?php

namespace App\Services\Ai;

use App\Models\AiCall;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Support\Facades\Log;

/**
 * Satu pintu ke LLM (FR-007): batas token, retry terbatas per provider, fallback antarprovider,
 * batas waktu total, normalisasi galat, dan pencatatan token/biaya tanpa isi pesan.
 */
class AiGateway
{
    /**
     * @param  list<AiProvider>  $providers  urutan prioritas
     * @param  array<string, array{input: ?float, output: ?float}>  $pricing  USD per 1 juta token
     */
    public function __construct(
        private array $providers,
        private int $maxOutputTokens,
        private float $temperature,
        private int $retries,
        private int $retryDelayMs,
        private int $budgetSeconds,
        private array $pricing = [],
    ) {
    }

    /** @throws AiGatewayException */
    public function generate(AiRequest $request, string $purpose = 'chat'): AiResponse
    {
        if ($this->providers === []) {
            throw AiGatewayException::noProviderConfigured();
        }

        // Permintaan tidak boleh melampaui batas platform.
        $request = $request->withLimits(
            min($request->maxOutputTokens ?? $this->maxOutputTokens, $this->maxOutputTokens),
            $request->temperature ?? $this->temperature,
        );

        $started = hrtime(true);
        $deadline = $started + $this->budgetSeconds * 1_000_000_000;
        $failures = [];
        $attempts = 0;
        $lastProvider = null;

        foreach ($this->providers as $index => $provider) {
            $lastProvider = $provider;

            for ($try = 0; $try <= $this->retries; $try++) {
                if ($attempts > 0 && hrtime(true) >= $deadline) {
                    $failures[] = ['provider' => $provider->name(), 'type' => 'budget_exceeded', 'retryable' => false];
                    break 2;
                }

                $attempts++;

                try {
                    $response = $provider->generate($request);
                } catch (AiProviderException $e) {
                    $failures[] = ['provider' => $e->provider, 'type' => $e->type, 'retryable' => $e->retryable];

                    if (! $e->retryable) {
                        break; // langsung ke provider berikutnya
                    }

                    if ($try < $this->retries && $this->retryDelayMs > 0) {
                        usleep($this->retryDelayMs * 1000 * ($try + 1));
                    }

                    continue;
                }

                $totalMs = (int) ((hrtime(true) - $started) / 1_000_000);
                $final = $response->with($attempts, $index > 0, $this->cost($response), $totalMs);
                $this->record($purpose, $final, null);

                return $final;
            }
        }

        $exception = AiGatewayException::allFailed($failures);
        $this->recordFailure($purpose, $lastProvider, $attempts, (int) ((hrtime(true) - $started) / 1_000_000), $exception);

        throw $exception;
    }

    private function cost(AiResponse $r): ?float
    {
        $price = $this->pricing[$r->provider] ?? null;

        if (! $price || $price['input'] === null || $price['output'] === null || $r->inputTokens === null || $r->outputTokens === null) {
            return null;
        }

        return round(($r->inputTokens * $price['input'] + $r->outputTokens * $price['output']) / 1_000_000, 6);
    }

    private function record(string $purpose, AiResponse $r, ?string $error): void
    {
        $this->persist([
            'purpose' => $purpose, 'provider' => $r->provider, 'model' => $r->model, 'status' => 'success',
            'input_tokens' => $r->inputTokens, 'output_tokens' => $r->outputTokens, 'latency_ms' => $r->latencyMs,
            'attempts' => $r->attempts, 'fallback_used' => $r->fallbackUsed, 'error_type' => $error, 'cost_usd' => $r->costUsd,
        ]);
    }

    private function recordFailure(string $purpose, ?AiProvider $provider, int $attempts, int $latencyMs, AiGatewayException $e): void
    {
        $this->persist([
            'purpose' => $purpose, 'provider' => $provider?->name(), 'model' => $provider?->model(), 'status' => 'failed',
            'latency_ms' => $latencyMs, 'attempts' => $attempts, 'fallback_used' => count($this->providers) > 1,
            'error_type' => $e->lastType(),
        ]);
    }

    /** Tanpa konteks tenant (mis. `ai:ping`) hanya dicatat ke log aplikasi. Isi pesan tidak pernah dicatat. */
    private function persist(array $row): void
    {
        if (app(CurrentTenant::class)->id() === null) {
            Log::info('ai.call', $row);

            return;
        }

        try {
            AiCall::create($row);
        } catch (\Throwable $e) {
            // Gagal mencatat tidak boleh menggagalkan jawaban ke pelanggan.
            Log::warning('ai.call.record_failed', ['error' => $e::class]);
        }
    }
}
