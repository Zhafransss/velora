# Deploy ke VPS (Contabo) — Runbook

Terakhir diperbarui: 2026-10-14. Menggantikan jalur Hostinger untuk **website + aplikasi** (FR-017, DEC-02, DEC-21).
**Email tetap di Hostinger** (record MX tidak diubah; aplikasi mengirim lewat SMTP Hostinger).

> **Mode sekarang: VPS sementara + domain sementara `fachrullahzhafran.site`** (noindex, tidak diindeks Google).
> `veloradigitalworks.com` **tetap di Hostinger** dan tidak disentuh. Pindah ke VPS final + domain utama: **Fase 9**.
> Semua perintah memakai variabel `$DOMAIN` (diatur di Fase 1.2), jadi runbook yang sama dipakai lagi nanti.

| Komponen | Di VPS |
|---|---|
| Server | Contabo, Ubuntu 24.04 LTS, 4 vCPU, 8 GB RAM, 100 GB SSD, 1 snapshot (**sementara**) |
| Domain | `fachrullahzhafran.site` + `www` (sementara, DNS di Cloudflare, `noindex`) → nanti `veloradigitalworks.com` |
| Web | Nginx + PHP 8.3-FPM (template `deploy/nginx/velora.conf` dipasang oleh `deploy/nginx/install.sh`, `deploy/php/99-velora.ini`) |
| Database | MariaDB 10.11 (sama keluarga dengan MariaDB lokal XAMPP → impor tanpa konversi) |
| Antrean | Supervisor: 3 worker `ai` + 2 worker `whatsapp,default` (`deploy/supervisor/velora-queue.conf`) |
| Penjadwal | Cron `schedule:run` tiap menit (jam pada jadwal = WIB, `app.schedule_timezone`) |
| WhatsApp | Konektor Node 20 sebagai layanan systemd, hanya `127.0.0.1` (`connector/deploy/velora-connector.service`) |
| Gambar | Cloudflare R2 `media.veloradigitalworks.com` (sudah jalan, lihat `R2-MEDIA-STORAGE.md`) |
| SSL | Let's Encrypt lewat DNS Cloudflare (bisa dibuat **sebelum** DNS pindah) |
| Backup | `backup:run` harian ke bucket R2 terpisah (lihat `BACKUP-RESTORE.md`) |

**Cara memakai dokumen ini:** kerjakan fase berurutan. Kotak `bash` dijalankan di VPS (kecuali tertulis
**[PC]** = PowerShell di komputer Anda). Ganti `<VPS_IP>` dengan IP VPS dari email/panel Contabo.
Simpan semua rahasia di password manager, **jangan** di chat atau di git.

Perkiraan waktu: 3–4 jam untuk Fase 0–8.

---

## Fase 0 — Persiapan (sebelum menyentuh VPS)

### 0.1 Rahasia yang perlu disiapkan

| Rahasia | Sumber | Catatan |
|---|---|---|
| `APP_KEY` | **Salin dari `.env` lokal**, persis | Wajib sama: secret MFA admin di database dienkripsi dengan kunci ini. Kunci baru = semua admin harus atur ulang MFA |
| `R2_*` (5 nilai) | `.env` lokal | Bucket `veloradigital` + `R2_PUBLIC_URL=https://media.veloradigitalworks.com` |
| `MAIL_PASSWORD` | Password mailbox `team@veloradigitalworks.com` (hPanel Hostinger → Email) | |
| `GEMINI_API_KEY`, `GROQ_API_KEY` | `.env` lokal / dashboard penyedia | |
| `RECAPTCHA_SITE_KEY`, `RECAPTCHA_SECRET_KEY` | Google reCAPTCHA Admin | **Tambahkan `fachrullahzhafran.site`** ke daftar domain key (Settings → Domains); tanpa ini form daftar & kontak gagal |
| Password DB `velora_app` dan `velora_connector` | Buat baru (≥ 24 karakter acak) | |
| `CONNECTOR_TOKEN`, `CONNECTOR_WEBHOOK_SECRET` | Buat baru (Fase 6) | Nilai sama di `.env` Laravel dan `connector/.env` |
| `AUTH_ENCRYPTION_KEYS` | Buat baru (`npm run keygen`, Fase 6) | **Cadangkan terpisah.** Hilang = semua nomor WhatsApp scan QR ulang |
| `BACKUP_ENCRYPTION_KEY` | Buat baru (`php artisan backup:key`, Fase 7) | **Cadangkan terpisah.** Hilang = backup tidak bisa dibuka |
| Token R2 untuk backup | Fase 0.3 | |
| Token API Cloudflare (DNS) | Fase 0.5 | Hanya untuk membuat/memperpanjang sertifikat SSL |

