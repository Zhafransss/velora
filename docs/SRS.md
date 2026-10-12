# SRS — Velora AI (Chatbot AI Customer Service Multi-Tenant)

| Item | Nilai |
|---|---|
| Versi | 0.1 (draft untuk review) |
| Tanggal | 2026-10-11 |
| Pemilik produk | Velora Digital Works |
| Sumber | `docs/Velora_AI_PRD_V1_MultiTenant_Updated.pdf` (PRD V1.0) + keputusan diskusi 2026-10-11 |
| Dokumen pendamping | `docs/RTM.md` (status per requirement, wajib diperbarui tiap sesi) |
| Status | Draft. Bagian bertanda **TBD** menunggu keputusan pemilik produk. |

> Dokumen ini menggabungkan konteks bisnis (pengganti BRD) dan spesifikasi kebutuhan, sesuai `CLAUDE.md` Fase 1–2.
> Bila SRS ini bertentangan dengan PRD, **SRS yang berlaku** karena memuat keputusan yang lebih baru (lihat Bagian 8).

---

## 1. Konteks Bisnis

### 1.1 Latar belakang dan masalah bisnis

Velora Digital Works saat ini menjual jasa pembuatan website untuk UMKM (target Purwokerto) melalui website company profile `veloradigitalworks.com`. Pendapatan masih berbasis proyek satu kali.

UMKM yang menjadi target memiliki masalah berulang: pertanyaan pelanggan di WhatsApp yang sama dijawab manual, informasi harga dan produk tersebar, dan jawaban admin tidak konsisten. Membuat chatbot sendiri membutuhkan kemampuan teknis dan API key AI yang tidak dimiliki UMKM.

Velora AI menjawab masalah itu sebagai layanan berlangganan: pemilik bisnis mengisi data bisnisnya, menghubungkan nomor WhatsApp dengan memindai QR, dan chatbot menjawab pelanggan berdasarkan data tersebut.

**Urgensi bagi Velora:** produk ini mengubah pendapatan dari proyek satu kali menjadi berulang (langganan), dan memanfaatkan basis klien website yang sudah ada.

### 1.2 Proses bisnis As-Is dan To-Be

| Aspek | As-Is (sekarang) | To-Be (dengan Velora AI) |
|---|---|---|
| Pertanyaan pelanggan | Dijawab manual oleh pemilik/admin | FAQ dan pertanyaan rutin dijawab otomatis oleh bot |
| Sumber informasi | Tersebar (chat lama, kepala pemilik) | Satu knowledge base terstruktur per bisnis |
| Konsistensi jawaban | Bergantung siapa yang membalas | Seragam, berdasarkan data bisnis |
| Visibilitas | Tidak tahu pertanyaan apa yang sering muncul | Riwayat percakapan dan penggunaan di dashboard |
| Setup chatbot | Butuh developer dan API key sendiri | Daftar, isi data, scan QR; API key dikelola Velora |

### 1.3 Tujuan bisnis (Business Goals)

| ID | Tujuan | Ukuran keberhasilan | Status ukuran |
|---|---|---|---|
| BG-01 | Mendapatkan pelanggan berlangganan | **5 tenant berbayar** | Target dari pemilik produk. Jangka waktu **TBD** (asumsi: 3–6 bulan setelah pilot). |
| BG-02 | Chatbot menjawab akurat dari data bisnis | Akurasi ≥ 90% pada test set | Usulan PRD, belum terukur |
| BG-03 | Layanan andal | ≥ 99% pesan valid berhasil diproses (kondisi normal) | Usulan PRD, belum terukur |
| BG-04 | Data tenant aman | 0 insiden akses data lintas tenant | PRD |
| BG-05 | Biaya terkendali | 0 pemakaian AI tanpa tercatat; margin per paket positif | Margin **TBD** (harga paket dan biaya per pesan belum ditetapkan) |
| BG-06 | Aktivasi pengguna | ≥ 70% pengguna pilot mengaktifkan minimal satu kanal | Usulan PRD |
| BG-07 | Kontinuitas bisnis website | Website company profile tetap tersedia selama dan setelah migrasi ke VPS | Keputusan 2026-10-11 |

### 1.4 Scope

