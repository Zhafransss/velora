<?php

namespace App\Console\Commands;

use App\Services\Admin\AuditLogger;
use App\Support\Media;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

/**
 * Menyalin gambar konten website antar-disk dengan path yang SAMA (mis. storage/app/public -> Cloudflare R2), sehingga
 * database tidak perlu diubah: setelah selesai cukup ganti MEDIA_DISK.
 *
 * Aman diulang: file yang sudah ada di tujuan dengan ukuran sama dilewati, jadi bila terputus tinggal dijalankan lagi.
 * Arah balik (R2 -> lokal) memakai `--from=r2 --to=public`.
 */
class SyncMedia extends Command
{
    protected $signature = 'media:sync
        {--from=public : Disk asal}
        {--to=r2 : Disk tujuan}
        {--all : Salin semua file di disk asal, bukan hanya gambar yang dipakai database}
        {--dry-run : Tampilkan yang akan disalin tanpa menyalin}';

    protected $description = 'Salin gambar konten website ke disk lain (mis. Cloudflare R2) dengan path yang sama; database tidak diubah.';

    public function handle(AuditLogger $audit): int
    {
        $from = (string) $this->option('from');
        $to = (string) $this->option('to');
        $dry = (bool) $this->option('dry-run');

        if ($error = $this->configError($from, $to)) {
            $this->error($error);

            return self::FAILURE;
        }

        $source = Storage::disk($from);
        $target = Storage::disk($to);
        $paths = $this->option('all')
            ? array_values(array_filter($source->allFiles(), fn (string $p) => basename($p) !== '.gitignore'))
            : $this->referencedPaths();

        $copied = $present = $missing = $failed = $bytes = 0;

        foreach ($paths as $path) {
            try {
                if (! $source->exists($path)) {
                    $missing++;
                    $this->warn("  tidak ada di {$from}: {$path}");

                    continue;
                }

                $size = $source->size($path);

                if ($target->exists($path) && $target->size($path) === $size) {
                    $present++;

                    continue;
                }

                if (! $dry) {
                    $stream = $source->readStream($path);

                    try {
                        $target->writeStream($path, $stream);
                    } finally {
                        if (is_resource($stream)) {
                            fclose($stream);
                        }
                    }

                    if ($target->size($path) !== $size) {
                        throw new RuntimeException('ukuran di tujuan berbeda setelah disalin');
                    }
                }

                $copied++;
                $bytes += $size;
                $this->line(sprintf('  %-8s %s (%s)', $dry ? 'akan' : 'disalin', $path, $this->mb($size)));
            } catch (Throwable $e) {
                $failed++;
                $this->warn("  gagal    {$path}: {$e->getMessage()}");
            }
        }

        $this->newLine();
        $this->info(sprintf('%s %d file (%s) dari %s ke %s. Sudah ada: %d. Tidak ditemukan: %d. Gagal: %d.',
            $dry ? 'Akan disalin' : 'Disalin', $copied, $this->mb($bytes), $from, $to, $present, $missing, $failed));

        if ($missing > 0) {
            $this->line('File yang tidak ditemukan juga tidak tampil di website saat ini; periksa/unggah ulang lewat admin.');
        }

        if (! $dry && $copied > 0) {
            $audit->record('system.media_synced', null, null, [], ['from' => $from, 'to' => $to, 'count' => $copied, 'bytes' => $bytes]);
        }

        if ($failed > 0) {
            $this->error('Sebagian file gagal disalin. Jalankan ulang perintah ini (file yang sudah tersalin dilewati).');

            return self::FAILURE;
        }

        if (! $dry && Media::disk() !== $to) {
            $this->line("Berikutnya: set MEDIA_DISK={$to} di .env, lalu `php artisan optimize` (bila config di-cache).");
        }

        return self::SUCCESS;
    }

    private function configError(string $from, string $to): ?string
    {
        if ($from === $to) {
            return 'Disk asal dan tujuan sama.';
        }

        return Media::configProblem($from) ?? Media::configProblem($to);
    }

    /** @return list<string> path unik yang dirujuk kolom gambar di database */
    private function referencedPaths(): array
    {
        $paths = [];

        foreach (OptimizeImages::TARGETS as $table => $column) {
            foreach (DB::table($table)->whereNotNull($column)->where($column, '!=', '')->pluck($column) as $path) {
                $paths[ltrim($path, '/')] = true;
            }
        }

        return array_keys($paths);
    }

    private function mb(int $bytes): string
    {
        return $bytes >= 1048576 ? round($bytes / 1048576, 1) . ' MB' : round($bytes / 1024) . ' KB';
    }
}