### 0.2 Branch produksi

**VPS sementara menjalankan branch `development`** (keputusan pemilik). Rilis = merge ke `development` lalu
`bash deploy/deploy.sh` (skrip memakai branch yang sedang aktif di server). VPS final nanti memakai `main`.
File `deploy/` dan runbook ini harus sudah ter-merge ke `development` sebelum Fase 4.

### 0.3 Bucket R2 untuk backup (terpisah dari gambar)

1. Cloudflare → R2 → **Create bucket** `velora-backup` (lokasi APAC). **Jangan** aktifkan akses publik/domain.
2. R2 → Manage API tokens → **Create Account API token**: Object Read & Write, hanya bucket `velora-backup`.
   Simpan Access Key ID + Secret Access Key.

### 0.4 Domain sementara di Cloudflare

Saat ini DNS `fachrullahzhafran.site` **tidak berfungsi** (dicek 2026-10-14: SERVFAIL / *lame delegation* — nameserver
yang ditunjuk registry menolak menjawab). Pindahkan pengelolaannya ke Cloudflare, sama seperti `veloradigitalworks.com`:

1. Cloudflare → **Domains → Add domain → Connect a domain** → `fachrullahzhafran.site` → paket **Free**.
   Import DNS: biarkan otomatis (kemungkinan kosong). AI crawlers: *Allow*; **Bot Preference Sync: off**.
2. Catat 2 nameserver Cloudflare → di **panel tempat Anda membeli domain**, ganti nameserver (hapus yang lama) → Save.
3. Tunggu status **Active** di Cloudflare (menit–jam).
4. **DNS → Records → Add record** (keduanya **DNS only**, awan abu-abu):

   | Type | Name | Content |
   |---|---|---|
   | A | `@` | `<VPS_IP>` |
   | CNAME | `www` | `fachrullahzhafran.site` |

5. **R2 → bucket `veloradigital` → Settings → CORS Policy → Edit**: tambahkan `"https://fachrullahzhafran.site"` ke
   `AllowedOrigins` (agar preview gambar di form admin tampil). Origin lama dibiarkan.

### 0.5 Token API Cloudflare untuk SSL

Cloudflare → ikon profil → **My Profile → API Tokens → Create Token** → template **Edit zone DNS** →
Zone Resources: *Include → Specific zone → fachrullahzhafran.site* (tambahkan juga `veloradigitalworks.com` agar token
yang sama dipakai saat pindah ke domain utama) → Create. Simpan token-nya.

### 0.6 Export database lokal **[PC]**

Opsional dulu: hapus data uji yang tidak ingin ikut ke produksi lewat `/admin` (mis. akun/workspace percobaan).

```powershell
# --result-file (bukan ">"): redirect PowerShell menulis UTF-16 dan file tidak bisa diimpor.
C:\xampp\mysql\bin\mysqldump.exe -u root --single-transaction --routines --triggers --default-character-set=utf8mb4 --result-file="$env:USERPROFILE\velora.sql" veloradigital
```

Pesan kontak yang hanya masuk ke website live Hostinger **tidak** ada di database lokal. Bila perlu, catat dari
`/admin` Hostinger sebelum cutover.

### 0.7 SSH key **[PC]**

```powershell
ssh-keygen -t ed25519 -C "velora-vps"      # Enter untuk lokasi bawaan; isi passphrase
type $env:USERPROFILE\.ssh\id_ed25519.pub   # kunci publik, dipakai di Fase 1
```

---

## Fase 1 — Akses dan pengamanan server (NFR-004)

### 1.1 Login pertama dan pembaruan

**[PC]** `ssh root@<VPS_IP>` (password root dari email Contabo), lalu:

```bash
lsb_release -ds                       # harus Ubuntu 24.04 (22.04: PHP 8.3 butuh PPA ondrej/php)
apt update && apt -y full-upgrade
reboot                                # bila diminta; login lagi
```

Zona waktu server dibiarkan **UTC** (aplikasi menyimpan waktu dalam UTC; jadwal memakai WIB lewat konfigurasi).

### 1.2 User `deploy` (admin harian) dengan SSH key

```bash
adduser deploy                        # isi password (dipakai untuk sudo)
usermod -aG sudo deploy
mkdir -p /home/deploy/.ssh && chmod 700 /home/deploy/.ssh
nano /home/deploy/.ssh/authorized_keys   # tempel isi id_ed25519.pub dari 0.7
chmod 600 /home/deploy/.ssh/authorized_keys && chown -R deploy:deploy /home/deploy/.ssh
```