**In-scope (MVP):**
- Registrasi, login, workspace (tenant), profil bisnis
- Knowledge base: produk, layanan, FAQ
- Konfigurasi AI (persona, bahasa, pesan pembuka, fallback)
- AI gateway server-side (Gemini dan Groq)
- Tes chatbot di dashboard
- Kanal **WhatsApp melalui Baileys** (multi-session, tenant memindai QR sendiri)
- Riwayat percakapan, usage ledger, kuota harian
- Paket Small dan Medium dengan entitlement terpusat
- Dashboard tenant (Blade + Livewire) dan admin platform (Filament)
- Penagihan **manual** untuk pilot
- Pindah website company profile dan aplikasi ke satu aplikasi di satu VPS

**Out-of-scope / ditunda:**
- **Website widget** (ditunda atas keputusan 2026-10-11; ID FR-010 dipertahankan dengan status Deferred)
- Developer API (bergantung pada widget/Medium, ditunda)
- Payment gateway dan billing otomatis
- Multi-agent inbox, multi-cabang, CRM, broadcast WhatsApp, voice bot, fine-tuning, analitik sentimen
- Human handover (lihat Bagian 9, usulan notifikasi minimal)
- WhatsApp Cloud API resmi (arsitektur disiapkan lewat adapter, tidak diimplementasi)
- Paket Enterprise (tanpa batas harian tetap) tidak diimplementasi pada MVP

### 1.5 Stakeholder dan peran

| Peran | Kebutuhan utama |
|---|---|
| Pemilik produk / developer tunggal | Membangun, mengoperasikan, dan menjual produk |
| Pemilik UMKM (tenant owner) | Mengurangi pekerjaan membalas pertanyaan berulang, memantau pemakaian |
| Admin customer service (anggota tenant) | Memantau percakapan, memperbaiki FAQ |
| Pelanggan akhir tenant | Mendapat jawaban cepat dan benar lewat WhatsApp |
| Administrator platform (Velora) | Mengelola tenant, paket, penggunaan, dan gangguan |

### 1.6 Batasan (Constraints)

| Batasan | Nilai |
|---|---|
| Tim | 1 developer |
| Infrastruktur | 1 VPS Ubuntu: 4 vCPU, 8 GB RAM, 100 GB SSD, 1 snapshot |
| Domain | Satu domain `veloradigitalworks.com`; website lama dan hosting lama dihapus setelah cutover terverifikasi |
| Stack wajib | Laravel 12, PHP, Filament 5 (admin saja), Blade, Livewire, Tailwind 4, Vite 7, MySQL |
| Penyedia AI | Gemini dan Groq, akun **berbayar** (dikonfirmasi pemilik produk) |
| Pengujian WhatsApp | 3 nomor testing |
| Deadline | **TBD** |
| Budget | **TBD** (biaya VPS sudah ada) |

---

## 2. Arsitektur Ringkas

Modular monolith Laravel dalam **satu repo**, ditambah satu service Node.js (konektor Baileys) sebagai proses terpisah.

```
Pelanggan WA ──> WhatsApp ──> [Konektor Node/Baileys] ──(HMAC, localhost)──> Laravel
                                       ^                                       |
                                       └──────(kirim balasan)──────────────────┤
Laravel: Auth/Tenancy ─ KnowledgeBase ─ Usage/Quota ─ AI Gateway ─ Chat ─ Admin (Filament)
Queue (database driver, Supervisor):  antrean `ai` dan `default` terpisah
Dashboard tenant: /app (Blade + Livewire)     Admin platform: /admin (Filament)     Website publik: /
```

| Komponen | Keputusan |
|---|---|
| Isolasi tenant | Satu database, kolom `tenant_id`, trait `BelongsToTenant` + global scope; policy di server |
| Akun | Tabel `users` bersama; admin platform ditandai `is_platform_admin`; tenant lewat `tenant_members` |
| Konektor WhatsApp | Node.js + Baileys, satu socket dan satu auth state per channel; hanya listen di `127.0.0.1` |
| Adapter | Interface `WhatsAppChannelAdapter` agar Baileys dapat diganti Cloud API tanpa mengubah modul AI |
| Antrean | `database` driver + Supervisor `queue:work` (VPS memungkinkan worker persisten) |
| Struktur kode | `app/Domain/*` (Tenancy, Billing, KnowledgeBase, Chat, AI, Channels/WhatsApp, Usage) terpisah dari kode website |
| Penamaan tabel | Katalog layanan tenant memakai `catalog_services` agar tidak bentrok dengan `services` milik website |

### Tech stack

