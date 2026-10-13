# RTM — Velora AI

Terakhir diperbarui: 2026-10-13. **Wajib diperbarui setiap sesi kerja.**
Rujukan: `docs/SRS.md`. Status: Not Started → In Progress → Done (atau Deferred). Prioritas: M/S/C/D.

## 1. Requirement Fungsional

| ID | Tujuan Bisnis | Requirement | Fitur/Modul | Prioritas | Tahap | Status | Test Case |
|----|---------------|-------------|-------------|-----------|-------|--------|-----------|
| FR-001 | BG-01, BG-06 | Registrasi, login, logout, reset password, verifikasi email | Tenancy/Auth | M | 1 | In Progress (registrasi, login, logout, reset, verifikasi, reCAPTCHA v2 di form daftar: tes lulus; reCAPTCHA belum dicoba dengan key produksi di domain asli) | TC-AUTH-01..05 |
| FR-002 | BG-01, BG-06 | Workspace dan profil bisnis (zona waktu, jam operasional) | Tenancy | M | 1 | In Progress (skema + model selesai; form profil bisnis ada, gated langganan) | TC-WS-01..03 |
| FR-003 | BG-04 | Isolasi tenant (`tenant_id`, global scope, policy); `/admin` hanya admin platform | Tenancy | M | 1 | In Progress (trait + scope + konteks + test lulus; policy dan model data nyata belum ada) | TC-ISO-01 (user A tak bisa baca/ubah data B), TC-ISO-02 (user tenant ditolak di `/admin`) |
| FR-004 | BG-01, BG-05 | Plan, entitlement terpusat, status subscription, penagihan manual | Billing | M | 4 | In Progress (Entitlements terpusat; harga Small Rp250.000/Medium Rp500.000; snapshot harga per langganan; pembeda paket = kuota + jendela riwayat; UI penagihan manual + audit. Belum: tabel plans di DB (paket masih config/plans.php), widget/API ditunda) | TC-ENT-01 (Small tak bisa fitur Medium), TC-SUB-01 (expired/suspended tak diproses) |
| FR-005 | BG-02 | CRUD produk, layanan, FAQ (stok NULL = tidak diketahui) | KnowledgeBase | M | 2 | In Progress (tabel + CRUD produk/layanan/FAQ + isolasi + gerbang langganan lulus; dokumen di luar MVP) | TC-KB-01..04 |
| FR-006 | BG-02 | Konfigurasi AI: persona, bahasa, pesan pembuka, fallback | AI | M | 2 | In Progress (tabel bots + form /app/bot + tes lulus; pemakaian di prompt menunggu 2.4) | TC-AIC-01..02 |
| FR-007 | BG-02, BG-03, BG-05 | AI gateway Gemini + Groq, timeout, retry, fallback, pencatatan token | AI | M | 2 | In Progress (gateway + penyusun prompt (aturan platform, anti injection, konteks penuh ber-batas) lulus tes dgn respons palsu; diuji dgn API asli 2026-10-12: Groq gpt-oss-120b OK, Gemini 3.5-flash kena kuota akun; jawaban terpotong kini ditolak (truncated)) | TC-GW-01 (timeout), TC-GW-02 (respons invalid), TC-GW-03 (fallback provider), TC-GW-04 (rate limit provider) |
| FR-008 | BG-02, BG-06 | Tes chatbot di dashboard, usage uji terpisah | Chat/AI | M | 2 | In Progress (halaman /app/chat-test, riwayat ai_test_messages, batas laju 20/jam, hanya owner/admin: tes lulus dgn respons palsu; command ai:accuracy + dataset 36 kasus siap (docs/ACCURACY-TEST.md), TC-ACC-01: dataset contoh 36/36 (100%, 1 run, Groq, 2026-10-12) — belum bermakna utk pilot, perlu dataset usaha nyata + --runs=3; belum terhubung kuota harian Tahap 3) | TC-TEST-01..02, TC-ACC-01 (uji akurasi ≥ 90%) |
| FR-009.1 | BG-03, BG-06 | Konektor Baileys multi-session, auth state per channel (creds + Signal keys) | Channels/WhatsApp | M | 0 | In Progress | WA-MT-01 |
| FR-009.2 | BG-06 | Pairing QR oleh tenant, QR tidak masuk log | Channels/WhatsApp, Dashboard | M | 0, 3 | In Progress (halaman /app/whatsapp: buat kanal, QR dirender di server & tidak disimpan/dilog, hanya owner/admin: tes lulus; belum diuji dgn ponsel — lihat docs/MANUAL-TEST-WHATSAPP.md) | WA-MT-06 |
| FR-009.3 | BG-03, BG-04 | Pesan masuk dipetakan ke channel/tenant terverifikasi, dideduplikasi | Channels/WhatsApp | M | 0, 3 | In Progress (webhook HMAC + anti replay, dedup per channel+id, tenant dari channel: tes lulus dgn konektor palsu; belum dgn Baileys asli) | WA-MT-02, WA-MT-05 |
| FR-009.4 | BG-04 | Balasan keluar hanya lewat channel milik percakapan | Channels/WhatsApp | M | 0, 3 | In Progress (balasan selalu via channel milik percakapan, diuji 2 channel; belum dgn Baileys asli) | WA-MT-03 |
| FR-009.5 | BG-03 | Reconnect dengan backoff; status koneksi dibedakan | Channels/WhatsApp | M | 0 | In Progress | WA-MT-04 |
| FR-009.6 | BG-04 | Pencabutan channel menutup koneksi dan menghapus kredensial | Channels/WhatsApp | M | 3 | In Progress | WA-MT-07 |
| FR-009.7 | BG-03 | Mitigasi blokir: hanya balas pesan masuk, jeda acak, batas laju | Channels/WhatsApp | M | 3 | In Progress (hanya membalas pesan masuk, batas laju per kanal 20/mnt, ditunda bukan dibuang: tes lulus; jeda acak ada di konektor) | TC-WA-RATE-01 |
| FR-009.8 | BG-03 | Interface `WhatsAppChannelAdapter` dapat diganti | Channels/WhatsApp | S | 3 | In Progress (interface + adapter Baileys + tes; belum ada adapter kedua) | WA-MT-08 |
| FR-010 | BG-01 | Website widget | Channels/WebsiteWidget | D | – | Deferred | – |
| FR-011 | BG-02, BG-06 | Penyimpanan percakapan, state pemrosesan vs pengiriman, retensi | Chat | M | 3 | In Progress (percakapan, status AI-selesai vs terkirim, is_test; retensi 30 hari otomatis (conversations:prune terjadwal, DEC-15) + riwayat dibatasi menurut paket; belum: UI hapus manual atas permintaan pelanggan akhir) | TC-CONV-01..03 |
| FR-012 | BG-05 | Ledger usage, kuota harian, reservasi atomik, idempotency | Usage | M | 3 | In Progress (ledger append-only, reservasi atomik, idempotency, release, rekonsiliasi, hari menurut zona tenant; konkurensi MySQL 8 proses x5 run lulus; admin koreksi = Tahap 4) | TC-QUOTA-01 (batas), TC-QUOTA-02 (paralel), TC-IDEM-01 (duplikat), TC-USG-01 (rekonsiliasi) |
| FR-013 | BG-06 | Dashboard tenant `/app` (Blade + Livewire) | Dashboard | M | 1–3 | In Progress (dashboard, profil, KB, bot, tes chatbot, WhatsApp+QR, percakapan, halaman langganan+harga, notifikasi (lencana, banner, kotak masuk, DEC-16); belum: ringkasan grafik pemakaian) | TC-DASH-01..04 |
| FR-014 | BG-01, BG-05 | Filament admin: tenant, plan, subscription, usage, failed jobs, audit | Admin | M | 4 | In Progress (/admin: Workspace (daftar+detail+tangguhkan), Langganan (perpanjang/tangguhkan/batalkan), Audit Log append-only, widget ringkasan; belum: halaman failed jobs, koreksi kuota, tabel plan) | TC-ADM-01..03 |
| FR-015 | BG-03 | Queue, retry, idempotency, failed-job handling, request ID | Reliability | M | 3–4 | In Progress (antrean database, job idempoten, klaim atomik, webhook cepat; penyapu pesan menggantung `messages:sweep-stuck` selesai; halaman Pesan Gagal + kirim ulang oleh pemilik workspace `/app/failed-messages` selesai; request ID belum) | TC-REL-01..03 |
| FR-016 | BG-03, BG-04 | Suite Pest: isolasi tenant dan kuota memblokir deploy | Testing | M | 1–4 | In Progress (82 test lulus; CI GitHub Actions ditambahkan; kuota, AI, konektor menyusul) | (meta; lihat seluruh TC) |
| FR-017 | BG-07 | Migrasi website ke VPS, cutover DNS, rollback, hapus hosting lama | Deployment | M | 5 | Not Started | TC-MIG-01 (data dan upload utuh), TC-MIG-02 (rollback), TC-MIG-03 (halaman publik setara) |

