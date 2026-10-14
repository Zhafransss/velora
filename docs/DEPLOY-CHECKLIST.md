# Checklist Deploy — Performa & Konfigurasi Produksi

Terakhir diperbarui: 2026-10-14. Dasar angka: audit performa 2026-10-13 (Lighthouse mobile + pengukuran server lokal).
Berlaku untuk hosting sekarang (Hostinger, `public_html`) dan VPS (SRS DEC-02). Langkah bertanda **[VPS]** hanya untuk VPS.

## 1. Setiap deploy

| # | Langkah | Perintah / cara | Kenapa |
|---|---|---|---|
| 1 | Dependensi produksi | `composer install --no-dev --optimize-autoloader` | Autoloader cepat, tanpa paket dev |
| 2 | Build aset | `npm ci && npm run build` | CSS/JS ber-hash di `public/build` |
| 3 | **Hostinger:** salin aset | `public/build` → `public_html/build`; `public/.htaccess` → `public_html/.htaccess`; `public/css/tenant.css` bila berubah | `public_html` adalah salinan manual `public/` |
| 4 | Migrasi | `php artisan migrate --force` | |
| 5 | Cache Laravel | `php artisan optimize` | Server 35–45% lebih cepat (diukur: 78 → 41–57 ms). `route:cache` berfungsi walau sitemap berupa closure |
| 6 | Restart worker **[VPS]** | `php artisan queue:restart` | Worker memuat kode saat mulai |
| 7 | Verifikasi | Lihat bagian 4 | |

Setelah mengubah `.env` di server, jalankan ulang `php artisan optimize` (config ter-cache tidak membaca `.env` lagi).
Untuk debugging: `php artisan optimize:clear`.

## 2. Sekali saja / bila belum

| # | Item | Nilai | Catatan |
|---|---|---|---|
| 1 | `.env` produksi | `APP_ENV=production`, `APP_DEBUG=false`, `LOG_LEVEL=info` (atau hapus baris; default produksi = info) | TD-07 |
| 2 | Cookie sesi | Biarkan `SESSION_SECURE_COOKIE` tidak diisi; isi `true` bila TLS diakhiri proxy (mis. Cloudflare Flexible) | Lihat `.env.example` |
| 3 | PHP | 8.3+, OPcache aktif (`opcache.enable=1`, `opcache.memory_consumption=128`, `opcache.max_accelerated_files=20000`) | Hostinger: hPanel → PHP Configuration |
| 4 | Kompresi & cache browser | Sudah di `.htaccess` (Apache/LiteSpeed). **[VPS]** Nginx: lihat bagian 3 | CSS/JS 1 tahun (berversi), gambar 30 hari |
| 5 | **Gambar di Cloudflare R2** | Bagian 6 (`MEDIA_DISK=r2`) | Upload admin langsung ke R2; tidak bergantung lagi pada salinan `public_html/storage` |
| 6 | **Optimasi gambar lama** | `php artisan images:optimize --dry-run`, lalu tanpa `--dry-run` | Setelah langkah 5 (berjalan langsung di R2). Gambar terpakai 37,7 MB → ±4,6 MB (uji lokal). Lihat bagian 5 |
| 7 | Link gambar upload (hanya bila `MEDIA_DISK=public`) | `php artisan storage:link` | Tanpa `public/storage`, semua gambar upload 404 (gambar portofolio/artikel hilang). Hostinger memakai salinan fisik `public_html/storage` |
| 8 | Penjadwal **[VPS]** | cron `* * * * * cd /var/www/velora && php artisan schedule:run` | Penyapu pesan, retensi, backup |
| 9 | Worker antrean **[VPS]** | `deploy/supervisor/velora-queue.conf` | Antrean `ai` + `whatsapp,default` |
| 10 | Konektor WhatsApp **[VPS]** | `connector/deploy/velora-connector.service` | |

## 3. [VPS] Nginx: kompresi dan cache aset

