#!/usr/bin/env bash
# Pasang situs Nginx Velora untuk satu domain. Panduan: docs/DEPLOY-VPS.md (Fase 5.3).
#   sudo bash deploy/nginx/install.sh fachrullahzhafran.site --noindex    # domain sementara (tidak diindeks Google)
#   sudo bash deploy/nginx/install.sh veloradigitalworks.com              # domain utama
# Sertifikat /etc/letsencrypt/live/<domain>/ harus sudah ada (Fase 5.2). Aman dijalankan ulang (menimpa konfigurasi).
set -euo pipefail

DOMAIN="${1:?Pakai: sudo bash deploy/nginx/install.sh <domain> [--noindex]}"
NOINDEX="${2:-}"
SRC="$(cd "$(dirname "$0")" && pwd)/velora.conf"
OUT=/etc/nginx/sites-available/velora

if [[ ! "$DOMAIN" =~ ^[a-z0-9.-]+\.[a-z]{2,}$ ]]; then
    echo "Domain tidak valid: $DOMAIN" >&2; exit 1
fi

if [ ! -f "/etc/letsencrypt/live/$DOMAIN/fullchain.pem" ]; then
    echo "Sertifikat untuk $DOMAIN belum ada. Jalankan certbot (Fase 5.2) dulu." >&2; exit 1
fi

sed "s/__DOMAIN__/$DOMAIN/g" "$SRC" > "$OUT"

if [ "$NOINDEX" = "--noindex" ]; then
    sed -i 's/^\([[:space:]]*\)# NOINDEX: /\1/' "$OUT"
    echo "noindex aktif (X-Robots-Tag) untuk $DOMAIN"
fi

ln -sf "$OUT" /etc/nginx/sites-enabled/velora
rm -f /etc/nginx/sites-enabled/default
nginx -t
systemctl reload nginx
echo "Nginx aktif untuk https://$DOMAIN"
