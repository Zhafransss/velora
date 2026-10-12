# Velora Connector (Baileys, multi-session) — PoC Tahap 0

Service Node.js terpisah yang mengelola **satu socket WhatsApp per channel**. Laravel tetap menjadi sumber kebenaran untuk tenant, kuota, percakapan, dan AI; konektor hanya menjembatani WhatsApp.
Rujukan: `docs/SRS.md` (FR-009.x, NFR-003/007/008) dan PRD bagian 17.

> **Status: PoC.** Auth state memakai `useMultiFileAuthState` (satu direktori per channel, berisi creds **dan** Signal keys). PRD bagian 17.4 menyebut ini cukup untuk prototipe, tetapi produksi perlu auth-store yang dievaluasi (OQ-07).
> Baileys adalah library tidak resmi: risiko blokir nomor diterima sadar (SRS R-01, R-02).

## Persyaratan

- Node.js >= 20 (Baileys 7.0.0-rc14 dipin pada versi tepat)
- Server Ubuntu; konektor **hanya listen di 127.0.0.1**

## Menjalankan

```bash
cd connector
npm ci
cp .env.example .env
# isi CONNECTOR_TOKEN (min 32 karakter) dan AUTH_DIR (di luar web root, ikut backup)
node --env-file=.env src/index.js
```

## Penyimpanan auth terenkripsi (produksi, DEC-17)

`AUTH_STORE=mysql` menyimpan kredensial perangkat dan kunci Signal di MySQL, terenkripsi AES-256-GCM per record
(IV acak; AAD = channel+jenis+id, sehingga baris yang ditukar/disalin tidak bisa dibaca). Laravel tidak pernah membaca isinya.

1. Jalankan migrasi Laravel (membuat `wa_auth_creds`, `wa_auth_keys`).
2. Buat pengguna MySQL khusus konektor, hak hanya pada dua tabel itu:
   ```sql
   CREATE USER 'velora_connector'@'127.0.0.1' IDENTIFIED BY '<password kuat>';
   GRANT SELECT, INSERT, UPDATE, DELETE ON veloradigital.wa_auth_creds TO 'velora_connector'@'127.0.0.1';
   GRANT SELECT, INSERT, UPDATE, DELETE ON veloradigital.wa_auth_keys  TO 'velora_connector'@'127.0.0.1';
   ```
3. `npm run keygen` → isi `AUTH_ENCRYPTION_KEYS`, plus `AUTH_STORE=mysql`, `DB_*` di `connector/.env`.
4. **Cadangkan kunci terpisah dari backup DB.** Kunci hilang = semua sesi hilang (scan ulang). Backup DB saja tidak cukup untuk memulihkan sesi.
5. Rotasi kunci: buat kunci baru dan taruh di DEPAN (`k2:...,k1:...`), restart; record lama tetap terbaca dan ditulis ulang
   dengan kunci baru saat berubah. Hapus kunci lama hanya setelah semua record memakai kunci baru (`enc_key_id` di tabel).
6. Sesi lama di folder `auth/` TIDAK dimigrasi: setelah pindah ke `mysql`, tiap nomor perlu scan QR ulang.

Tes: `npm test` (unit, tanpa DB). Dengan MySQL sungguhan:
`TEST_DB_DATABASE=<db> TEST_DB_USERNAME=<user> npm test` (membuat/menghapus baris `itest_*` saja; jangan arahkan ke DB produksi).

Produksi di VPS: lihat `deploy/velora-connector.service` (systemd, batas memori, auto-restart).

## Pairing satu nomor

```bash
CONNECTOR_TOKEN=... node scripts/pair.js tenantA_wa1
```
Scan QR lewat WhatsApp > Perangkat tertaut. `channelId` hanya boleh `[A-Za-z0-9_-]{1,64}`; di produksi ID dibuat Laravel, bukan dipilih pengguna.

## API internal (Bearer token, localhost saja)

| Method | Path | Fungsi |
|---|---|---|
| GET | `/health` | Liveness (tanpa token) |
| GET | `/metrics` | Jumlah session per state, memori proses |
| GET | `/channels` | Daftar session |
| POST | `/channels/:id/start` | Buat/pulihkan session (idempoten) |
| GET | `/channels/:id` | Status session |
| GET | `/channels/:id/qr` | QR terakhir (hanya di memori, tidak di-log, tidak lewat webhook) |
| POST | `/channels/:id/send` | `{ "to": "62812...", "text": "..." }` lewat channel itu saja |
| POST | `/channels/:id/presence` | `{ "to": "...", "state": "composing"\|"paused" }` indikator "mengetik" (diperbarui otomatis tiap 8 dtk, maks. 60 dtk) |
| DELETE | `/channels/:id[?logout=1]` | Stop; dengan `logout=1` device di-unlink dan kredensial dihapus |