**[PC]** Di jendela terminal **baru** (jangan tutup sesi root): `ssh deploy@<VPS_IP>` lalu `sudo whoami` → `root`.
Lanjut hanya bila berhasil. Lalu, masih sebagai `deploy`, simpan domain untuk semua perintah berikutnya:

```bash
echo 'export DOMAIN=fachrullahzhafran.site' >> ~/.bashrc && source ~/.bashrc && echo $DOMAIN
```

Nanti di VPS final cukup isi `veloradigitalworks.com`.

### 1.3 Matikan login root dan password

```bash
sudo tee /etc/ssh/sshd_config.d/99-velora.conf >/dev/null <<'EOF'
PermitRootLogin no
PasswordAuthentication no
KbdInteractiveAuthentication no
EOF
sudo sshd -t && sudo systemctl reload ssh
```

Terkunci di luar? Pakai **VNC console** di panel Contabo.

### 1.4 Firewall, fail2ban, pembaruan otomatis, swap

```bash
sudo ufw allow OpenSSH && sudo ufw allow 80,443/tcp && sudo ufw --force enable && sudo ufw status
sudo apt -y install fail2ban unattended-upgrades
sudo dpkg-reconfigure -plow unattended-upgrades          # pilih "Yes"
sudo fail2ban-client status sshd                         # jail sshd aktif bawaan Ubuntu

# Swap 2 GB: cadangan saat build composer/npm
sudo fallocate -l 2G /swapfile && sudo chmod 600 /swapfile && sudo mkswap /swapfile && sudo swapon /swapfile
echo '/swapfile none swap sw 0 0' | sudo tee -a /etc/fstab
```

MySQL (3306) dan konektor (3100) **tidak** dibuka: keduanya hanya mendengar di 127.0.0.1.

---

## Fase 2 — Instal perangkat lunak

```bash
sudo apt -y install nginx mariadb-server supervisor git unzip curl ca-certificates netcat-openbsd \
  php8.3-fpm php8.3-cli php8.3-mysql php8.3-mbstring php8.3-xml php8.3-intl php8.3-curl \
  php8.3-bcmath php8.3-gd php8.3-zip php8.3-gmp php8.3-opcache php8.3-readline \
  certbot python3-certbot-dns-cloudflare

php -v                                                    # 8.3.x
php -m | grep -E '^(pcntl|gd|intl|mbstring|pdo_mysql|zip)$'   # pcntl wajib untuk timeout worker
php -r 'var_dump(gd_info()["WebP Support"]);'             # true (optimasi gambar ke WebP)

# Composer (installer resmi + verifikasi tanda tangan)
cd /tmp
EXPECTED="$(curl -fsSL https://composer.github.io/installer.sig)"
php -r "copy('https://getcomposer.org/installer', 'composer-setup.php');"
[ "$EXPECTED" = "$(php -r "echo hash_file('sha384', 'composer-setup.php');")" ] && echo OK || echo "HASH SALAH - berhenti"
sudo php composer-setup.php --install-dir=/usr/local/bin --filename=composer && rm composer-setup.php
composer --version

# Node 20 (konektor WhatsApp butuh >= 20; apt Ubuntu 24.04 masih 18)
curl -fsSL https://deb.nodesource.com/setup_20.x | sudo -E bash -
sudo apt -y install nodejs && node -v
```

**Cek SMTP Hostinger dari VPS** (beberapa penyedia/ISP memblokir port email):

```bash
nc -vz -w 10 smtp.hostinger.com 465     # harus "succeeded"
nc -vz -w 10 smtp.hostinger.com 587
```

Bila keduanya gagal: buka tiket ke Contabo minta port SMTP keluar dibuka. Tanpa ini undangan akun, verifikasi email,
dan reset password tidak terkirim.

---

## Fase 3 — Database

### 3.1 Amankan MariaDB dan buat database + user

```bash
sudo mariadb-secure-installation
# unix_socket: Y (root login lewat sudo), ganti password root: n, hapus anonymous: Y,
# larang root remote: Y, hapus test DB: Y, reload: Y
sudo mariadb
```

```sql
CREATE DATABASE veloradigital CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
-- Database kosong tempat backup:verify mencoba restore tiap minggu.
CREATE DATABASE veloradigital_restore_check CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

CREATE USER 'velora_app'@'localhost' IDENTIFIED BY '<PASSWORD_DB_APP>';
GRANT ALL PRIVILEGES ON veloradigital.* TO 'velora_app'@'localhost';
GRANT ALL PRIVILEGES ON veloradigital_restore_check.* TO 'velora_app'@'localhost';
FLUSH PRIVILEGES;
EXIT;
```