## 2. Requirement Non-Fungsional

| ID | Tujuan Bisnis | Requirement | Prioritas | Tahap | Status | Test Case |
|----|---------------|-------------|-----------|-------|--------|-----------|
| NFR-001 | BG-03 | p95 < 500 ms dashboard di staging | S | 4 | Not Started | TC-PERF-01 |
| NFR-002 | BG-03 | Retry, idempotency, monitoring failed job; uptime internal 99% | M | 4 | Not Started | TC-REL-01..03 |
| NFR-003 | BG-04 | Otorisasi server, secret server-side, konektor hanya `127.0.0.1` | M | 0–4 | Not Started | TC-SEC-01..03 |
| NFR-004 | BG-04 | Hardening VPS (SSH key, firewall, fail2ban, HTTPS) | M | 0 | Not Started | Checklist infra |
| NFR-005 | BG-03, BG-04 | Backup luar server + restore diuji (MySQL dan auth Baileys) | M | 4 | In Progress (kode + uji restore lokal selesai; bucket luar server, pemantau `backup:status`, dan latihan pemulihan ke server lain belum) | TC-BKP-01 (restore berhasil) |
| NFR-006 | BG-04 | Privasi: kebijakan, syarat, UU PDP, retensi | M | 4 | Not Started | Checklist legal |
| NFR-007 | BG-03 | Kapasitas session Baileys diukur dan dicatat | M | 0 | Not Started | TC-CAP-01 |
| NFR-008 | BG-07 | Worker dan konektor terpisah dari PHP-FPM, batas memori, auto-restart | M | 0, 4 | Not Started | TC-ISOP-01 |
| NFR-009 | BG-03, BG-05 | Observabilitas (request ID, latensi, error rate, biaya) | S | 4 | Not Started | TC-OBS-01 |
| NFR-010 | BG-07 | Migration SaaS tidak mengubah tabel website | M | 1 | In Progress (TC-MIGR-01 lulus untuk Tahap 1) | TC-MIGR-01 |
| NFR-011 | BG-05 | Batas token, rate limit, alarm biaya | M | 2–3 | Not Started | TC-COST-01 |
| NFR-012 | BG-06 | Dashboard responsif dan dapat dinavigasi keyboard | C | 4 | Not Started | TC-A11Y-01 |

