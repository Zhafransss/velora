<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default Filesystem Disk
    |--------------------------------------------------------------------------
    |
    | Here you may specify the default filesystem disk that should be used
    | by the framework. The "local" disk, as well as a variety of cloud
    | based disks are available to your application for file storage.
    |
    */

    'default' => env('FILESYSTEM_DISK', 'local'),

    /*
    | Disk gambar konten website (thumbnail artikel, portofolio, layanan) yang diunggah lewat panel admin.
    | 'public' = storage/app/public (lokal); 'r2' = Cloudflare R2. Lihat docs/DEPLOY-CHECKLIST.md bagian 6.
    | Pindah ke R2: salin dulu gambar lama (`php artisan media:sync --to=r2`), baru ubah MEDIA_DISK.
    */
    'media_disk' => env('MEDIA_DISK', 'public'),

    /*
    |--------------------------------------------------------------------------
    | Filesystem Disks
    |--------------------------------------------------------------------------
    |
    | Below you may configure as many filesystem disks as necessary, and you
    | may even configure multiple disks for the same driver. Examples for
    | most supported storage drivers are configured here for reference.
    |
    | Supported drivers: "local", "ftp", "sftp", "s3"
    |
    */

    'disks' => [

        // Tujuan backup (NFR-005). Produksi: isi BACKUP_S3_* (Cloudflare R2 / Backblaze B2 / AWS S3 / MinIO).
        // Tanpa BACKUP_S3_BUCKET, backup hanya ditulis ke folder lokal (BUKAN di luar server; hanya untuk pengembangan).
        'backup' => env('BACKUP_S3_BUCKET') ? [
            'driver' => 's3',
            'key' => env('BACKUP_S3_KEY'),
            'secret' => env('BACKUP_S3_SECRET'),
            'region' => env('BACKUP_S3_REGION', 'auto'),
            'bucket' => env('BACKUP_S3_BUCKET'),
            'endpoint' => env('BACKUP_S3_ENDPOINT'),
            'use_path_style_endpoint' => (bool) env('BACKUP_S3_PATH_STYLE', true),
            'throw' => true,
        ] : [
            'driver' => 'local',
            'root' => storage_path('app/backups-local'),
            'throw' => true,
        ],

        'local' => [
            'driver' => 'local',
            'root' => storage_path('app/private'),
            'serve' => true,
            'throw' => false,
            'report' => false,
        ],

        'public' => [
            'driver' => 'local',
            'root' => storage_path('app/public'),
            'url' => rtrim(env('APP_URL', 'http://localhost'), '/').'/storage',
            'visibility' => 'public',
            'throw' => false,
            'report' => false,
        ],

        // Cloudflare R2 untuk gambar konten website (MEDIA_DISK=r2).
        'r2' => [
            'driver' => 's3',
            'key' => env('R2_ACCESS_KEY_ID'),
            'secret' => env('R2_SECRET_ACCESS_KEY'),
            'region' => 'auto',
            'bucket' => env('R2_BUCKET'),
            // https://<ACCOUNT_ID>.r2.cloudflarestorage.com
            'endpoint' => env('R2_ENDPOINT'),
            // URL publik bucket (domain kustom, mis. https://media.veloradigitalworks.com, atau https://pub-xxx.r2.dev).
            'url' => env('R2_PUBLIC_URL'),
            'use_path_style_endpoint' => true,
            'options' => [
                // R2 tidak mendukung ACL per objek dan MENOLAK 'public-read' (NotImplemented), sedangkan Laravel/Filament
                // mengirimnya untuk visibility 'public'. Paksa 'private' (diterima R2); akses publik diatur di level bucket.
                'ACL' => 'private',
                // Nama file unik (ULID / -w<lebar>.webp) dan tidak pernah ditimpa, jadi aman di-cache lama.
                'CacheControl' => 'public, max-age=31536000, immutable',
            ],
            'throw' => true,
            'report' => false,
        ],

        's3' => [
            'driver' => 's3',
            'key' => env('AWS_ACCESS_KEY_ID'),
            'secret' => env('AWS_SECRET_ACCESS_KEY'),
            'region' => env('AWS_DEFAULT_REGION'),
            'bucket' => env('AWS_BUCKET'),
            'url' => env('AWS_URL'),
            'endpoint' => env('AWS_ENDPOINT'),
            'use_path_style_endpoint' => env('AWS_USE_PATH_STYLE_ENDPOINT', false),
            'throw' => false,
            'report' => false,
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Symbolic Links
    |--------------------------------------------------------------------------
    |
    | Here you may configure the symbolic links that will be created when the
    | `storage:link` Artisan command is executed. The array keys should be
    | the locations of the links and the values should be their targets.
    |
    */

    'links' => [
        public_path('storage') => storage_path('app/public'),
    ],

];
