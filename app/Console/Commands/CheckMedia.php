<?php

namespace App\Console\Commands;

use App\Support\Media;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * Uji ujung-ke-ujung disk gambar (terutama Cloudflare R2) sebelum/ sesudah MEDIA_DISK diganti:
 * kredensial bisa menulis, file bisa dibuka pengunjung lewat URL publik, dan CORS mengizinkan panel admin
 * (preview gambar lama di form admin dimuat lewat fetch() lintas domain). File uji selalu dihapus.
 */
class CheckMedia extends Command
{
    /** PNG 1x1 piksel. */
    private const PROBE = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';

    protected $signature = 'media:check
        {disk? : Disk yang diuji (bawaan: MEDIA_DISK)}
        {--origin= : Alamat panel admin untuk uji CORS (bawaan: APP_URL)}';

    protected $description = 'Uji disk gambar (mis. R2): tulis, buka lewat URL publik, cek CORS untuk admin, lalu hapus file uji.';

    public function handle(): int
    {
        $disk = (string) ($this->argument('disk') ?: Media::disk());

        if ($problem = Media::configProblem($disk)) {
            $this->error($problem);

            return self::FAILURE;
        }

        $storage = Storage::disk($disk);
        $path = 'healthcheck/' . Str::lower((string) Str::ulid()) . '.png';
        $probe = base64_decode(self::PROBE);
        $origin = rtrim((string) ($this->option('origin') ?: config('app.url')), '/');
        $remote = config("filesystems.disks.{$disk}.driver") === 's3';
        $rows = [];

        try {
            $storage->put($path, $probe);
            $rows[] = ['Tulis file', 'OK', $path];

            $rows[] = $storage->exists($path) ? ['Baca lewat API', 'OK', ''] : ['Baca lewat API', 'GAGAL', 'file tidak ditemukan setelah ditulis'];

            if ($remote) {
                $url = $storage->url($path);
                $response = Http::timeout(15)->withHeaders(['Origin' => $origin])->get($url);
                $rows[] = $response->successful() && $response->body() === $probe
                    ? ['URL publik', 'OK', $url]
                    : ['URL publik', 'GAGAL', "HTTP {$response->status()} {$url} — aktifkan akses publik bucket (domain kustom/r2.dev) dan cek R2_PUBLIC_URL"];

                $allowed = (string) $response->header('Access-Control-Allow-Origin');
                $rows[] = in_array($allowed, ['*', $origin], true)
                    ? ['CORS admin', 'OK', "{$origin} diizinkan"]
                    : ['CORS admin', 'GAGAL', "{$origin} belum diizinkan — tambahkan CORS policy bucket (docs/DEPLOY-CHECKLIST.md bagian 6); tanpa ini preview gambar di form admin kosong"];
            }
        } catch (Throwable $e) {
            $rows[] = ['Koneksi', 'GAGAL', Str::limit($e->getMessage(), 300)];
        } finally {
            rescue(fn () => $storage->delete($path), report: false);
        }

        $this->table(['Uji', 'Hasil', 'Keterangan'], $rows);

        if (collect($rows)->contains(fn (array $r) => $r[1] !== 'OK')) {
            $this->error("Disk {$disk} belum siap.");

            return self::FAILURE;
        }

        $this->info("Disk {$disk} siap dipakai.");

        return self::SUCCESS;
    }
}