### 3.2 Impor database lokal

**[PC]** `scp "$env:USERPROFILE\velora.sql" deploy@<VPS_IP>:/home/deploy/`

```bash
sudo mariadb veloradigital < ~/velora.sql
# Data sementara dari komputer lokal TIDAK boleh ikut berjalan: job antrean yang tertunda bisa mengirim WhatsApp.
sudo mariadb veloradigital -e "TRUNCATE jobs; TRUNCATE job_batches; TRUNCATE failed_jobs; TRUNCATE sessions; TRUNCATE cache; TRUNCATE cache_locks; TRUNCATE password_reset_tokens; TRUNCATE account_invitation_tokens;"
sudo mariadb veloradigital -e "SELECT COUNT(*) AS articles FROM articles; SELECT COUNT(*) AS tenants FROM tenants;"
shred -u ~/velora.sql        # file berisi data pelanggan; hapus setelah impor
```

### 3.3 User khusus konektor WhatsApp (hak minimal)

```bash
sudo mariadb
```

```sql
CREATE USER 'velora_connector'@'127.0.0.1' IDENTIFIED BY '<PASSWORD_DB_CONNECTOR>';
GRANT SELECT, INSERT, UPDATE, DELETE ON veloradigital.wa_auth_creds TO 'velora_connector'@'127.0.0.1';
GRANT SELECT, INSERT, UPDATE, DELETE ON veloradigital.wa_auth_keys  TO 'velora_connector'@'127.0.0.1';
FLUSH PRIVILEGES;
EXIT;
```

---

## Fase 4 — Kode aplikasi

### 4.1 Deploy key GitHub (akses baca saja)

```bash
ssh-keygen -t ed25519 -C "velora-vps-deploy" -f ~/.ssh/github_velora -N ""
cat >> ~/.ssh/config <<'EOF'
Host github.com
  IdentityFile ~/.ssh/github_velora
  IdentitiesOnly yes
EOF
cat ~/.ssh/github_velora.pub
```

GitHub → repo `Zhafransss/velora` → **Settings → Deploy keys → Add deploy key**: tempel kunci di atas,
**jangan** centang *Allow write access*. Lalu `ssh -T git@github.com` → "successfully authenticated".

### 4.2 Clone, izin folder, dependensi

```bash
sudo mkdir -p /var/www/velora && sudo chown deploy:www-data /var/www/velora
git clone -b development git@github.com:Zhafransss/velora.git /var/www/velora
cd /var/www/velora

# Kode milik deploy; storage & cache ditulis PHP-FPM/worker (www-data).
sudo chown -R deploy:www-data /var/www/velora
sudo chmod -R g+w storage bootstrap/cache
sudo find storage bootstrap/cache -type d -exec chmod g+s {} \;

composer install --no-dev --optimize-autoloader --no-interaction
npm ci --no-audit --no-fund && npm run build
```

### 4.3 `.env` produksi

```bash
cp .env.example .env && nano .env
sudo chown deploy:www-data .env && chmod 640 .env
```

Ubah/isi baris berikut (yang tidak disebut biarkan seperti `.env.example`):

```dotenv
APP_NAME="Velora Digital"
APP_ENV=production
APP_KEY=<SALIN DARI .env LOKAL>
APP_DEBUG=false
APP_URL=https://fachrullahzhafran.site
LOG_LEVEL=info

DB_CONNECTION=mysql
DB_HOST=localhost
DB_PORT=3306
DB_DATABASE=veloradigital
DB_USERNAME=velora_app
DB_PASSWORD=<PASSWORD_DB_APP>

MAIL_MAILER=smtp
MAIL_SCHEME=smtps
MAIL_HOST=smtp.hostinger.com
MAIL_PORT=465
MAIL_USERNAME=team@veloradigitalworks.com
MAIL_PASSWORD=<PASSWORD MAILBOX>
MAIL_FROM_ADDRESS="team@veloradigitalworks.com"
MAIL_FROM_NAME="Velora Digital"
MAIL_EHLO_DOMAIN=veloradigitalworks.com

MEDIA_DISK=r2
R2_ACCESS_KEY_ID=<DARI .env LOKAL>
R2_SECRET_ACCESS_KEY=<DARI .env LOKAL>
R2_BUCKET=veloradigital
R2_ENDPOINT=<DARI .env LOKAL>
R2_PUBLIC_URL=https://media.veloradigitalworks.com

GEMINI_API_KEY=<...>
GROQ_API_KEY=<...>

CONNECTOR_URL=http://127.0.0.1:3100
CONNECTOR_TOKEN=<Fase 6.3>
CONNECTOR_WEBHOOK_SECRET=<Fase 6.3>

RECAPTCHA_ENABLED=true
RECAPTCHA_SITE_KEY=<...>
RECAPTCHA_SECRET_KEY=<...>

# Fase 7
BACKUP_ENCRYPTION_KEY=<Fase 7>
BACKUP_S3_BUCKET=velora-backup
BACKUP_S3_KEY=<token R2 backup>
BACKUP_S3_SECRET=<token R2 backup>
BACKUP_S3_ENDPOINT=<sama dengan R2_ENDPOINT>
BACKUP_S3_REGION=auto
BACKUP_VERIFY_DATABASE=veloradigital_restore_check
```

