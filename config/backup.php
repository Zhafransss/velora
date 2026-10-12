<?php

/*
 * Backup database (NFR-005). Berkas dienkripsi di server SEBELUM keluar dari server (AES-256-GCM), lalu diunggah ke
 * disk "backup" — untuk produksi harus penyimpanan DI LUAR server (S3-kompatibel: Cloudflare R2, Backblaze B2, AWS).
 * Auth WhatsApp ikut tercakup karena tersimpan di MySQL (sudah terenkripsi sendiri oleh konektor, DEC-17).
 *
 * TIDAK tercakup (simpan terpisah): file .env, AUTH_ENCRYPTION_KEYS, BACKUP_ENCRYPTION_KEY — kunci tidak boleh
 * berada di tempat yang sama dengan datanya.
 */
return [

    'disk' => env('BACKUP_DISK', 'backup'),

    // Folder di dalam disk.
    'path' => 'backups',

    // Kunci enkripsi berkas backup (base64 32 byte). Buat: php artisan backup:key. Cadangkan di password manager.
    'encryption_key' => env('BACKUP_ENCRYPTION_KEY'),

    // Masa simpan. Dibatasi maksimal 30 hari (DEC-15: data percakapan tidak boleh bertahan di backup lebih lama).
    'retention_days' => (int) env('BACKUP_RETENTION_DAYS', 14),
    'max_retention_days' => 30,

    'mysqldump' => env('BACKUP_MYSQLDUMP', 'mysqldump'),
    'mysql' => env('BACKUP_MYSQL', 'mysql'),

    // Database sementara untuk uji restore. WAJIB berbeda dari database aplikasi dan mengandung "restore";
    // dibuat lalu DIHAPUS oleh backup:verify. Pengguna MySQL perlu hak CREATE/DROP untuk nama ini saja.
    'verify_database' => env('BACKUP_VERIFY_DATABASE'),

    // backup:status gagal bila backup sukses terakhir lebih lama dari ini, atau restore belum teruji dalam N hari.
    'max_age_hours' => (int) env('BACKUP_MAX_AGE_HOURS', 26),
    'max_verify_age_days' => (int) env('BACKUP_MAX_VERIFY_AGE_DAYS', 8),

    'timeout_seconds' => (int) env('BACKUP_TIMEOUT', 1800),
];
