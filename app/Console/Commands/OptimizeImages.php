<?php

namespace App\Console\Commands;

use App\Services\Admin\AuditLogger;
use App\Services\Media\ImageOptimizer;
use App\Support\Media;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Memperkecil gambar konten website yang SUDAH terunggah (lihat ImageOptimizer) dan mengarahkan database ke file WebP baru.
 * Bekerja di disk media aktif (Media::disk(): lokal 'public' atau Cloudflare R2).
 *
 * Aman diulang dan dapat dibatalkan:
 *  - File asli tidak dihapus; kolom database hanya diubah bila masih berisi path lama.
 *  - Setiap eksekusi menulis manifest di disk local (storage/app/private/image-optimizations/*.json); `--rollback=<manifest>`
 *    mengembalikan path lama.
 *  - Disk 'public' saja: bila folder public_html/storage ada (Hostinger menyimpan salinan fisik disk public di sana), file
 *    baru ikut ditulis ke sana; tanpa itu database menunjuk file yang tidak ada di web root dan gambar hilang.
 */
class OptimizeImages extends Command
{
    /** table => kolom path gambar (path relatif di disk media). Dipakai juga oleh media:sync. */
    public const TARGETS = [
        'articles' => 'thumbnail',
        'portfolios' => 'cover_image',
        'portfolio_images' => 'image_path',
        'services' => 'hero_image',
    ];

    private const MANIFEST_DIR = 'image-optimizations';

    protected $signature = 'images:optimize
        {--dry-run : Hitung hasil tanpa menulis file atau mengubah database}
        {--mirror= : Folder salinan disk public yang ikut menerima file baru (bawaan: public_html/storage bila ada)}
        {--no-mirror : Jangan menulis ke folder salinan}
        {--rollback= : Kembalikan path lama dari manifest (nama file di storage/app/private/image-optimizations)}';

    protected $description = 'Perkecil gambar konten website (WebP, lebar maks 1600 px) dan arahkan database ke file baru.';

    public function handle(ImageOptimizer $optimizer, AuditLogger $audit): int
    {
        if ($this->option('rollback')) {
            return $this->rollback((string) $this->option('rollback'), $audit);
        }

        // Foto kamera 6000x4000 butuh ±200 MB saat di-decode; batas CLI bawaan (128 MB) terlalu kecil.
        ini_set('memory_limit', '1024M');

        $dry = (bool) $this->option('dry-run');
        $disk = Media::disk();
        $mirror = $disk === 'public' ? $this->mirrorRoot() : null;
        $changes = [];
        $skipped = [];
        $before = 0;
        $after = 0;

        foreach (self::TARGETS as $table => $column) {
            $rows = DB::table($table)->whereNotNull($column)->where($column, '!=', '')->orderBy('id')->get(['id', $column]);

            foreach ($rows as $row) {
                try {
                    $result = $optimizer->optimize($disk, $row->{$column}, $dry, $mirror);
                } catch (Throwable $e) {
                    // Mis. koneksi R2 putus: lewati gambar ini, lanjutkan yang lain (aman diulang).
                    $skipped['error'] = ($skipped['error'] ?? 0) + 1;
                    $this->warn("  gagal      {$row->{$column}}: {$e->getMessage()}");

                    continue;
                }

                $before += $result->oldBytes;
                $after += $result->newBytes;

                if (! $result->wasOptimized()) {
                    $skipped[$result->reason] = ($skipped[$result->reason] ?? 0) + 1;

                    continue;
                }

                if (! $dry) {
                    // Hanya bila masih path lama (admin mungkin baru saja mengganti gambar).
                    DB::table($table)->where('id', $row->id)->where($column, $result->path)->update([$column => $result->newPath]);
                }

                $changes[] = ['table' => $table, 'column' => $column, 'id' => $row->id, 'old' => $result->path, 'new' => $result->newPath,
                    'old_bytes' => $result->oldBytes, 'new_bytes' => $result->newBytes];
                $this->line(sprintf('  %-10s %s  %s -> %s', $dry ? 'akan' : 'dioptimasi', $result->path,
                    $this->mb($result->oldBytes), $this->mb($result->newBytes)));
            }
        }

        $this->newLine();
        $this->info(sprintf('%s %d gambar. Total gambar terpakai: %s -> %s.%s',
            $dry ? 'Akan dioptimasi' : 'Dioptimasi', count($changes), $this->mb($before), $this->mb($after),
            $skipped ? ' Dilewati: ' . collect($skipped)->map(fn ($n, $r) => "{$r}={$n}")->implode(', ') . '.' : ''));

        if ($dry || $changes === []) {
            return self::SUCCESS;
        }

        $manifest = self::MANIFEST_DIR . '/' . now()->format('Ymd-His') . '.json';
        Storage::disk('local')->put($manifest, json_encode(['disk' => $disk, 'mirror' => $mirror, 'changes' => $changes], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        $this->info('Manifest: ' . Storage::disk('local')->path($manifest) . ' (batalkan: php artisan images:optimize --rollback=' . basename($manifest) . ')');
        $this->line(match (true) {
            $disk !== 'public' => "File baru ditulis ke disk {$disk}.",
            $mirror !== null => "File baru juga ditulis ke {$mirror}.",
            default => 'Tidak ada folder salinan (public_html/storage); pastikan web root membaca disk public.',
        });
        $this->line('File asli tidak dihapus.');

        $audit->record('system.images_optimized', null, null, [], ['count' => count($changes), 'before_bytes' => $before, 'after_bytes' => $after], basename($manifest));

        return self::SUCCESS;
    }

    private function rollback(string $name, AuditLogger $audit): int
    {
        $manifest = self::MANIFEST_DIR . '/' . basename($name);

        if (! Storage::disk('local')->exists($manifest)) {
            $this->error('Manifest tidak ditemukan: ' . Storage::disk('local')->path($manifest));

            return self::FAILURE;
        }

        $restored = 0;

        foreach (json_decode(Storage::disk('local')->get($manifest), true)['changes'] ?? [] as $c) {
            if (! array_key_exists($c['table'], self::TARGETS) || self::TARGETS[$c['table']] !== $c['column']) {
                continue; // manifest diubah tangan: abaikan tabel/kolom di luar daftar
            }

            $restored += DB::table($c['table'])->where('id', $c['id'])->where($c['column'], $c['new'])->update([$c['column'] => $c['old']]);
        }

        $this->info("Dikembalikan: {$restored} gambar ke file aslinya.");
        $audit->record('system.images_rollback', null, null, [], ['count' => $restored], basename($manifest));

        return self::SUCCESS;
    }

    private function mirrorRoot(): ?string
    {
        if ($this->option('no-mirror')) {
            return null;
        }

        $mirror = $this->option('mirror') ?: base_path('public_html/storage');

        return is_dir($mirror) ? $mirror : null;
    }

    private function mb(int $bytes): string
    {
        return $bytes >= 1048576 ? round($bytes / 1048576, 1) . ' MB' : round($bytes / 1024) . ' KB';
    }
}