```nginx
gzip on;
gzip_types text/css application/javascript application/json image/svg+xml text/plain text/xml;

location /build/   { expires 1y; add_header Cache-Control "public, immutable"; try_files $uri =404; }
location ~* \.(?:css|js|woff2)$ { expires 1y; add_header Cache-Control "public"; try_files $uri =404; }
location /storage/ { expires 30d; add_header Cache-Control "public"; try_files $uri =404; }
```

## 4. Verifikasi setelah deploy

| Cek | Cara | Harus |
|---|---|---|
| Kompresi | `curl -sI -H "Accept-Encoding: gzip" https://veloradigitalworks.com/ \| grep -i content-encoding` | `gzip` atau `br` |
| Cache aset | `curl -sI https://veloradigitalworks.com/build/assets/<file>.css \| grep -iE "cache-control\|expires"` | max-age ±1 tahun |
| Header keamanan | `curl -sI https://veloradigitalworks.com/ \| grep -iE "x-frame\|strict-transport"` | Ada (HSTS hanya HTTPS) |
| Gambar | Buka `/articles` dan satu portofolio; DevTools → Network → Img | Tiap gambar ratusan KB, bukan MB |
| Skor halaman | PageSpeed Insights (mobile) untuk `/`, `/articles`, `/portfolio` | Sebelum perbaikan: 49–69. LCP < 4 dtk |

## 5. Optimasi gambar (`images:optimize`)

- Mengubah gambar konten (thumbnail artikel, sampul/screenshot portofolio, gambar layanan) ke WebP, lebar maks 1600 px, kualitas 80.
  Mutu diperiksa visual pada foto dan screenshot berteks: tetap tajam.
- **File asli tidak dihapus.** Database diarahkan ke file baru `<nama>-w<lebar>.webp`.
- Bekerja di disk media aktif (`MEDIA_DISK`). Dengan R2, file dibaca dan ditulis langsung di bucket.
- Khusus `MEDIA_DISK=public`: bila folder `public_html/storage` ada (Hostinger), file baru otomatis ikut ditulis ke sana. Tanpa itu gambar akan hilang di web.
  Pakai `--mirror=<path>` bila salinannya di tempat lain, `--no-mirror` bila web root membaca disk public langsung (VPS + `storage:link`).
- Setiap eksekusi menulis manifest `storage/app/private/image-optimizations/<waktu>.json`.
  Batalkan: `php artisan images:optimize --rollback=<nama-manifest>.json`.
- Upload baru lewat panel admin sudah otomatis diperkecil (di browser) dan diubah ke WebP (di server).
- Jalankan saat sepi; butuh ±1 GB memori sementara untuk foto kamera besar (diatur otomatis oleh perintah).

## 6. Gambar di Cloudflare R2 (`MEDIA_DISK=r2`)

Latar belakang, arsitektur, konfigurasi Cloudflare, log pindah DNS, dan status: [`R2-MEDIA-STORAGE.md`](R2-MEDIA-STORAGE.md).

Gambar konten website (thumbnail artikel, sampul/screenshot portofolio, gambar layanan, gambar di isi artikel) yang
diunggah lewat admin disimpan di bucket R2 dan ditampilkan dari URL publik bucket. Database tetap menyimpan path
relatif yang sama (mis. `articles/01K….webp`), jadi pindah ke/dari R2 tidak mengubah data.

### 6.1 Di dashboard Cloudflare (sekali)

