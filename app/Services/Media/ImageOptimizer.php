<?php

namespace App\Services\Media;

use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Memperkecil gambar konten website (thumbnail artikel, sampul/screenshot portofolio, gambar layanan) menjadi WebP
 * dengan lebar maksimal MAX_WIDTH. Diukur 2026-10-13: gambar upload sampai 6000x4000 px / 6,5 MB membuat halaman
 * /articles 15,6 MB (LCP 83 dtk di mobile).
 *
 * Prinsip:
 *  - File asli TIDAK pernah ditimpa/dihapus di sini; hasil ditulis sebagai file baru `<nama>-w<lebar>.webp`.
 *  - Gagal-aman: format tak didukung, memori PHP tidak cukup, atau hasil tidak lebih kecil -> dilewati, bukan error.
 *    Upload dan halaman tetap jalan dengan file asli.
 */
class ImageOptimizer
{
    public const MAX_WIDTH = 1600;
    public const QUALITY = 80;
    /** Batas dimensi WebP adalah 16383 px; screenshot halaman penuh bisa sangat tinggi. */
    public const MAX_HEIGHT = 16000;

    private const SUPPORTED = [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP];

    /**
     * @param  string|null  $mirrorRoot  folder kedua yang ikut menerima file hasil (mis. public_html/storage di Hostinger,
     *                                   yang berisi salinan fisik disk public)
     */
    public function optimize(string $disk, string $path, bool $dryRun = false, ?string $mirrorRoot = null): OptimizeResult
    {
        // Lewat API disk (bukan path lokal) agar berlaku juga untuk Cloudflare R2.
        $storage = Storage::disk($disk);

        if (! $storage->exists($path)) {
            return OptimizeResult::skipped($path, 'missing');
        }

        [$result, $webp] = $this->convert($path, (string) $storage->get($path));

        if ($webp !== null && ! $dryRun) {
            $storage->put($result->newPath, $webp);

            if ($mirrorRoot !== null) {
                $mirrored = rtrim($mirrorRoot, '/\\') . '/' . $result->newPath;
                @mkdir(dirname($mirrored), 0755, true);
                file_put_contents($mirrored, $webp);
            }
        }

        return $result;
    }

    /**
     * Konversi di memori tanpa menulis apa pun. Dipakai upload admin agar file mentah tidak perlu dikirim ke disk
     * (R2) lalu diunduh kembali.
     *
     * @return array{0: OptimizeResult, 1: string|null} hasil + isi WebP (null bila dilewati)
     */
    public function convert(string $path, string $contents): array
    {
        $info = @getimagesizefromstring($contents);

        if ($info === false || ! in_array($info[2], self::SUPPORTED, true)) {
            return [OptimizeResult::skipped($path, 'unsupported'), null];
        }

        [$width, $height] = $info;
        $oldBytes = strlen($contents);
        [$targetWidth, $targetHeight] = $this->targetSize($width, $height);

        // Sudah WebP dan tidak perlu diperkecil: encode ulang hanya menurunkan kualitas.
        if ($info[2] === IMAGETYPE_WEBP && $targetWidth === $width) {
            return [OptimizeResult::skipped($path, 'already_optimal', $oldBytes), null];
        }

        // Kehabisan memori adalah fatal error (tidak bisa ditangkap), jadi diperiksa sebelum decode.
        if (! $this->hasMemoryFor($width, $height, $targetWidth, $targetHeight)) {
            return [OptimizeResult::skipped($path, 'insufficient_memory', $oldBytes), null];
        }

        try {
            $webp = $this->encode($contents, $width, $height, $targetWidth, $targetHeight);
        } catch (Throwable) {
            $webp = null;
        }

        if ($webp === null) {
            return [OptimizeResult::skipped($path, 'encode_failed', $oldBytes), null];
        }

        if (strlen($webp) >= $oldBytes) {
            return [OptimizeResult::skipped($path, 'not_smaller', $oldBytes), null];
        }

        $result = OptimizeResult::optimized($path, $this->optimizedPath($path, $targetWidth), $oldBytes, strlen($webp),
            [$width, $height], [$targetWidth, $targetHeight]);

        return [$result, $webp];
    }

    /** @return array{0: int, 1: int} */
    private function targetSize(int $width, int $height): array
    {
        $scale = min(1, self::MAX_WIDTH / $width, self::MAX_HEIGHT / $height);

        return [max(1, (int) round($width * $scale)), max(1, (int) round($height * $scale))];
    }

    private function hasMemoryFor(int $width, int $height, int $targetWidth, int $targetHeight): bool
    {
        $limit = $this->bytes((string) ini_get('memory_limit'));

        if ($limit <= 0) {
            return true; // -1 = tanpa batas
        }

        // GD truecolor ±5 byte/piksel untuk sumber + hasil, ditambah cadangan 16 MB.
        $needed = ($width * $height + $targetWidth * $targetHeight) * 5 + 16 * 1048576;

        // Pemakaian RIIL: batas memori PHP ditegakkan terhadap memori yang dialokasikan, bukan yang sedang terpakai.
        return memory_get_usage(true) + $needed < $limit;
    }

    private function encode(string $contents, int $width, int $height, int $targetWidth, int $targetHeight): ?string
    {
        $image = @imagecreatefromstring($contents);

        if ($image === false) {
            return null;
        }

        $output = $image;

        if ($targetWidth !== $width || $targetHeight !== $height) {
            $output = imagecreatetruecolor($targetWidth, $targetHeight);
            // Pertahankan transparansi PNG.
            imagealphablending($output, false);
            imagesavealpha($output, true);
            imagecopyresampled($output, $image, 0, 0, 0, 0, $targetWidth, $targetHeight, $width, $height);
            imagedestroy($image);
        } else {
            imagepalettetotruecolor($output);
            imagealphablending($output, false);
            imagesavealpha($output, true);
        }

        ob_start();
        $ok = imagewebp($output, null, self::QUALITY);
        $data = (string) ob_get_clean();
        imagedestroy($output);

        return $ok && $data !== '' ? $data : null;
    }

    private function optimizedPath(string $path, int $width): string
    {
        $info = pathinfo($path);
        $dir = ($info['dirname'] ?? '.') === '.' ? '' : $info['dirname'] . '/';

        return $dir . $info['filename'] . '-w' . $width . '.webp';
    }

    private function bytes(string $value): int
    {
        $value = trim($value);
        $unit = strtolower(substr($value, -1));
        $number = (int) $value;

        return match ($unit) {
            'g' => $number * 1073741824,
            'm' => $number * 1048576,
            'k' => $number * 1024,
            default => $number,
        };
    }
}
