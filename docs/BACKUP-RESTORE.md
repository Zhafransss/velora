# Backup & Restore (NFR-005)

Yang dicadangkan: **seluruh database MySQL** (termasuk kredensial WhatsApp, yang sudah terenkripsi sendiri oleh konektor — DEC-17).
Berkas dienkripsi **di server** (AES-256-GCM) sebelum diunggah ke penyimpanan **di luar server**.

| Hal | Nilai |
|---|---|
| Jadwal backup | Harian 01:30 (`backup:run`) |
| Uji restore otomatis | Mingguan, Minggu 04:30 (`backup:verify`) |
| Masa simpan | 14 hari (`BACKUP_RETENTION_DAYS`), **maksimal 30** (DEC-15) |
| Format | `velora-YYYYMMDD-HHMMSS.vbk` + `.vbk.json` (manifest: nama tabel & jumlah baris, tanpa isi data) |
| Yang TIDAK ikut | `.env`, `AUTH_ENCRYPTION_KEYS`, `BACKUP_ENCRYPTION_KEY`, file unggahan di `storage/app/public` |

> **Dua kunci, dua tempat.** `BACKUP_ENCRYPTION_KEY` membuka backup; `AUTH_ENCRYPTION_KEYS` membuka kredensial WhatsApp di dalamnya.
> Simpan keduanya di password manager, **terpisah** dari backup dan dari server. Tanpa `BACKUP_ENCRYPTION_KEY`, backup tidak bisa dibuka siapa pun
> (termasuk kami). Tanpa `AUTH_ENCRYPTION_KEYS`, data pulih tetapi setiap nomor WhatsApp harus scan QR ulang.

## 1. Pasang (sekali, di VPS)

1. **Siapkan bucket S3-kompatibel di luar server.** Contoh: Cloudflare R2 atau Backblaze B2 (murah; ada free tier). Buat bucket **privat**,
   lalu API token yang hanya boleh baca/tulis/hapus bucket itu.
2. Isi `.env`:
   ```
   BACKUP_ENCRYPTION_KEY=...          # php artisan backup:key
   BACKUP_S3_BUCKET=nama-bucket
   BACKUP_S3_KEY=...
   BACKUP_S3_SECRET=...
   BACKUP_S3_ENDPOINT=https://<account>.r2.cloudflarestorage.com   # sesuai penyedia
   BACKUP_S3_REGION=auto
   BACKUP_VERIFY_DATABASE=veloradigital_restore_check
   ```
3. Pengguna MySQL untuk uji restore butuh hak `CREATE`/`DROP` pada database `veloradigital_restore_check` saja:
   ```sql
   GRANT ALL PRIVILEGES ON `veloradigital_restore_check`.* TO 'velora_app'@'127.0.0.1';
   ```
4. Pastikan `mysqldump` dan `mysql` ada di PATH (atau isi `BACKUP_MYSQLDUMP` / `BACKUP_MYSQL`) dan cron `schedule:run` aktif.
5. Jalankan sekali secara manual dan pastikan lulus:
   ```
   php artisan backup:run
   php artisan backup:verify
   php artisan backup:status
   ```
6. **Pasang pemantau:** cron yang menjalankan `php artisan backup:status` tiap pagi dan mengirim email bila exit code ≠ 0
   (backup terakhir > 26 jam, atau restore belum teruji > 8 hari). Tanpa ini, backup yang berhenti tidak ada yang tahu.

## 2. Cara kerja pengamanan

- Dump `--single-transaction` (konsisten tanpa mengunci tabel), lalu gzip, lalu AES-256-GCM per potongan 64 KB dengan nomor urut dan penanda
  "potongan terakhir". Berkas yang **terpotong, diubah satu bit, atau potongannya dibuang/ditukar** ditolak saat dibuka.
- Dump yang tidak berakhir dengan penanda `Dump completed` dianggap gagal dan tidak diunggah.
- Setelah unggah, ukuran di tujuan dibandingkan; tidak sama = dibatalkan.
- `backup:verify`: unduh → cek SHA-256 → dekripsi → impor ke database **sementara** → bandingkan jumlah baris tiap tabel dengan manifest → hapus
  database sementara. Nama database sementara wajib mengandung `restore` dan berbeda dari database aplikasi (tidak mungkin menimpa data asli).
- Backup terbaru tidak pernah dihapus oleh retensi.

## 3. Pemulihan bencana (server baru / data rusak)

1. Siapkan server baru: PHP 8.3, MySQL, kode aplikasi, `.env` (dari password manager), **`BACKUP_ENCRYPTION_KEY`**.
2. Unduh backup terbaru yang sehat dari bucket (`velora-….vbk`).
3. Buka menjadi SQL: `php artisan backup:decrypt velora-….vbk /aman/pulih.sql`
   (gagal bila kunci salah atau berkas rusak — coba backup sebelumnya).
4. Buat database kosong dan impor: `mysql -u <user> -p <database> < /aman/pulih.sql`
5. **Hapus `pulih.sql`** (berisi data tanpa enkripsi).
6. Pasang `AUTH_ENCRYPTION_KEYS` yang **sama** di `connector/.env` agar sesi WhatsApp terbaca. Bila kunci hilang: kosongkan tabel
   `wa_auth_creds` dan `wa_auth_keys`, lalu scan QR ulang tiap nomor.
7. Jalankan `php artisan migrate --force`, nyalakan konektor, queue worker, dan scheduler. Cek `php artisan backup:status`.

Target (usulan, belum disepakati): kehilangan data maksimal 24 jam (RPO), pemulihan di bawah 2 jam (RTO) — **belum pernah diukur di VPS nyata**.

## 4. Catatan privasi (DEC-15)

Percakapan lebih dari 30 hari dihapus dari database setiap malam (`conversations:prune`, 02:30), tetapi **masih ada di backup yang lebih lama**.
Karena masa simpan backup ≤ 30 hari (bawaan 14), data percakapan benar-benar hilang paling lambat sekitar 60 hari setelah dibuat
(30 hari di database + masa simpan backup maksimal 30 hari). Permintaan penghapusan data harus memperhitungkan ini.

## 5. Yang belum tercakup

- File unggahan website (`storage/app/public`: gambar portofolio dll.). Bukan data SaaS; cadangkan lewat sinkronisasi terpisah bila perlu.
- Uji restore masih di mesin yang sama dengan produksi (database sementara). Latihan pemulihan penuh ke **server lain** perlu dilakukan sekali setelah VPS ada.
