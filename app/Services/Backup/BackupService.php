<?php

namespace App\Services\Backup;

use App\Models\BackupRun;
use Closure;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * Backup MySQL terenkripsi + uji restore (NFR-005).
 *
 * Alur backup: mysqldump (konsisten, --single-transaction) -> gzip -> AES-256-GCM -> berkas sementara -> unggah ke
 * disk backup -> cek ukuran di tujuan -> hapus berkas sementara -> hapus backup yang melewati masa simpan.
 * Alur uji restore: unduh -> cek SHA-256 -> dekripsi -> impor ke database SEMENTARA -> bandingkan hitungan baris tiap
 * tabel dengan manifest -> hapus database sementara. Backup yang belum pernah diuji dianggap belum ada.
 */
class BackupService
{
    /** Tabel yang tidak dibandingkan jumlah barisnya (isi tetap dicadangkan). */
    private const VOLATILE = ['backup_runs', 'sessions', 'cache', 'cache_locks', 'jobs', 'job_batches'];

    private const NAME = '/^velora-\d{8}-\d{6}\.vbk$/';

    /** @param Closure(string $path, callable(string): void $sink): void|null $dumpRunner untuk tes */
    public function __construct(private ?Closure $dumpRunner = null)
    {
    }

    public function disk(): Filesystem
    {
        return Storage::disk(config('backup.disk'));
    }

    public function retentionDays(): int
    {
        return max(1, min((int) config('backup.retention_days'), (int) config('backup.max_retention_days')));
    }

    public function run(): BackupRun
    {
        $key = BackupCipher::parseKey(config('backup.encryption_key'));
        $name = 'velora-' . now()->format('Ymd-His') . '.vbk';
        $run = BackupRun::create(['file' => $name, 'disk' => config('backup.disk'), 'status' => BackupRun::RUNNING, 'started_at' => now()]);
        $tmp = storage_path('app/backup-tmp');
        is_dir($tmp) || mkdir($tmp, 0700, true);
        $local = "{$tmp}/{$name}";

        try {
            $before = $this->tableCounts();
            $writer = BackupCipher::writer($local, $key);
            $tail = '';

            try {
                $this->dump(function (string $chunk) use ($writer, &$tail) {
                    $writer->write($chunk);
                    $tail = substr($tail . $chunk, -200);
                });
            } finally {
                $size = $writer->close();
            }

            // mysqldump menutup berkas dengan penanda ini; tanpa itu dump terpotong.
            if (! str_contains($tail, 'Dump completed')) {
                throw new RuntimeException('Dump tidak lengkap (penanda akhir tidak ditemukan).');
            }

            $after = $this->tableCounts();
            $sha = hash_file('sha256', $local);
            $manifest = ['tables' => array_keys($before), 'rows_before' => $before, 'rows_after' => $after, 'app' => config('app.name')];

            $path = config('backup.path') . '/' . $name;
            $this->upload($local, $path, $size);
            $this->disk()->put($path . '.json', json_encode(['sha256' => $sha, 'size' => $size] + $manifest, JSON_PRETTY_PRINT));

            $run->update(['status' => BackupRun::SUCCEEDED, 'size_bytes' => $size, 'sha256' => $sha, 'manifest' => $manifest, 'finished_at' => now()]);
            $this->prune();
        } catch (Throwable $e) {
            $run->update(['status' => BackupRun::FAILED, 'error' => mb_substr($e->getMessage(), 0, 500), 'finished_at' => now()]);
            Log::error('backup.failed', ['error' => $e::class, 'message' => mb_substr($e->getMessage(), 0, 300)]);

            throw $e;
        } finally {
            @unlink($local);
        }

        Log::info('backup.completed', ['file' => $name, 'size' => $run->size_bytes]);

        return $run->refresh();
    }

    /**
     * Hapus backup yang melewati masa simpan (berdasarkan waktu pada nama berkas). Backup terbaru tidak pernah dihapus.
     *
     * @return list<string> nama berkas yang dihapus
     */
    public function prune(): array
    {
        $names = $this->files();
        $cutoff = now()->subDays($this->retentionDays());
        $deleted = [];

        foreach (array_slice($names, 1) as $name) { // $names terurut baru -> lama; yang terbaru dilindungi
            if ($this->timeOf($name)->lt($cutoff)) {
                $this->disk()->delete([config('backup.path') . "/{$name}", config('backup.path') . "/{$name}.json"]);
                $deleted[] = $name;
            }
        }

        return $deleted;
    }