## 3. Hutang Teknis Website (terbawa ke aplikasi gabungan)

| ID | Temuan | Terkait | Prioritas | Status |
|----|--------|---------|-----------|--------|
| TD-01 | `User::canAccessPanel()` selalu `true` | FR-003 | **M, wajib sebelum ada user tenant** | Done (tests/Feature/PanelAccessTest.php) |
| TD-02 | `DatabaseSeeder` membuat `test@example.com`; pastikan tidak ada di produksi | FR-003 | M | Done (tests/Feature/PanelAccessTest.php) |
| TD-03 | `portfolio->year` dan `$p->tag` dipakai tetapi kolom tidak ada | FR-017 | S | Not Started |
| TD-04 | `ServiceController::show` tanpa cek null/`is_active` | FR-017 | S | Not Started |
| TD-05 | Form kontak tanpa rate limit | FR-017 | S | Not Started |
| TD-06 | `MAIL_MAILER=log`, lead tidak diberi notifikasi email | FR-017 | C | Not Started |
| TD-07 | `LOG_LEVEL=debug` di produksi | NFR-003 | M | Not Started |
| TD-08 | Sitemap berupa closure di route (menghalangi `route:cache`), tanpa filter `is_active` | FR-017 | S | Not Started |
| TD-09 | `og-image.jpg` tidak ada; `og:title` tanpa default | FR-017 | C | Not Started |
| TD-10 | Duplikasi manual `public/` dan `public_html/` hilang setelah pindah VPS (root Nginx = `public/`) | FR-017 | S | Not Started |

## 4. Keputusan Terbuka yang Memengaruhi RTM

Lihat `docs/SRS.md` bagian 9 (OQ-01 s/d OQ-10).

## 5. Riwayat Perubahan