`DB_HOST=localhost` = lewat socket Unix (cocok dengan user `'velora_app'@'localhost'`).
`SESSION_SECURE_COOKIE` **jangan** diisi (otomatis Secure di HTTPS).

### 4.4 Inisialisasi

```bash
sudo -u www-data php artisan migrate --force          # database lokal sudah termigrasi: biasanya "Nothing to migrate"
sudo -u www-data php artisan optimize
sudo -u www-data php artisan media:check r2 --origin=https://$DOMAIN   # semua baris OK (CORS: Fase 0.4 langkah 5)
```

Semua perintah `artisan` di server dijalankan sebagai `www-data` (`sudo -u www-data`) agar file log/cache
pemiliknya sama dengan PHP-FPM dan worker.

---

## Fase 5 — Nginx, PHP-FPM, SSL

### 5.1 PHP-FPM

```bash
sudo cp /var/www/velora/deploy/php/99-velora.ini /etc/php/8.3/fpm/conf.d/99-velora.ini
sudo systemctl restart php8.3-fpm
```

### 5.2 Sertifikat SSL lewat DNS Cloudflare (sebelum DNS pindah)

```bash
sudo mkdir -p /root/.secrets
echo 'dns_cloudflare_api_token = <TOKEN API CLOUDFLARE 0.5>' | sudo tee /root/.secrets/cloudflare.ini >/dev/null
sudo chmod 600 /root/.secrets/cloudflare.ini

sudo certbot certonly --dns-cloudflare --dns-cloudflare-credentials /root/.secrets/cloudflare.ini \
  --dns-cloudflare-propagation-seconds 30 \
  -d $DOMAIN -d www.$DOMAIN \
  -m team@veloradigitalworks.com --agree-tos -n --deploy-hook "systemctl reload nginx"

sudo certbot renew --dry-run                      # perpanjangan otomatis (timer systemd) berfungsi
```

### 5.3 Situs Nginx

```bash
# Domain sementara: --noindex (header X-Robots-Tag). Untuk domain utama nanti: tanpa --noindex.
sudo bash /var/www/velora/deploy/nginx/install.sh $DOMAIN --noindex
curl -sI https://$DOMAIN | grep -iE "^HTTP|x-robots-tag"     # HTTP/2 200 + x-robots-tag: noindex, nofollow
```

---

## Fase 6 — Worker antrean, penjadwal, konektor WhatsApp

### 6.1 Worker antrean (Supervisor)

```bash
sudo cp /var/www/velora/deploy/supervisor/velora-queue.conf /etc/supervisor/conf.d/
sudo supervisorctl reread && sudo supervisorctl update && sudo supervisorctl status   # 5 proses RUNNING
```

### 6.2 Penjadwal (cron)

```bash
echo '* * * * * cd /var/www/velora && php artisan schedule:run >> /dev/null 2>&1' | sudo crontab -u www-data -
sudo -u www-data php artisan schedule:list      # ekspresi tampil dalam UTC server: backup:run "30 18 * * *" = 01:30 WIB
```

### 6.3 Konektor WhatsApp

**[PC] Matikan dulu konektor & worker antrean di komputer lokal.** Satu nomor WhatsApp tidak boleh tersambung dari
dua tempat. Sesi lokal (mode `file`) tidak dipindah: tiap nomor **scan QR ulang sekali** di VPS (DEC-17).

```bash
# User sistem khusus konektor (tanpa login)
sudo useradd --system --home /var/lib/velora --shell /usr/sbin/nologin velora
sudo mkdir -p /var/lib/velora && sudo chown velora:velora /var/lib/velora

cd /var/www/velora/connector
npm ci --omit=dev --no-audit --no-fund
npm run keygen                        # -> nilai AUTH_ENCRYPTION_KEYS; CADANGKAN di password manager
openssl rand -hex 32                  # -> CONNECTOR_TOKEN
openssl rand -hex 32                  # -> WEBHOOK_SECRET (= CONNECTOR_WEBHOOK_SECRET di .env Laravel)

cp .env.example .env && nano .env
```

