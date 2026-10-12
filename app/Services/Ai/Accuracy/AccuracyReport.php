<?php

namespace App\Services\Ai\Accuracy;

/**
 * Hasil uji akurasi.
 *
 * @phpstan-type Run array{answer: ?string, passed: bool, reasons: list<string>, latency_ms: ?int, input_tokens: ?int, output_tokens: ?int, cost_usd: ?float, fallback: bool}
 */
class AccuracyReport
{
    /**
     * @param  list<array{id: string, category: string, critical: bool, question: string, runs: list<array>}>  $cases
     */
    public function __construct(
        public readonly string $datasetName,
        public readonly array $cases,
        public readonly int $runsPerCase,
    ) {
    }

    public function totalRuns(): int
    {
        return array_sum(array_map(fn ($c) => count($c['runs']), $this->cases));
    }

    public function passedRuns(): int
    {
        return array_sum(array_map(fn ($c) => count(array_filter($c['runs'], fn ($r) => $r['passed'])), $this->cases));
    }

    public function accuracy(): float
    {
        return $this->totalRuns() === 0 ? 0.0 : round($this->passedRuns() / $this->totalRuns() * 100, 1);
    }

    /** @return array<string, array{passed: int, total: int, rate: float}> */
    public function byCategory(): array
    {
        $out = [];

        foreach ($this->cases as $case) {
            $cat = $case['category'];
            $out[$cat] ??= ['passed' => 0, 'total' => 0, 'rate' => 0.0];

            foreach ($case['runs'] as $run) {
                $out[$cat]['total']++;
                $out[$cat]['passed'] += $run['passed'] ? 1 : 0;
            }
        }

        foreach ($out as &$row) {
            $row['rate'] = $row['total'] === 0 ? 0.0 : round($row['passed'] / $row['total'] * 100, 1);
        }

        ksort($out);

        return $out;
    }

    /** Kasus kritis (halusinasi/keamanan) yang gagal pada setidaknya satu run. */
    public function criticalFailures(): array
    {
        return array_values(array_filter($this->cases, fn ($c) => $c['critical'] && $this->hasFailure($c)));
    }

    public function failures(): array
    {
        return array_values(array_filter($this->cases, fn ($c) => $this->hasFailure($c)));
    }

    public function totals(): array
    {
        $runs = array_merge(...array_map(fn ($c) => $c['runs'], $this->cases ?: [['runs' => []]]));
        $latencies = array_filter(array_column($runs, 'latency_ms'), fn ($v) => $v !== null);
        $costs = array_filter(array_column($runs, 'cost_usd'), fn ($v) => $v !== null);

        return [
            'input_tokens' => (int) array_sum(array_column($runs, 'input_tokens')),
            'output_tokens' => (int) array_sum(array_column($runs, 'output_tokens')),
            'avg_latency_ms' => $latencies === [] ? null : (int) round(array_sum($latencies) / count($latencies)),
            'cost_usd' => $costs === [] ? null : round(array_sum($costs), 6),
            'fallback_runs' => count(array_filter($runs, fn ($r) => $r['fallback'])),
        ];
    }

    public function toMarkdown(float $threshold): string
    {
        $t = $this->totals();
        $md = "# Laporan uji akurasi — {$this->datasetName}\n\n";
        $md .= "- Akurasi: **{$this->accuracy()}%** ({$this->passedRuns()}/{$this->totalRuns()} jawaban benar; ambang {$threshold}%)\n";
        $md .= "- Pengulangan per kasus: {$this->runsPerCase}\n";
        $md .= "- Token masuk/keluar: {$t['input_tokens']} / {$t['output_tokens']}; latensi rata-rata: " . ($t['avg_latency_ms'] ?? '?') . " ms\n";
        $md .= '- Estimasi biaya: ' . ($t['cost_usd'] !== null ? '$' . $t['cost_usd'] : 'tidak dihitung (harga belum diisi)') . "; fallback dipakai: {$t['fallback_runs']}\n\n";

        $md .= "## Per kategori\n\n| Kategori | Benar | Total | % |\n|---|---|---|---|\n";
        foreach ($this->byCategory() as $cat => $row) {
            $md .= "| {$cat} | {$row['passed']} | {$row['total']} | {$row['rate']} |\n";
        }

        $critical = $this->criticalFailures();
        $md .= "\n## Kegagalan kritis (halusinasi / keamanan): " . count($critical) . "\n";
        $md .= $critical === [] ? "\nTidak ada.\n" : $this->renderCases($critical);

        $other = array_values(array_filter($this->failures(), fn ($c) => ! $c['critical']));
        $md .= "\n## Kegagalan lain: " . count($other) . "\n";
        $md .= $other === [] ? "\nTidak ada.\n" : $this->renderCases($other);

        $md .= "\n> Penilaian berbasis aturan teks. Tinjau jawaban yang gagal secara manual: sebagian bisa berupa parafrase yang benar.\n";

        return $md;
    }

    private function renderCases(array $cases): string
    {
        $md = '';

        foreach ($cases as $c) {
            $md .= "\n### {$c['id']} ({$c['category']})\n\n**Pertanyaan:** {$c['question']}\n";

            foreach ($c['runs'] as $i => $run) {
                if ($run['passed']) {
                    continue;
                }

                $n = $i + 1;
                $md .= "\n- Run {$n}: " . implode('; ', $run['reasons']) . "\n  - Jawaban: " . str_replace("\n", ' ', (string) ($run['answer'] ?? '(tidak ada jawaban)')) . "\n";
            }
        }

        return $md;
    }

    private function hasFailure(array $case): bool
    {
        foreach ($case['runs'] as $run) {
            if (! $run['passed']) {
                return true;
            }
        }

        return false;
    }
}