    /** @return list<string> nama berkas backup di tujuan, terbaru lebih dulu */
    public function files(): array
    {
        $names = collect($this->disk()->files(config('backup.path')))
            ->map(fn ($f) => basename($f))->filter(fn ($n) => preg_match(self::NAME, $n) === 1)
            ->sortDesc()->values()->all();

        return $names;
    }

    /**
     * Uji restore ke database sementara.
     *
     * @return array{ok: bool, tables: int, problems: list<string>}
     */
    public function verify(?string $name = null): array
    {
        $key = BackupCipher::parseKey(config('backup.encryption_key'));
        $scratch = $this->scratchDatabase();
        $name ??= $this->files()[0] ?? throw new RuntimeException('Belum ada backup untuk diuji.');
        $path = config('backup.path') . '/' . basename($name);

        if (! $this->disk()->exists($path)) {
            throw new RuntimeException("Backup {$name} tidak ditemukan di tujuan.");
        }

        $meta = $this->disk()->exists($path . '.json') ? json_decode($this->disk()->get($path . '.json'), true) : null;
        $tmp = storage_path('app/backup-tmp');
        is_dir($tmp) || mkdir($tmp, 0700, true);
        $enc = "{$tmp}/verify-" . basename($name);
        $sql = "{$tmp}/verify-" . pathinfo($name, PATHINFO_FILENAME) . '.sql';
        $problems = [];

        try {
            $in = $this->disk()->readStream($path);
            $out = fopen($enc, 'wb');
            stream_copy_to_stream($in, $out);
            fclose($out);
            is_resource($in) && fclose($in);

            if (isset($meta['sha256']) && ! hash_equals($meta['sha256'], hash_file('sha256', $enc))) {
                throw new RuntimeException('Checksum berkas di tujuan tidak cocok (rusak atau diubah).');
            }

            $handle = fopen($sql, 'wb');
            BackupCipher::decrypt($enc, $key, fn (string $chunk) => fwrite($handle, $chunk));
            fclose($handle);

            $restored = $this->restoreInto($scratch, $sql);

            foreach ($meta['tables'] ?? [] as $table) {
                if (! array_key_exists($table, $restored)) {
                    $problems[] = "Tabel {$table} tidak ada setelah restore.";

                    continue;
                }

                [$lo, $hi] = $this->range($meta['rows_before'][$table] ?? 0, $meta['rows_after'][$table] ?? 0);

                if ($restored[$table] < $lo || $restored[$table] > $hi) {
                    $problems[] = "Tabel {$table}: {$restored[$table]} baris setelah restore, diharapkan {$lo}–{$hi}.";
                }
            }

            if ($meta === null) {
                $problems[] = 'Manifest (.json) tidak ditemukan: jumlah baris tidak dapat dibandingkan.';
            }
        } finally {
            $this->dropScratch($scratch);
            @unlink($enc);
            @unlink($sql);
        }

        $result = ['ok' => $problems === [], 'tables' => count($restored ?? []), 'problems' => $problems];

        BackupRun::where('file', basename($name))->update(['verified_at' => now(), 'verify_result' => $result]);

        return $result;
    }

    /** @return array{0: int, 1: int} */
    private function range(int $a, int $b): array
    {
        return [min($a, $b), max($a, $b)];
    }

    /** @return array<string, int> */
    public function tableCounts(): array
    {
        $counts = [];

        foreach ($this->tableNames() as $table) {
            if (in_array($table, self::VOLATILE, true)) {
                continue; // berubah terus selama dump (atau dicatat oleh backup itu sendiri): tetap ikut di-dump, tidak dibandingkan
            }
            $counts[$table] = DB::table($table)->count();
        }

        ksort($counts);

        return $counts;
    }

    /** @return list<string> tabel dasar milik database aktif saja (getTableListing() bisa memuat skema lain di MySQL) */
    private function tableNames(): array
    {
        if (DB::getDriverName() === 'mysql') {
            return array_map(
                fn ($r) => $r->table_name ?? $r->TABLE_NAME,
                DB::select("SELECT table_name FROM information_schema.tables WHERE table_schema = DATABASE() AND table_type = 'BASE TABLE'"),
            );
        }

        return Schema::getTableListing(schemaQualified: false);
    }