Isi `connector/.env`:

```dotenv
CONNECTOR_HOST=127.0.0.1
CONNECTOR_PORT=3100
CONNECTOR_TOKEN=<token di atas>
AUTH_STORE=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=veloradigital
DB_USERNAME=velora_connector
DB_PASSWORD=<PASSWORD_DB_CONNECTOR>
AUTH_ENCRYPTION_KEYS=<hasil npm run keygen>
WEBHOOK_URL=http://127.0.0.1:8080/webhooks/whatsapp
WEBHOOK_SECRET=<secret di atas>
LOG_LEVEL=info
```

```bash
sudo chown velora:velora .env && sudo chmod 600 .env

# Samakan di Laravel: CONNECTOR_TOKEN dan CONNECTOR_WEBHOOK_SECRET
nano /var/www/velora/.env
sudo -u www-data php /var/www/velora/artisan optimize
sudo supervisorctl restart all

sudo cp /var/www/velora/connector/deploy/velora-connector.service /etc/systemd/system/
sudo systemctl daemon-reload && sudo systemctl enable --now velora-connector
sudo systemctl status velora-connector --no-pager
journalctl -u velora-connector -n 30 --no-pager
```

---

## Fase 7 — Backup (NFR-005)

```bash
cd /var/www/velora
sudo -u www-data php artisan backup:key       # -> BACKUP_ENCRYPTION_KEY; CADANGKAN di password manager
which mysqldump mysql                         # bila kosong: isi BACKUP_MYSQLDUMP=/usr/bin/mariadb-dump dan BACKUP_MYSQL=/usr/bin/mariadb
nano .env                                     # isi BACKUP_* (lihat 4.3)
sudo -u www-data php artisan optimize
sudo -u www-data php artisan backup:run
sudo -u www-data php artisan backup:verify    # restore ke veloradigital_restore_check, cocokkan jumlah baris
sudo -u www-data php artisan backup:status
```

Berkas muncul di bucket `velora-backup`. Prosedur pemulihan: `docs/BACKUP-RESTORE.md`.

---

## Fase 8 — Uji di domain sementara

DNS `fachrullahzhafran.site` sudah mengarah ke VPS sejak Fase 0.4, jadi uji langsung di
`https://fachrullahzhafran.site` (browser biasa dan HP dengan data seluler). `veloradigitalworks.com` tetap di Hostinger.

| # | Uji | Harus |
|---|---|---|
| 1 | Beranda, `/services`, `/portfolio`, `/articles`, detail artikel | Tampil; gambar dari `media.veloradigitalworks.com`; gembok HTTPS valid |
| 2 | `https://www.fachrullahzhafran.site` dan `http://…` | Dialihkan ke `https://fachrullahzhafran.site` |
| 3 | `curl -sI https://fachrullahzhafran.site \| grep -i x-robots` | `x-robots-tag: noindex, nofollow` |
| 4 | Form kontak (reCAPTCHA) | Pesan masuk di `/admin` → Pesan Kontak (gagal = domain belum ditambahkan ke key reCAPTCHA) |
| 5 | `/admin` login + kode MFA | Masuk (MFA lama tetap berlaku karena `APP_KEY` sama) |
| 6 | `/admin` → Akun → kirim ulang undangan ke email Anda | Email masuk; tautan ke `https://fachrullahzhafran.site/invitation/…` |
| 7 | `/admin` → edit portofolio | Preview gambar lama tampil (kosong = origin belum ditambahkan ke CORS R2) |
| 8 | `/login` tenant → Dashboard | Data workspace tampil |
| 9 | `/app/whatsapp` → scan QR dengan HP bisnis | Status **Terhubung** |
| 10 | Kirim WhatsApp ke nomor bisnis dari HP lain | Dibalas bot (worker `ai` + `whatsapp` bekerja) |
| 11 | `/app/chat-test` | Bot menjawab |
| 12 | `/admin` → Dashboard | Antrean 0, tidak ada pesan menggantung |

Tag GA4/Google Ads/GTM ikut termuat di domain sementara (kodenya sengaja tidak diubah); kunjungan tercatat dengan
hostname `fachrullahzhafran.site` dan bisa difilter di GA4 bila mengganggu laporan.

---

## Fase 9 — Nanti: pindah ke VPS final + domain utama

**Bukan untuk sekarang.** Saat VPS final siap, ulangi runbook ini di VPS baru dengan perbedaan berikut.

### 9.1 Siapkan VPS final (Fase 1–7)

