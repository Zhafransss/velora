<?php

namespace App\Services\Ai\Accuracy;

use App\Models\Bot;
use App\Models\CatalogService;
use App\Models\Faq;
use App\Models\Product;
use App\Models\Tenant;
use App\Services\Ai\AiGateway;
use App\Services\Ai\AiGatewayException;
use App\Services\Ai\PromptBuilder;
use App\Support\Tenancy\CurrentTenant;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Menjalankan kasus uji terhadap bot sungguhan (builder + gateway) pada tenant sementara.
 * Semua data uji dibuat di dalam transaksi dan SELALU di-rollback — database tidak tercemari.
 */
class AccuracyRunner
{
    public function __construct(
        private PromptBuilder $builder,
        private AiGateway $gateway,
        private AnswerChecker $checker,
    ) {
    }

    /**
     * @param  array<string, mixed>  $dataset
     * @param  list<string>  $onlyCategories  kosong = semua
     * @param  callable(string $caseId, int $run, bool $passed): void|null  $progress
     */
    public function run(array $dataset, int $runs = 1, array $onlyCategories = [], int $sleepMs = 0, ?callable $progress = null): AccuracyReport
    {
        self::validate($dataset);

        $cases = array_values(array_filter(
            $dataset['cases'],
            fn ($c) => $onlyCategories === [] || in_array($c['category'], $onlyCategories, true),
        ));

        $context = app(CurrentTenant::class);
        $previous = $context->get();

        DB::beginTransaction();

        try {
            [$tenant, $bot] = $this->seed($dataset['business']);
            $context->set($tenant);

            $results = [];

            foreach ($cases as $case) {
                $caseRuns = [];

                for ($i = 1; $i <= $runs; $i++) {
                    $run = $this->runCase($bot->fresh(), $case, $dataset['now'] ?? null, $tenant->timezone);
                    $caseRuns[] = $run;
                    $progress && $progress($case['id'], $i, $run['passed']);
                    $sleepMs > 0 && usleep($sleepMs * 1000);
                }

                $results[] = [
                    'id' => $case['id'],
                    'category' => $case['category'],
                    'critical' => (bool) ($case['critical'] ?? false),
                    'question' => $case['question'],
                    'runs' => $caseRuns,
                ];
            }

            return new AccuracyReport($dataset['name'] ?? 'dataset', $results, $runs);
        } finally {
            DB::rollBack();
            $context->set($previous);
        }
    }

    /** @throws InvalidArgumentException */
    public static function validate(array $dataset): void
    {
        foreach (['business', 'cases'] as $key) {
            if (empty($dataset[$key]) || ! is_array($dataset[$key])) {
                throw new InvalidArgumentException("Dataset requires '{$key}'.");
            }
        }

        $ids = [];

        foreach ($dataset['cases'] as $i => $case) {
            foreach (['id', 'category', 'question', 'expect'] as $key) {
                if (empty($case[$key])) {
                    throw new InvalidArgumentException("Case #{$i} is missing '{$key}'.");
                }
            }

            if (in_array($case['id'], $ids, true)) {
                throw new InvalidArgumentException("Duplicate case id '{$case['id']}'.");
            }

            $ids[] = $case['id'];

            $rules = array_intersect(array_keys($case['expect']), ['all_of', 'any_of', 'none_of', 'regex_none', 'admits_unknown']);

            if ($rules === []) {
                throw new InvalidArgumentException("Case '{$case['id']}' has no expectation rules.");
            }
        }
    }

    /** @return array{0: Tenant, 1: Bot} */
    private function seed(array $business): array
    {
        $tenant = Tenant::create([
            'name' => $business['name'],
            'slug' => 'accuracy-' . Str::lower(Str::random(10)),
            'timezone' => $business['timezone'] ?? 'Asia/Jakarta',
        ]);

        if (! empty($business['profile'])) {
            $tenant->businessProfile()->create($business['profile']);
        }

        // Konteks tenant dibutuhkan oleh model ber-BelongsToTenant saat menyimpan.
        app(CurrentTenant::class)->runAs($tenant, function () use ($business, &$bot) {
            $bot = Bot::create(($business['bot'] ?? []) + Bot::defaults());

            foreach ($business['products'] ?? [] as $row) {
                Product::create($row);
            }
            foreach ($business['services'] ?? [] as $row) {
                CatalogService::create($row);
            }
            foreach ($business['faqs'] ?? [] as $row) {
                Faq::create($row);
            }
        });

        return [$tenant, $bot];
    }

    private function runCase(Bot $bot, array $case, ?string $datasetNow, string $timezone): array
    {
        $now = ($case['now'] ?? $datasetNow) ? Carbon::parse($case['now'] ?? $datasetNow, $timezone) : null;

        try {
            $built = $this->builder->build($bot, $case['question'], $case['history'] ?? [], $now);
            $answer = $this->gateway->generate($built->request, 'test');
        } catch (AiGatewayException $e) {
            return [
                'answer' => null, 'passed' => false, 'reasons' => ['Panggilan AI gagal: ' . ($e->lastType() ?? 'unknown')],
                'latency_ms' => null, 'input_tokens' => null, 'output_tokens' => null, 'cost_usd' => null, 'fallback' => false,
            ];
        }

        $check = $this->checker->check($answer->text, $case['expect']);

        return [
            'answer' => $answer->text,
            'passed' => $check['passed'],
            'reasons' => $check['reasons'],
            'latency_ms' => $answer->latencyMs,
            'input_tokens' => $answer->inputTokens,
            'output_tokens' => $answer->outputTokens,
            'cost_usd' => $answer->costUsd,
            'fallback' => $answer->fallbackUsed,
        ];
    }
}