    private function dump(callable $sink): void
    {
        if ($this->dumpRunner !== null) {
            ($this->dumpRunner)($sink);

            return;
        }

        $c = config('database.connections.' . config('database.default'));

        if (($c['driver'] ?? null) !== 'mysql') {
            throw new RuntimeException('Backup hanya mendukung MySQL/MariaDB.');
        }

        $process = new Process([
            config('backup.mysqldump'), '--host=' . $c['host'], '--port=' . $c['port'], '--user=' . $c['username'],
            '--single-transaction', '--quick', '--routines', '--triggers', '--no-tablespaces', '--default-character-set=utf8mb4',
            $c['database'],
        ], null, ['MYSQL_PWD' => (string) $c['password']], null, (float) config('backup.timeout_seconds'));

        $process->run(function (string $type, string $buffer) use ($sink) {
            $type === Process::OUT && $sink($buffer);
        });

        if (! $process->isSuccessful()) {
            throw new RuntimeException('mysqldump gagal: ' . mb_substr(trim($process->getErrorOutput()), 0, 300));
        }
    }

    private function upload(string $local, string $path, int $size): void
    {
        $stream = fopen($local, 'rb');

        try {
            $this->disk()->writeStream($path, $stream);
        } finally {
            is_resource($stream) && fclose($stream);
        }

        if ($this->disk()->size($path) !== $size) {
            $this->disk()->delete($path);

            throw new RuntimeException('Ukuran berkas di tujuan tidak sama dengan yang dikirim; unggahan dibatalkan.');
        }
    }

    /** Nama database sementara; menolak nama yang bisa menimpa data sungguhan. */
    public function scratchDatabase(): string
    {
        $name = (string) config('backup.verify_database');
        $live = (string) config('database.connections.' . config('database.default') . '.database');

        if ($name === '' || ! preg_match('/^[A-Za-z0-9_]+$/', $name) || ! str_contains(strtolower($name), 'restore') || strcasecmp($name, $live) === 0) {
            throw new RuntimeException('BACKUP_VERIFY_DATABASE harus diisi dengan nama database SEMENTARA yang mengandung "restore" dan berbeda dari database aplikasi.');
        }

        return $name;
    }

    /** @return array<string, int> hitungan baris per tabel hasil restore */
    private function restoreInto(string $scratch, string $sqlFile): array
    {
        $c = config('database.connections.' . config('database.default'));
        $base = [config('backup.mysql'), '--host=' . $c['host'], '--port=' . $c['port'], '--user=' . $c['username'], '--default-character-set=utf8mb4'];
        $env = ['MYSQL_PWD' => (string) $c['password']];

        $this->mysql($base, $env, "DROP DATABASE IF EXISTS `{$scratch}`; CREATE DATABASE `{$scratch}` CHARACTER SET utf8mb4;");

        $handle = fopen($sqlFile, 'rb');
        $import = new Process([...$base, $scratch], null, $env, $handle, (float) config('backup.timeout_seconds'));
        $import->run();
        fclose($handle);

        if (! $import->isSuccessful()) {
            throw new RuntimeException('Impor ke database sementara gagal: ' . mb_substr(trim($import->getErrorOutput()), 0, 300));
        }

        $tables = trim($this->mysql([...$base, '-N', '-B'], $env, "SELECT table_name FROM information_schema.tables WHERE table_schema = '{$scratch}' AND table_type = 'BASE TABLE'"));
        $counts = [];

        foreach (array_filter(explode("\n", $tables)) as $table) {
            $counts[trim($table)] = (int) trim($this->mysql([...$base, '-N', '-B'], $env, "SELECT COUNT(*) FROM `{$scratch}`.`" . str_replace('`', '', trim($table)) . '`'));
        }

        return $counts;
    }

    private function dropScratch(string $scratch): void
    {
        try {
            $c = config('database.connections.' . config('database.default'));
            $this->mysql([config('backup.mysql'), '--host=' . $c['host'], '--port=' . $c['port'], '--user=' . $c['username']], ['MYSQL_PWD' => (string) $c['password']], "DROP DATABASE IF EXISTS `{$scratch}`");
        } catch (Throwable $e) {
            Log::warning('backup.scratch_cleanup_failed', ['error' => $e::class]);
        }
    }

    private function mysql(array $command, array $env, string $sql): string
    {
        $p = new Process($command, null, $env, $sql, (float) config('backup.timeout_seconds'));
        $p->run();

        if (! $p->isSuccessful()) {
            throw new RuntimeException('Perintah mysql gagal: ' . mb_substr(trim($p->getErrorOutput()), 0, 300));
        }

        return $p->getOutput();
    }

    private function timeOf(string $name): \Illuminate\Support\Carbon
    {
        preg_match('/(\d{8})-(\d{6})/', $name, $m);

        return \Illuminate\Support\Carbon::createFromFormat('Ymd-His', "{$m[1]}-{$m[2]}");
    }
}