```yaml
project_name: "Velora (website + Velora AI)"
client_type: "Produk sendiri (SaaS untuk UMKM)"
frontend: { framework: "Blade + Livewire + Alpine", styling: "Tailwind 4 (Vite 7)", language: "PHP/JS" }
backend: { framework: "Laravel 12", language: "PHP 8.3", api_style: "Controller Blade/Livewire; endpoint internal untuk konektor", auth: "Session Laravel" }
database: { primary: "MySQL 8", cache: "database", orm: "Eloquent" }
infrastructure: { hosting_web: "VPS Ubuntu 4vCPU/8GB/100GB", process_manager: "Supervisor (queue) + PM2/systemd (konektor Node)", ssl: "Let's Encrypt", ci_cd: "manual deploy (TBD)", backup: "MySQL dan auth session ke luar server" }
third_party_integration: { messaging: "Baileys (tidak resmi)", ai: "Gemini + Groq (berbayar)", analytics: "GTM + GA4 (website)", payment_gateway: "ditunda" }
security_requirements: { data_sensitivity: "percakapan pelanggan (data pribadi)", compliance: "UU PDP (perlu ditinjau)" }
```

---

## 3. Persyaratan Fungsional

Penomoran mengikuti tabel PRD bagian 4.1 (FR-001–016). Penomoran FR di PRD bagian 6 berbeda dan **tidak dipakai** di sini. Prioritas: **M**=Must, **S**=Should, **C**=Could, **D**=Deferred.

### FR-001 Authentication (M)
Registrasi, login, logout, reset password dengan token kedaluwarsa, verifikasi email. Password di-hash Laravel. Registrasi publik dapat dibatasi invite-only selama pilot (**TBD**).

### FR-002 Workspace dan profil bisnis (M)
Pengguna membuat workspace (tenant) dengan nama, jenis usaha, deskripsi, zona waktu (default Asia/Jakarta), jam operasional, kontak. Struktur membership (owner/admin/viewer) disiapkan; MVP memakai satu workspace aktif per pengguna.

### FR-003 Tenant isolation (M)
Seluruh data bisnis tenant memiliki `tenant_id`. Query tenant berasal dari konteks otorisasi, bukan input request. Admin platform `/admin` hanya dapat diakses `is_platform_admin`; pengguna tenant tidak dapat membukanya.

### FR-004 Subscription dan entitlement (M)
Plan, entitlement, dan status subscription (trialing, active, past_due, suspended, canceled, expired). Hak akses berasal dari satu layanan entitlement terpusat, bukan kondisi hard-coded di controller. Penagihan manual dicatat lewat admin.

| Fitur | Small | Medium |
|---|---|---|
| Pesan AI per hari | 100 | 1.000 |
| WhatsApp | Ya | Ya |
| Website widget | Tidak | Ditunda (FR-010) |
| Developer API | Tidak | Ditunda |
| Produk/layanan/FAQ, tes chatbot, analitik dasar | Ya | Ya |
| Riwayat percakapan | Dasar | Penuh |

Harga paket: **TBD**. Fitur "tahap lanjut" tidak boleh dipasarkan seolah sudah tersedia.

### FR-005 Knowledge base (M)
CRUD produk, layanan, FAQ. Stok `NULL` berarti tidak diketahui. Pencarian awal: karena jumlah data UMKM kecil, konteks disusun dari data tenant yang relevan (strategi retrieval final **TBD** setelah uji akurasi). Dokumen (`documents`) di luar MVP kecuali diputuskan lain.

### FR-006 AI configuration (M)
Persona, gaya, bahasa, instruksi, pesan pembuka, pesan fallback, aturan pengakuan ketidakpastian. API key provider tidak terlihat tenant.

### FR-007 AI gateway (M)
Interface provider konsisten dengan adapter Gemini dan Groq, secret server-side, timeout, retry terbatas, fallback antarprovider, normalisasi error, pencatatan token dan biaya bila tersedia, batas token output.

Aturan akurasi: AI tidak boleh mengarang harga, stok, produk, atau kebijakan. Harga dan stok dibaca dari database saat diperlukan. Bila data tidak ada, bot mengaku tidak tahu dan menawarkan langkah berikutnya. Pesan pelanggan dianggap input tidak tepercaya (anti prompt injection).

### FR-008 Chatbot test (M)
Tenant menguji pertanyaan di dashboard tanpa mengirim pesan ke pelanggan. Penggunaan uji dicatat terpisah (`ai_test_messages`) tetapi tetap melewati kontrol kuota dan biaya.