| # | Langkah | Detail |
|---|---|---|
| 1 | Buat bucket | R2 → Create bucket, mis. `velora-media` (Standard) |
| 2 | Akses publik | **Domain kustom** (disarankan untuk produksi), mis. `media.veloradigitalworks.com`: lewat cache CDN Cloudflare dan tanpa batas laju. Syaratnya, domain harus menjadi zone di akun Cloudflare yang sama. DNS saat ini di Hostinger (`ns1/ns2.dns-parking.com`), jadi nameserver perlu dipindah ke Cloudflare (paket Free), dengan menyalin semua record termasuk MX email. **Atau `r2.dev`**: langsung aktif, tetapi menurut Cloudflare dibatasi laju, tanpa cache, dan hanya untuk development. **Tidak bisa dipakai di Indonesia:** `*.r2.dev` diblokir nasional (Kemkomdigi, Juni 2026; terbukti di XL dan ICONNET 2026-10-14). Wajib domain kustom |
| 3 | API token | R2 → Manage API tokens → Create: izin **Object Read & Write**, hanya untuk bucket ini. Simpan Access Key ID dan Secret (hanya tampil sekali). Endpoint: `https://<ACCOUNT_ID>.r2.cloudflarestorage.com` |
| 4 | CORS | Bucket → Settings → CORS policy (contoh di bawah). Wajib supaya preview gambar lama di form admin tampil (dimuat lewat `fetch()` lintas domain) |

```json
[
  {
    "AllowedOrigins": ["https://veloradigitalworks.com", "https://www.veloradigitalworks.com", "http://127.0.0.1:8000"],
    "AllowedMethods": ["GET", "HEAD"],
    "AllowedHeaders": ["*"],
    "MaxAgeSeconds": 3600
  }
]
```

### 6.2 Di server produksi (urutan penting)

Jalankan di server (SSH Hostinger), karena database dan file upload produksi (`storage/app/public`) ada di sana.
Salinan di komputer lokal bisa tertinggal.

```bash
# 1) Isi .env: R2_ACCESS_KEY_ID, R2_SECRET_ACCESS_KEY, R2_BUCKET, R2_ENDPOINT, R2_PUBLIC_URL.
#    MEDIA_DISK JANGAN diubah dulu (website tetap memakai gambar lokal).
php artisan config:clear
php artisan media:check r2 --origin=https://veloradigitalworks.com   # semua baris harus OK

# 2) Kirim gambar lama ke R2 (path sama, database tidak diubah). Aman diulang bila terputus.
php artisan media:sync --to=r2 --dry-run
php artisan media:sync --to=r2

# 3) Pindahkan website ke R2.
#    .env: MEDIA_DISK=r2
php artisan optimize

# 4) Disarankan: perkecil gambar lama langsung di R2 (lihat bagian 5).
php artisan images:optimize --dry-run
php artisan images:optimize
```

### 6.3 Verifikasi

| Cek | Harus |
|---|---|
| `/portfolio`, `/articles`, satu halaman layanan → klik kanan gambar → Open image in new tab | Alamat diawali `R2_PUBLIC_URL` |
| Admin → edit portofolio | Preview gambar lama tampil (bila kosong: CORS bucket belum diatur) |
| Admin → unggah gambar baru → simpan | Muncul di R2 → bucket → Objects sebagai `…-w<lebar>.webp`, dan tampil di website |

### 6.4 Membatalkan

- Ubah `MEDIA_DISK=public`, lalu `php artisan optimize`.
- Gambar yang diunggah selama memakai R2 hanya ada di R2. Salin balik dulu dengan `php artisan media:sync --from=r2 --to=public`
  (Hostinger: lalu salin juga ke `public_html/storage`).
- Proses ini tidak menghapus file di `storage/app/public`. Hapus manual hanya setelah R2 berjalan stabil.

### Catatan teknis

- R2 tidak mendukung ACL per objek dan menolak `public-read`, padahal Laravel/Filament mengirimnya untuk visibility `public`.
  Disk `r2` di `config/filesystems.php` memaksa `ACL=private`, dan akses publik diatur di level bucket. **Jangan hapus opsi ini.**
- Objek dikirim dengan `Cache-Control: public, max-age=31536000, immutable` (nama file unik, tidak pernah ditimpa).
- Biaya (harga Cloudflare, Standard): gratis sampai 10 GB-bulan penyimpanan, 1 juta operasi tulis, dan 10 juta operasi baca
  per bulan; egress (bandwidth keluar) gratis. Gambar terpakai saat ini ±38 MB (±5 MB setelah optimasi).