- Fase 1.2: `export DOMAIN=veloradigitalworks.com`.
- Fase 4.3: `APP_URL=https://veloradigitalworks.com`. **`APP_KEY` sama** dengan VPS sementara.
- Fase 5.2: certbot untuk `veloradigitalworks.com` (token 0.5 sudah mencakup zona ini; DNS-01 → sertifikat jadi
  sebelum DNS pindah).
- Fase 5.3: `sudo bash deploy/nginx/install.sh veloradigitalworks.com` — **tanpa** `--noindex`.
- Fase 6.3: **`AUTH_ENCRYPTION_KEYS` sama** dengan VPS sementara (lihat 9.2). Jangan buat kunci baru.
- **Lewati Fase 3.2** (impor dari lokal); data diambil dari VPS sementara (9.2).

### 9.2 Pindahkan data dari VPS sementara

```bash
# --- di VPS SEMENTARA: hentikan semua penulis data, lalu dump ---
sudo -u www-data php /var/www/velora/artisan down
sudo supervisorctl stop all
sudo systemctl stop velora-connector && sudo systemctl disable velora-connector   # jangan hidup lagi (nomor bentrok)
sudo mariadb-dump --single-transaction --routines --triggers veloradigital > ~/velora-final.sql
```

Salin `velora-final.sql` ke VPS final (mis. lewat PC: `scp` turun lalu `scp` naik), lalu di VPS final:

```bash
sudo mariadb veloradigital < ~/velora-final.sql
sudo mariadb veloradigital -e "TRUNCATE jobs; TRUNCATE job_batches; TRUNCATE failed_jobs; TRUNCATE sessions; TRUNCATE cache; TRUNCATE cache_locks;"
shred -u ~/velora-final.sql
```

Tabel `wa_auth_*` **tidak** dikosongkan: sesi WhatsApp ikut pindah (terenkripsi; terbaca karena
`AUTH_ENCRYPTION_KEYS` sama) sehingga nomor **tidak perlu scan QR ulang**. Lalu jalankan Fase 6 (worker, cron,
konektor) dan Fase 7 (backup).

### 9.3 Uji sebelum DNS dipindah

Domain utama masih ke Hostinger, jadi arahkan **hanya komputer Anda** ke VPS final. **[PC]** Notepad *Run as
administrator* → `C:\Windows\System32\drivers\etc\hosts`, tambahkan `<VPS_FINAL_IP>  veloradigitalworks.com www.veloradigitalworks.com`,
lalu `ipconfig /flushdns`. Jalankan uji Fase 8 (tanpa uji noindex), lalu **hapus baris itu** dan `ipconfig /flushdns`.

### 9.4 Cutover DNS

Cloudflare → `veloradigitalworks.com` → **DNS → Records**:

| Record | Sebelum (Hostinger) | Ubah menjadi |
|---|---|---|
| `A @` | `46.202.186.247` | `<VPS_FINAL_IP>`, tetap **DNS only** |
| `AAAA @` | `2a02:4780:6:1251:0:12e5:3bd2:2` | **Hapus** (tambahkan IPv6 VPS nanti setelah diuji; bila dibiarkan, pengunjung IPv6 masih ke Hostinger) |
| `CNAME www` | `veloradigitalworks.com` | Tidak diubah |
| `MX`, `TXT` SPF/DMARC, `CNAME` DKIM/autodiscover/autoconfig | Hostinger | **Tidak diubah** (email tetap di Hostinger) |
| `A ftp` | `46.202.186.247` | Tidak diubah (FTP hosting lama) |
| `CNAME media` (R2) | — | Tidak diubah |

```bash
dig +short veloradigitalworks.com @1.1.1.1          # = <VPS_FINAL_IP>
curl -sI https://veloradigitalworks.com | head -3   # HTTP/2 200, TANPA x-robots-tag
```

**Rencana mundur:** kembalikan `A @` ke `46.202.186.247` dan buat lagi `AAAA @` `2a02:4780:6:1251:0:12e5:3bd2:2`.
Website Hostinger dibiarkan hidup 1–2 minggu sampai VPS final stabil.

### 9.5 Bereskan yang sementara

- VPS sementara: biarkan mati 1–2 minggu (cadangan), lalu hapus. **Konektornya jangan pernah dihidupkan lagi.**
- `fachrullahzhafran.site`: hapus record `A @`/`CNAME www`, atau arahkan ke VPS final dengan redirect 301 ke domain utama.
- CORS R2: origin `https://fachrullahzhafran.site` boleh dihapus.

---

## Fase 10 — Setelah live

1. **Snapshot Contabo** (panel → Snapshots → Create). Hanya 1 slot: snapshot baru menggantikan yang lama.
   Snapshot ≠ backup (NFR-005); backup harian tetap ke R2.