### FR-009 WhatsApp via Baileys (M)
| Sub-ID | Persyaratan |
|---|---|
| FR-009.1 | Konektor Node mengelola banyak socket, satu auth state per channel; creds **dan** Signal keys dipersistenkan |
| FR-009.2 | Tenant membuat channel dan memindai QR; QR hanya tampil untuk anggota berwenang dan tidak masuk log |
| FR-009.3 | Pesan masuk dipetakan ke channel terverifikasi, dideduplikasi, diteruskan ke Laravel |
| FR-009.4 | Balasan keluar selalu memilih channel milik percakapan, tidak pernah socket global atau berdasarkan nomor penerima |
| FR-009.5 | Reconnect dengan exponential backoff; status connected/disconnected/logged_out dibedakan |
| FR-009.6 | Pencabutan/penghapusan channel menutup koneksi dan menghapus kredensial sesuai retensi |
| FR-009.7 | Mitigasi blokir: hanya membalas pesan masuk, jeda balasan acak, batas laju per nomor |
| FR-009.8 | Interface `WhatsAppChannelAdapter` agar konektor dapat diganti |

Kriteria penerimaan: WA-MT-01 sampai WA-MT-08 (PRD bagian 17.5).

### FR-010 Website widget (D, Deferred)
Ditunda. Spesifikasi PRD bagian FR-008 widget dipertahankan sebagai referensi untuk fase berikutnya.

### FR-011 Conversation (M)
Menyimpan channel, tenant, identitas pelanggan yang diperlukan, isi pesan, state pemrosesan dan pengiriman, provider/model, penanda test. Status "AI selesai" dipisahkan dari "terkirim". Retensi data: **TBD**.

### FR-012 Usage metering dan kuota (M)
- Kuota harian, dihitung per tenant untuk semua kanal, berdasarkan zona waktu tenant (default Asia/Jakarta).
- Satu pesan pelanggan yang diproses AI = 1 unit. Retry provider dalam satu request tidak menambah unit. Request gagal validasi sebelum AI tidak memakai kuota.
- Reservasi kuota **atomik** (transaksi/lock); dua pesan paralel dengan sisa 1 unit hanya satu yang lolos.
- Ledger `usage_events` (audit, koreksi lewat event adjustment) + agregat `daily_usage`.
- AI selesai tetapi gagal kirim dicatat terpisah; kirim ulang tidak memanggil AI lagi.
- Event duplikat tidak menimbulkan pemakaian ganda (idempotency key).

### FR-013 Dashboard tenant (M)
Di `/app` (Blade + Livewire): ringkasan penggunaan dan status kanal, kelola profil, knowledge base, konfigurasi AI, koneksi WhatsApp (QR/status), tes chatbot, riwayat percakapan.

### FR-014 Platform admin (M)
Filament `/admin` khusus admin platform: tenant, plan, entitlement, subscription (termasuk pencatatan penagihan manual), usage, status koneksi, failed jobs, audit log. Tindakan administratif penting dicatat.

### FR-015 Reliability (M)
Queue dengan retry terkontrol, idempotency, penanganan failed job, logging dengan request ID, webhook/endpoint internal ringan sementara AI berjalan di antrean.

### FR-016 Testing (M)
Pest 4 untuk alur bisnis, kuota, isolasi tenant, integrasi (provider palsu di CI). Test isolasi tenant dan kuota **memblokir deployment** bila gagal.

### FR-017 Migrasi dan cutover website ke VPS (M) — *ditambahkan 2026-10-11, bukan dari PRD*
Website company profile (halaman publik + `/admin` konten) dipindahkan ke aplikasi gabungan di VPS, dengan data dan file upload, tanpa downtime berarti. Cutover DNS dilakukan di tahap akhir; hosting lama dipertahankan sebagai rollback sampai terverifikasi, baru dihapus. Ditelusuri ke BG-07.

---

## 4. Persyaratan Non-Fungsional

