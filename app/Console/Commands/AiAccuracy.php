<?php

namespace App\Console\Commands;

use App\Services\Ai\Accuracy\AccuracyRunner;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;

/**
 * Uji akurasi bot (TC-ACC-01, target >= 90%). Memanggil LLM sungguhan dan MEMAKAI KUOTA/BIAYA API:
 * jumlah panggilan = jumlah kasus x --runs. Data uji dibuat sementara dan di-rollback.
 */
class AiAccuracy extends Command
{
    protected $signature = 'ai:accuracy
        {dataset=resources/accuracy/kopi-senja.json : Path file dataset JSON}
        {--runs=1 : Ulangi tiap kasus N kali (mengukur variasi jawaban)}
        {--threshold=90 : Ambang akurasi minimum (%)}
        {--only=* : Hanya kategori tertentu (boleh diulang)}
        {--sleep-ms=0 : Jeda antar panggilan (hindari rate limit provider)}
        {--strict : Gagal juga bila ada kasus kritis (halusinasi/keamanan) yang gagal}
        {--report : Simpan laporan lengkap (Markdown) ke storage/app/accuracy}';

    protected $description = 'Ukur akurasi bot pada dataset uji (memakai API AI sungguhan).';

    public function handle(AccuracyRunner $runner): int
    {
        $path = base_path($this->argument('dataset'));

        if (! is_file($path)) {
            $path = $this->argument('dataset');
        }

        if (! is_file($path)) {
            $this->error('Dataset tidak ditemukan: ' . $this->argument('dataset'));

            return self::FAILURE;
        }

        $dataset = json_decode((string) file_get_contents($path), true);

        if (! is_array($dataset)) {
            $this->error('Dataset bukan JSON yang valid.');

            return self::FAILURE;
        }

        $runs = max(1, (int) $this->option('runs'));
        $threshold = (float) $this->option('threshold');
        $only = array_filter((array) $this->option('only'));

        try {
            AccuracyRunner::validate($dataset);
        } catch (InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $caseCount = count(array_filter($dataset['cases'], fn ($c) => $only === [] || in_array($c['category'], $only, true)));
        $this->info("Dataset: " . ($dataset['name'] ?? basename($path)) . " — {$caseCount} kasus x {$runs} = " . ($caseCount * $runs) . ' panggilan AI (berbiaya).');

        if ($caseCount === 0) {
            $this->error('Tidak ada kasus yang cocok dengan filter --only.');

            return self::FAILURE;
        }

        $bar = $this->output->createProgressBar($caseCount * $runs);
        $bar->start();

        try {
            $report = $runner->run($dataset, $runs, $only, (int) $this->option('sleep-ms'), function () use ($bar) {
                $bar->advance();
            });
        } catch (\Throwable $e) {
            $bar->finish();
            $this->newLine();
            $this->error('Uji dihentikan: ' . $e->getMessage());

            return self::FAILURE;
        }

        $bar->finish();
        $this->newLine(2);

        $this->table(
            ['Kategori', 'Benar', 'Total', '%'],
            collect($report->byCategory())->map(fn ($r, $cat) => [$cat, $r['passed'], $r['total'], $r['rate']])->values()->all(),
        );

        $totals = $report->totals();
        $this->line("Akurasi: {$report->accuracy()}% ({$report->passedRuns()}/{$report->totalRuns()}) — ambang {$threshold}%");
        $this->line("Token masuk/keluar: {$totals['input_tokens']} / {$totals['output_tokens']}; latensi rata-rata: " . ($totals['avg_latency_ms'] ?? '?') . ' ms'
            . ($totals['cost_usd'] !== null ? "; estimasi biaya: \${$totals['cost_usd']}" : '')
            . ($totals['fallback_runs'] > 0 ? "; fallback dipakai {$totals['fallback_runs']}x" : ''));

        $critical = $report->criticalFailures();
        foreach ($report->failures() as $case) {
            $tag = $case['critical'] ? '<fg=red>[KRITIS]</>' : '[gagal]';
            $this->line("{$tag} {$case['id']}: {$case['question']}");

            foreach ($case['runs'] as $run) {
                if (! $run['passed']) {
                    $this->line('    ↳ ' . implode('; ', $run['reasons']));
                    $this->line('      jawaban: ' . mb_substr(str_replace("\n", ' ', (string) ($run['answer'] ?? '-')), 0, 220));
                    break;
                }
            }
        }

        if ($this->option('report')) {
            $file = 'accuracy/' . now()->format('Ymd-His') . '.md';
            Storage::disk('local')->put($file, $report->toMarkdown($threshold));
            $this->info('Laporan lengkap: ' . storage_path('app/private/' . $file));
        }

        $this->newLine();
        $ok = $report->accuracy() >= $threshold;

        if ($ok) {
            $this->info("LULUS: akurasi {$report->accuracy()}% ≥ {$threshold}%.");
        } else {
            $this->error("TIDAK LULUS: akurasi {$report->accuracy()}% < {$threshold}%.");
        }

        if ($critical !== []) {
            $this->warn(count($critical) . ' kasus kritis (halusinasi/keamanan) gagal — tinjau sebelum pilot.');
        }

        if ($this->option('strict') && $critical !== []) {
            $ok = false;
        }

        return $ok ? self::SUCCESS : self::FAILURE;
    }
}
