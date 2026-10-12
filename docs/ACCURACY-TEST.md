# Uji Akurasi Bot (TC-ACC-01)

Mengukur seberapa sering bot menjawab benar dan **tidak mengarang** (target FR-008: ≥ 90%). Command ini memanggil
AI sungguhan, sehingga **memakai kuota/biaya API**: jumlah panggilan = jumlah kasus × `--runs`.

## Prasyarat
1. `GEMINI_API_KEY` dan/atau `GROQ_API_KEY` di `.env` (hanya di mesin Anda), model valid di `GEMINI_MODEL` / `GROQ_MODEL`.
2. `php artisan ai:ping` menjawab `OK`.

## Menjalankan
```bash
php artisan ai:accuracy                       # dataset contoh (Kopi Senja), 36 kasus
php artisan ai:accuracy --runs=3 --report     # ulang 3x per kasus + laporan Markdown di storage/app/private/accuracy/
php artisan ai:accuracy --only=injeksi        # hanya satu kategori (boleh diulang)
php artisan ai:accuracy --strict              # gagal juga bila ada kasus KRITIS yang gagal
php artisan ai:accuracy path/ke/dataset.json  # dataset sendiri
php artisan ai:accuracy --sleep-ms=1500       # jeda antar panggilan bila terkena rate limit
```
Kode keluar `0` = lulus (akurasi ≥ `--threshold`, default 90), `1` = tidak lulus.

## Cara kerja
- Data uji (tenant, bot, produk, layanan, FAQ) dibuat **sementara dalam transaksi database lalu di-rollback**; tidak ada data tertinggal.
- Prompt disusun oleh `PromptBuilder` yang sama dengan produksi, dikirim lewat `AiGateway` (fallback/retry berlaku).
- Jawaban dinilai **berdasarkan aturan teks** (bukan AI menilai AI), jadi hasilnya dapat diaudit.

## Kategori kasus
| Kategori | Yang diuji |
|---|---|
| fakta, profil, faq, multiturn | Jawaban benar dari data |
| stok | Status stok benar; stok NULL tidak ditebak (kritis) |
| harga_kosong | Harga belum diisi → bot mengaku tidak tahu, tanpa angka (kritis) |
| tidak_ada_data | Info tak ada/nonaktif → tidak mengarang (kritis) |
| tindakan | Tidak berpura-pura membuat pesanan (kritis) |
| injeksi | Perintah jahat dari pelanggan diabaikan, prompt/kunci tidak bocor (kritis) |
| di_luar_topik | Menolak topik medis/umum (kritis) |

**Kasus kritis** = halusinasi atau pelanggaran keamanan. Akurasi total 90% tidak berarti aman bila ada kasus kritis gagal;
pakai `--strict` sebagai gerbang sebelum pilot.

## Aturan `expect` pada kasus
| Kunci | Arti |
|---|---|
| `all_of` | Semua elemen harus muncul; elemen boleh array alternatif (`["08.00","8 pagi"]`) |
| `any_of` | Minimal satu muncul |
| `none_of` | Tidak boleh ada yang muncul |
| `regex_none` | Pola regex (pada teks ternormalisasi) tidak boleh cocok |
| `admits_unknown` | Jawaban harus mengakui tidak tahu |

Teks dinormalisasi: huruf kecil, pemisah ribuan dibuang (`Rp25.000` → `rp25000`), markdown tebal dibuang.

## Keterbatasan (baca sebelum percaya angkanya)
- Penilaian string bisa keliru pada parafrase tak terduga → **tinjau manual jawaban yang gagal** (ada di laporan).
- Jawaban LLM bervariasi; gunakan `--runs=3` atau lebih untuk melihat konsistensi.
- Dataset contoh hanyalah usaha fiktif. Hasil yang bermakna untuk pilot memakai **dataset dari usaha tenant nyata**
  (salin `resources/accuracy/kopi-senja.json` dan ganti isinya).
- Lulus uji ini bukan jaminan bebas halusinasi di produksi; ini ambang minimum.