| ID | Area | Persyaratan |
|---|---|---|
| NFR-001 | Performa | p95 < 500 ms untuk endpoint dashboard sederhana di staging (tidak termasuk AI eksternal). Latensi chatbot diukur terpisah. |
| NFR-002 | Keandalan | Retry terkontrol, idempotency, monitoring failed job, pemisahan generated vs sent. Target uptime 99% adalah target internal, **bukan SLA** (usulan, perlu konfirmasi). |
| NFR-003 | Keamanan | Otorisasi di server, isolasi tenant, secret hanya di backend/`.env`, redaksi log, audit tindakan sensitif. Konektor Node tidak terbuka ke internet (bind `127.0.0.1`, autentikasi HMAC/token internal). |
| NFR-004 | Hardening VPS | SSH hanya key, firewall hanya 22/80/443, MySQL tidak terbuka keluar, `fail2ban`, HTTPS wajib, pembaruan keamanan OS. |
| NFR-005 | Backup | Backup MySQL harian **dan** direktori/tabel auth session Baileys ke penyimpanan **di luar server**; restore diuji. Snapshot VPS (hanya 1) tidak dianggap backup. |
| NFR-006 | Privasi | Data percakapan tidak dipakai untuk training otomatis. Kebijakan privasi, syarat layanan, dan penjelasan pemrosesan oleh penyedia AI siap sebelum pilot dengan pelanggan nyata. Tinjau UU PDP. Retensi dan penghapusan data **TBD**. |
| NFR-007 | Skalabilitas | Cukup untuk pilot 5 tenant. Kapasitas session Baileys per VPS diukur pada Tahap 0 dan menjadi batas operasional. |
| NFR-008 | Isolasi proses | Queue worker dan konektor Node berjalan sebagai proses terpisah dari PHP-FPM dengan batas memori dan auto-restart, agar gangguan modul AI tidak menjatuhkan website. |
| NFR-009 | Observabilitas | Request ID, status job, latensi provider, error rate, token/biaya, usage per tenant. |
| NFR-010 | Maintainability | Service layer, Form Request, Policy, migration, test otomatis; tabel website tidak diubah oleh migration SaaS. |
| NFR-011 | Biaya | Batas token output, rate limit, ledger, alarm biaya, proteksi abuse. |
| NFR-012 | Aksesibilitas | Dashboard responsif dan dapat dinavigasi dengan keyboard. |

---

## 5. Rencana Tahap dan Dependensi

| Tahap | Isi | Syarat selesai | Bergantung pada |
|---|---|---|---|
| 0 | PoC konektor Baileys (3 nomor testing) | WA-MT-01–04 lolos; memori per session terukur | VPS, 3 nomor |
| 1 | Tenancy, auth, dashboard minimal, perbaikan `canAccessPanel` | Test isolasi tenant lulus | – |
| 2 | Knowledge base, AI config, AI gateway, tes chatbot | Akurasi diukur tanpa WhatsApp | Tahap 1 |
| 3 | Konektor ke inti AI, kuota atomik, idempotency, percakapan | Satu tenant end-to-end di WhatsApp | Tahap 0, 2 |
| 4 | Admin Filament, subscription manual, hardening, backup | 2–3 tenant pilot berjalan | Tahap 3 |
| 5 | Migrasi website dan cutover DNS, penghapusan hosting lama | Website di VPS terverifikasi | Tahap 4 |

Branching: `main` → `staging` → `feature/*`. VPS dipakai sebagai staging selama pengembangan; website tetap di hosting lama sampai Tahap 5.

---

## 6. Risiko

| ID | Risiko | Dampak | Penanganan |
|---|---|---|---|
| R-01 | Baileys tidak resmi; nomor tenant dapat diblokir | Nomor bisnis pelanggan hilang | **Diterima dengan sadar** (keputusan 2026-10-11). Mitigasi FR-009.7, teks onboarding jujur, syarat layanan, adapter siap diganti. |
| R-02 | Semua session dari satu IP datacenter | Risiko blokir lebih tinggi | Diterima; jeda balasan, batas laju, hanya balas pesan masuk |
| R-03 | VPS tunggal untuk website dan SaaS | Satu gangguan mematikan keduanya | Backup luar server, dokumentasi restore, monitoring uptime, NFR-008 |
| R-04 | Biaya AI per pesan belum terukur, harga paket belum ada | Margin bisa negatif | Ukur token pada uji akurasi Tahap 2; tetapkan harga setelahnya |
| R-05 | Pengembang tunggal | Jadwal dan kontinuitas | Urutan berbasis risiko (Tahap 0 dulu), scope MVP dipotong (widget, billing otomatis ditunda) |
| R-06 | Kebijakan WhatsApp/Meta terkait penyedia AI | Larangan komersial | Dipantau; adapter terpisah; peninjauan sebelum peluncuran komersial |
| R-07 | Migrasi website produksi | Downtime/kehilangan data | Cutover terakhir, rollback via DNS, hosting lama dipertahankan |
| R-08 | Chat pelanggan berisi data pribadi | Risiko hukum dan kepercayaan | NFR-006 |
| R-09 | Tier gratis/rendah penyedia AI: uji 2026-10-12 menunjukkan Groq `on_demand` dibatasi 8.000 token/menit (~6 percakapan/menit dgn prompt ~1.250 token) dan kuota `gemini-3.5-flash` akun habis | Pesan pelanggan gagal dijawab saat ramai, walau hanya 5 tenant | Upgrade ke tier berbayar (Groq Dev Tier / Gemini berbayar) SEBELUM pilot; antrean + backoff di Tahap 3; pantau 429 di `ai_calls` |

