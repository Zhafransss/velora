#!/usr/bin/env bash
# Update aplikasi di VPS ke versi terbaru dari GitHub. Panduan: docs/DEPLOY-VPS.md (Fase 10).
# Jalankan sebagai user "deploy":  cd /var/www/velora && bash deploy/deploy.sh [branch]
# Bawaan: branch yang sedang aktif di server (VPS sementara: development; VPS final: main).
#
# Bila satu langkah gagal, skrip berhenti dan website TETAP dalam mode maintenance agar pengunjung tidak melihat
# aplikasi setengah ter-update. Perbaiki penyebabnya, lalu jalankan ulang skrip (atau: sudo -u www-data php artisan up).
set -euo pipefail

APP_DIR=/var/www/velora
BRANCH="${1:-$(git -C /var/www/velora rev-parse --abbrev-ref HEAD)}"
artisan() { sudo -u www-data php "$APP_DIR/artisan" "$@"; }

cd "$APP_DIR"
OLD_COMMIT=$(git rev-parse HEAD)

echo "==> Mengambil kode terbaru ($BRANCH)"
git fetch origin "$BRANCH"
if [ "$(git rev-parse HEAD)" = "$(git rev-parse "origin/$BRANCH")" ]; then
    echo "Sudah versi terbaru ($OLD_COMMIT). Tidak ada yang di-deploy."
    exit 0
fi

echo "==> Mode maintenance"
artisan down --retry=60

# --ff-only: gagal (bukan menimpa) bila ada perubahan manual di server.
git merge --ff-only "origin/$BRANCH"
NEW_COMMIT=$(git rev-parse HEAD)

echo "==> Dependensi PHP"
composer install --no-dev --optimize-autoloader --no-interaction

echo "==> Build aset (Vite)"
npm ci --no-audit --no-fund
npm run build

if ! git diff --quiet "$OLD_COMMIT" "$NEW_COMMIT" -- connector/; then
    echo "==> Dependensi konektor WhatsApp"
    (cd connector && npm ci --omit=dev --no-audit --no-fund)
fi

echo "==> Migrasi database"
artisan migrate --force

echo "==> Cache konfigurasi/route/view"
artisan optimize

echo "==> Muat ulang PHP-FPM (opcache.validate_timestamps=0) dan worker antrean"
sudo systemctl reload php8.3-fpm
artisan queue:restart

if ! git diff --quiet "$OLD_COMMIT" "$NEW_COMMIT" -- connector/; then
    echo "==> Restart konektor WhatsApp (sesi tersambung kembali dari MySQL dalam beberapa detik)"
    sudo systemctl restart velora-connector
fi

artisan up
echo "==> Selesai: $OLD_COMMIT -> $NEW_COMMIT"