2. **Komputer lokal:** konektor dan worker antrean jangan dijalankan bersamaan dengan VPS untuk nomor yang sama.
3. **Pemantauan:** daftarkan `https://fachrullahzhafran.site` (nanti domain utama) di UptimeRobot (gratis) dengan notifikasi email/WhatsApp.
4. **Update aplikasi** (setelah merge ke `development`; VPS final: `main`):

   ```bash
   cd /var/www/velora && bash deploy/deploy.sh
   ```

   Skrip: maintenance → `git merge --ff-only` → composer → build Vite → migrasi → `optimize` → reload PHP-FPM →
   `queue:restart` → (restart konektor bila folder `connector/` berubah) → online.
   Setelah mengubah `.env`: `sudo -u www-data php artisan optimize && sudo supervisorctl restart all`.

---

## Lampiran A — Perintah harian

| Keperluan | Perintah |
|---|---|
| Log aplikasi | `tail -f /var/www/velora/storage/logs/laravel.log` |
| Status worker | `sudo supervisorctl status` |
| Log worker | `tail -f /var/www/velora/storage/logs/queue-ai.log` |
| Status/log konektor | `systemctl status velora-connector` · `journalctl -u velora-connector -f` |
| Jadwal | `sudo -u www-data php artisan schedule:list` |
| Status backup | `sudo -u www-data php artisan backup:status` |
| Mode maintenance | `sudo -u www-data php artisan down` / `up` |
| Sertifikat SSL | `sudo certbot certificates` |

## Lampiran B — Pemecahan masalah

| Gejala | Penyebab umum | Solusi |
|---|---|---|
| 502 Bad Gateway | PHP-FPM mati / socket salah | `sudo systemctl status php8.3-fpm`; socket `/run/php/php8.3-fpm.sock` |
| 500 Server Error | Izin `storage`, `.env` salah | `tail storage/logs/laravel.log`; ulangi izin di 4.2 |
| Perubahan kode tidak terlihat | OPcache (`validate_timestamps=0`) | `sudo systemctl reload php8.3-fpm` (sudah di `deploy.sh`) |
| Perubahan `.env` tidak berlaku | Config ter-cache | `sudo -u www-data php artisan optimize` + `sudo supervisorctl restart all` |
| Pesan WhatsApp tidak dibalas | Worker berhenti / kuota AI | `supervisorctl status`; `/admin` → Antrean; log `queue-ai.log` |
| QR tidak muncul / "konektor tidak terjangkau" | Konektor mati / `CONNECTOR_TOKEN` beda | `journalctl -u velora-connector`; samakan token di kedua `.env` |
| Pesan masuk tidak tercatat | `WEBHOOK_SECRET` ≠ `CONNECTOR_WEBHOOK_SECRET`, atau listener 8080 | Samakan secret; `curl -s -o /dev/null -w "%{http_code}" -X POST http://127.0.0.1:8080/webhooks/whatsapp` → 401 (bukan 404/502) |
| Email tidak terkirim | Port SMTP diblokir / password salah | `nc -vz smtp.hostinger.com 465`; cek `MAIL_*` |
| Gambar tidak tampil | `MEDIA_DISK`/`R2_*` salah | `sudo -u www-data php artisan media:check r2` |
| Backup gagal | `mysqldump` tidak ada / kredensial R2 | `which mysqldump`; isi `BACKUP_MYSQLDUMP`; cek `BACKUP_S3_*` |

## Lampiran C — Keputusan teknis

| Pilihan | Alternatif | Alasan |
|---|---|---|
| Nginx + PHP-FPM | Apache | Lebih hemat memori untuk worker + konektor di 8 GB; konfigurasi Laravel standar |
| MariaDB 10.11 | MySQL 8 | Sama keluarga dengan lokal (XAMPP MariaDB), impor dump tanpa konversi collation |
| Let's Encrypt via DNS Cloudflare | Let's Encrypt HTTP / Cloudflare proxy | Sertifikat siap sebelum DNS pindah (uji penuh HTTPS tanpa downtime). Proxy Cloudflare (awan oranye) bisa diaktifkan nanti — perlu SSL "Full (strict)" dan konfigurasi trusted proxies agar IP pengunjung asli terbaca |
| `AAAA` dihapus saat cutover | Langsung pakai IPv6 VPS | Mengurangi variabel saat cutover; IPv6 ditambahkan setelah diuji |
| Webhook via `127.0.0.1:8080` | Lewat domain publik | Konektor tidak bergantung pada DNS/SSL; uji WhatsApp bisa sebelum cutover |