---

## 7. Hutang Teknis Website yang Terbawa

Dicatat di RTM bagian Hutang Teknis. Yang **harus** selesai sebelum ada pengguna tenant: `canAccessPanel()` (FR-003).

---

## 8. Log Keputusan

| ID | Tanggal | Keputusan | Menggantikan PRD |
|---|---|---|---|
| DEC-01 | 2026-10-11 | Multi-tenant sungguhan sejak MVP | – |
| DEC-02 | 2026-10-11 | Satu repo, digabung dan dideploy di VPS (website + SaaS) | PRD: Hostinger |
| DEC-03 | 2026-10-11 | Kanal WhatsApp via Baileys, tenant scan QR sendiri | PRD: Cloud API sebagai rekomendasi utama |
| DEC-04 | 2026-10-11 | Widget ditunda; Small hanya WhatsApp | PRD: widget di MVP |
| DEC-05 | 2026-10-11 | Dashboard tenant bukan Filament (Blade + Livewire); Filament hanya admin | – |
| DEC-06 | 2026-10-11 | Kuota harian (bukan bulanan) | – |
| DEC-07 | 2026-10-11 | Penagihan manual; payment gateway ditunda | – |
| DEC-08 | 2026-10-11 | Tabel katalog tenant: `catalog_services` | PRD: `services` |
| DEC-09 | 2026-10-11 | Penomoran FR mengikuti PRD 4.1 (FR-001–016) | PRD bagian 6 |
| DEC-10 | 2026-10-11 | Dashboard tenant di `/app` pada domain yang sama | – (default, boleh diubah) |
| DEC-11 | 2026-10-12 | Registrasi publik terbuka; akses fitur ditentukan status langganan workspace (trialing/active dan periode belum lewat), bukan persetujuan admin per akun. Tanpa langganan: dashboard + halaman langganan saja. Aktivasi tetap manual setelah pembayaran dicatat (DEC-07) | Menutup OQ-04 (sebelumnya: invite-only direkomendasikan) |

---

## 9. Keputusan Terbuka

| ID | Pertanyaan | Dibutuhkan sebelum |
|---|---|---|
| OQ-01 | Jangka waktu target 5 tenant berbayar (asumsi 3–6 bulan setelah pilot) | Finalisasi BG-01 |
| OQ-02 | Harga paket Small dan Medium | Tahap 4 (setelah biaya per pesan terukur) |
| OQ-03 | Pembeda paket Medium tanpa widget (selain kuota 1.000, riwayat penuh) | Pemasaran paket |
| OQ-04 | ~~Registrasi publik atau invite-only~~ — **Diputuskan DEC-11** (publik + gerbang langganan) | – |
| OQ-05 | Retensi percakapan dan prosedur penghapusan data. **Sementara:** `messages` disimpan tanpa batas waktu; belum ada penghapusan | Pilot dengan pelanggan nyata |
| OQ-06 | Notifikasi ke pemilik saat bot gagal menjawab / kuota habis (usulan minimal pengganti human handover). **Sementara:** pesan yang gagal/terlewat hanya tercatat (status `failed`/`quota_exceeded`/`skipped`); pelanggan menerima `fallback_message` bila AI gagal, dan TIDAK menerima apa pun saat kuota habis | Tahap 3 |
| OQ-07 | Penyimpanan auth Baileys produksi: direktori persisten vs MySQL terenkripsi | Akhir Tahap 0 |
| OQ-08 | Deadline dan alur deploy (manual atau CI) | Perencanaan Tahap 1 |
| OQ-09 | Strategi retrieval knowledge base (konteks penuh vs pencarian) | Tahap 2 — **sementara: konteks penuh ber-batas (AI_CONTEXT_MAX_CHARS); finalisasi setelah uji akurasi 2.6** |
| OQ-10 | Ketentuan pemrosesan data pada akun Gemini/Groq berbayar yang dipakai | Sebelum pilot |