Event ke Laravel (jika `WEBHOOK_URL` diisi) ditandatangani `HMAC-SHA256(secret, "<timestamp>.<body>")` pada header `X-Velora-Signature` dan `X-Velora-Timestamp`. Laravel wajib memverifikasi tanda tangan dan menolak timestamp kedaluwarsa. Jenis event: `message.inbound`, `channel.status`.

## Perilaku penting

- **Isolasi:** socket selalu dipilih dari `channelId`, tidak pernah dari nomor penerima (WA-MT-03). Reconnect satu channel tidak menyentuh channel lain (WA-MT-04).
- **Reconnect:** exponential backoff + jitter, maksimum percobaan dibatasi (`failed` setelahnya). `logged_out` menghapus kredensial. QR yang tidak dipindai menjadi `qr_expired` dan tidak berulang tanpa batas.
- **Deduplikasi:** `externalMessageId` yang sama tidak diteruskan dua kali per session (WA-MT-05).
- **Mitigasi blokir:** hanya membalas lewat API (tidak ada broadcast), pesan keluar diserialkan per channel dengan jeda minimum dan jeda acak, indikator "mengetik" sebelum kirim.
- **Hanya chat 1:1 teks.** Grup, broadcast, newsletter, dan media diabaikan pada PoC.
- **Baileys v7 dan LID:** pesan masuk dapat memakai jid `@lid`. Event menyertakan `from` (balas ke sini) dan `fromAlt` (nomor telepon bila diketahui). Perlu diverifikasi pada nomor testing.

## Prosedur uji PoC (3 nomor testing)

Jalankan di VPS. Catat hasil di tabel bawah, lalu perbarui `docs/RTM.md`.

| ID | Skenario | Cara uji | Hasil |
|---|---|---|---|
| WA-MT-01 | 3 nomor terhubung dan bertahan | `pair.js` untuk `poc_a`, `poc_b`, `poc_c`; restart service; pastikan ketiganya `connected` tanpa scan ulang | |
| WA-MT-02 | Pesan masuk dipetakan ke channel yang benar | Kirim pesan ke tiap nomor; periksa log/webhook `channelId` sesuai | |
| WA-MT-03 | Balasan lewat channel asal | `send` dari `poc_a` ke ponsel penguji; pengirim di ponsel harus nomor A, bukan B/C | |
| WA-MT-04 | Gangguan satu session tidak memengaruhi lain | Unlink perangkat `poc_a` dari ponsel (atau putus jaringan sebentar); `poc_b`, `poc_c` tetap `connected` dan tetap bisa kirim | |
| WA-MT-05 | Duplikat tidak diproses ganda | Dicakup dedup per `externalMessageId`; uji lanjutan di Tahap 3 bersama Laravel | |
| WA-MT-06 | QR/kredensial tidak ada di log | `grep -ri "qr\|creds" <log>`; periksa isi log | Lokal: lulus (lihat smoke) |
| WA-MT-07 | Revoke menutup koneksi | `DELETE /channels/poc_a?logout=1`; direktori auth hilang, kirim ditolak | |
| NFR-007 | Kapasitas | Catat `/metrics` dengan 1, 3 sesi terhubung dalam kondisi idle dan setelah beberapa pesan; ekstrapolasi batas per VPS | |

Smoke tanpa ponsel (membuktikan N socket paralel dengan QR berbeda):
```bash
CONNECTOR_TOKEN=... node scripts/smoke.js 3
```
Catatan: sesi yang baru berada di tahap QR memakai memori lebih kecil dari sesi terhubung. Ukur memori **setelah pairing** untuk angka kapasitas yang jujur.

## Belum dikerjakan (di luar PoC ini)

- Integrasi Laravel (penerima webhook, pemanggil API, pemetaan tabel `channels`).
- Auth-store terenkripsi di MySQL, backup luar server untuk direktori auth.
- Pesan media, grup, status baca.
- Unit/integration test otomatis untuk konektor.