| Tanggal | Perubahan |
|---------|-----------|
| 2026-10-11 | RTM dibuat dari PRD V1.0 dan keputusan diskusi. Seluruh item Not Started; FR-010 Deferred. |
| 2026-10-11 | Tahap 0 dimulai (branch `feature/poc-baileys`): kerangka konektor `connector/` selesai. FR-009.1–.5 menjadi In Progress. Terverifikasi lokal: 3 socket paralel menghasilkan 3 QR berbeda, token dan validasi `channelId` bekerja. **Belum terverifikasi dengan nomor sungguhan di VPS**, sehingga belum ada yang Done. |
| 2026-10-12 | DEC-17 (branch `feature/encrypted-auth-store`): auth Baileys di MySQL terenkripsi AES-256-GCM (`connector/src/auth/`, tabel `wa_auth_creds`/`wa_auth_keys`), hapus kredensial saat kanal dihapus + `whatsapp:prune-auth` mingguan. 39 tes Node (unit + MySQL nyata), 12 tes Laravel; konektor boot di mode mysql. **Belum terverifikasi dengan ponsel** (pairing ulang + restart tanpa scan) → FR-009.1/.6 tetap In Progress. NFR-005: kunci enkripsi wajib dibackup terpisah. |
| 2026-10-12 | FR-015/NFR-002 (branch `feature/stuck-message-sweeper`): `messages:sweep-stuck` tiap 5 menit. received/pending macet → diantre ulang (klaim atomik); lewat 60 menit → kedaluwarsa; processing macet → gagal + kuota dikembalikan (tidak diulang); sending macet → gagal "tidak terkonfirmasi" (tidak dikirim ulang, hindari kirim ganda). 13 tes. Butuh `schedule:work`/cron di VPS. Halaman job gagal + retry belum. |
| 2026-10-12 | FR-015/NFR-002 (branch `feature/failed-messages`): halaman `/app/failed-messages` untuk owner/admin workspace (viewer hanya lihat). Daftar pesan gagal 24 jam terakhir dengan penyebab berbahasa awam; kirim ulang atomik (anti klik ganda), butuh WhatsApp terhubung, pengiriman "tidak terkonfirmasi" wajib konfirmasi (risiko ganda). Pesan masuk diproses ulang dengan kunci kuota baru (`messages.process_attempts`) agar AI tetap dihitung. **Keputusan pengguna: admin platform TIDAK berhak mengirim WhatsApp → tidak ada versi admin** (tes menjaga ini). Notifikasi gagal kirim kini mengarah ke halaman ini. |
| 2026-10-12 | FR-001 + NFR-005 (branch `feature/recaptcha-backup`): (1) reCAPTCHA v2 di form daftar lewat `RecaptchaVerifier` bersama (gagal-tertutup: Google tidak terjangkau/secret kosong = ditolak; form kontak ikut memakai dan tidak lagi error 500 bila Google down); diverifikasi ke Google dengan key uji publik. (2) Backup terenkripsi (`backup:run` harian, `backup:verify` mingguan, `backup:status`, `backup:decrypt`, `backup:key`), tujuan S3-kompatibel, retensi 14 hari (maks 30). Terbukti di MySQL lokal: backup → restore ke DB sementara 39 tabel cocok; berkas rusak/terpotong ditolak; pemulihan bencana (decrypt + impor) 8 pengguna cocok. Runbook: `docs/BACKUP-RESTORE.md`. |
| 2026-10-13 | NFR-003/NFR-004 (branch `chore/composer-security-update`): `composer update` menutup seluruh 61 advisory (`composer audit` = 0). Laravel 12.53→12.69.3, Filament 5.0→5.10.1 (batas `5.0` di composer.json yang mengunci versi diubah ke `^5.0`), Livewire 4.2→4.4.7, Guzzle 7.10→7.15.5, league/commonmark 2.8→2.10.3, tiptap-php 2.1→2.2.0, paket Symfony 7.4.x. Pest 621/621 lulus. 11 tes isolasi tenant perlu diubah: test harness Livewire 4.4 tidak lagi melempar `ModelNotFoundException` melainkan merender respons 404 (perilaku HTTP produksi tidak berubah); tes kini memakai `assertNotFound()` dan memeriksa data tetap utuh. Smoke test HTTP (publik, /admin/login, /login, /register, aset Filament) 200. **Belum:** sinkron aset Filament ke `public_html/` (hanya bila deploy ke Hostinger), uji manual login admin dengan akun nyata. |
