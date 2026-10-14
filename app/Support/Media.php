<?php

namespace App\Support;

use Illuminate\Support\Facades\Storage;

/**
 * Satu pintu untuk gambar konten website: disk tempat menyimpan (config filesystems.media_disk: 'public' atau 'r2')
 * dan URL publiknya. Kolom database hanya berisi path relatif (mis. "articles/01K...webp"), sehingga pindah disk
 * cukup dengan menyalin file (media:sync) lalu mengubah MEDIA_DISK, tanpa mengubah data.
 */
final class Media
{
    public static function disk(): string
    {
        return (string) config('filesystems.media_disk', 'public');
    }

    public static function url(?string $path, ?string $fallback = null): ?string
    {
        if (blank($path)) {
            return $fallback;
        }

        $path = ltrim($path, '/');

        // Disk lokal: tetap asset() seperti sebelumnya (host request saat ini). URL bawaan disk public memakai APP_URL,
        // yang bisa berbeda dari host yang dibuka (lokal dengan .env produksi, www vs non-www).
        if (self::disk() === 'public') {
            return asset('storage/' . $path);
        }

        return Storage::disk(self::disk())->url($path);
    }

    /** Pesan bila disk tidak dikenal atau disk S3/R2 belum lengkap; null bila siap dipakai. */
    public static function configProblem(string $disk): ?string
    {
        $config = config("filesystems.disks.{$disk}");

        if (! is_array($config)) {
            return "Disk tidak dikenal: {$disk}.";
        }

        if (($config['driver'] ?? null) !== 's3') {
            return null;
        }

        $empty = array_keys(array_filter([
            'key' => $config['key'] ?? null,
            'secret' => $config['secret'] ?? null,
            'bucket' => $config['bucket'] ?? null,
            'endpoint' => $config['endpoint'] ?? null,
            'url' => $config['url'] ?? null,
        ], 'blank'));

        return $empty === [] ? null : "Disk {$disk} belum lengkap (kosong: " . implode(', ', $empty) . '). '
            . 'Untuk R2 isi R2_ACCESS_KEY_ID, R2_SECRET_ACCESS_KEY, R2_BUCKET, R2_ENDPOINT, R2_PUBLIC_URL di .env.';
    }
}
