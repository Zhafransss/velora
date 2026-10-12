# Pemrosesan Data oleh Penyedia AI (OQ-10)

Pertanyaan: *"Ketentuan pemrosesan data pada akun Gemini/Groq yang dipakai itu bagaimana?"*
Artinya: **apa yang dilakukan Google dan Groq terhadap pesan pelanggan tenant yang kita kirim ke API mereka** —
apakah dipakai melatih model, dibaca manusia, disimpan berapa lama, dan di negara mana. Ini penting karena pesan pelanggan
dapat berisi data pribadi (nama, alamat, nomor telepon, keluhan) milik pelanggan tenant, dan kita adalah pihak yang menyerahkannya.

> Diverifikasi dari halaman resmi pada **2026-10-12**; ketentuan dapat berubah — periksa ulang sebelum pilot.
> Ini ringkasan teknis, **bukan nasihat hukum**.

## Apa yang kita kirim ke penyedia AI
Per pertanyaan pelanggan: aturan platform, pengaturan bot, **data bisnis tenant** (produk, layanan, FAQ, profil), riwayat ≤ 10 pesan,
dan **teks pesan pelanggan**. Nama dan nomor WhatsApp pelanggan **tidak** dimasukkan ke prompt (hanya isi pesan yang mereka ketik).
Isi pesan sendiri bisa memuat data pribadi yang mereka tulis.

## Gemini (Google) — [Gemini API Additional Terms of Service](https://ai.google.dev/gemini-api/terms) (berlaku 23 Mar 2026)
| | Layanan **gratis** (*Unpaid*) | Layanan **berbayar** (*Paid*) |
|---|---|---|
| Dipakai melatih/memperbaiki produk Google | **Ya** | Tidak |
| Dibaca reviewer manusia | **Ya** (dilepas dari akun/API key/proyek) | Tidak disebut |
| Disimpan | – | Dicatat "untuk jangka waktu terbatas" hanya untuk deteksi pelanggaran dan kewajiban hukum (lamanya tidak disebut di halaman; laporan sekunder menyebut ±55 hari) |
| Data pribadi | Google: *"Do not submit sensitive, confidential, or personal information to the Unpaid Services."* | Diatur oleh Data Processing Addendum |
| Lokasi | – | Dapat disimpan/di-cache di negara mana pun Google beroperasi |

**Penentu "berbayar":** API key harus berasal dari **Google Cloud project yang terhubung ke akun penagihan (billing) aktif**.
Sekadar punya akun Google atau memakai AI Studio gratis tetap dihitung *Unpaid*.

## Groq — [Your Data](https://console.groq.com/docs/your-data)
- Bawaan: *"By default, Groq does not retain customer data for inference requests."* Metadata pemakaian dicatat (tanpa isi pesan).
- Pengecualian: dapat **mencatat sementara** input/output untuk menyelidiki galat keandalan atau dugaan penyalahgunaan, disimpan **hingga 30 hari**
  (kecuali kewajiban hukum). Fitur *batch* menyimpan berkas hingga 30 hari; *fine-tuning* menyimpan data sampai dihapus — **kita tidak memakai keduanya**.
- **Zero Data Retention (ZDR):** *"All customers may enable ZDR in Data Controls settings"* (admin organisasi). Bila aktif, pencatatan keandalan/abuse juga dimatikan.
- Lokasi data: server di **Amerika Serikat** (bucket Google Cloud AS).
- Pelatihan model: halaman ini tidak membahasnya; halaman privasi Groq menyatakan tidak melatih dengan data pengguna. Dokumen mengikat ada di
  *Groq Services Agreement* dan *Data Processing Addendum* (console.groq.com/docs/legal).

## Dampak untuk Velora AI
1. **Kunci Gemini yang dipakai harus berasal dari proyek berbayar.** Jika gratis, pesan pelanggan dapat dipakai melatih model Google dan dibaca manusia.
   (Catatan: kuota `gemini-3.5-flash` akun kita sempat habis, ciri umum tier gratis — **periksa**.)
2. **Aktifkan ZDR di Groq** (Console › Data Controls). Kita tidak memerlukan fitur yang bergantung pada penyimpanan.
3. Data pelanggan **keluar negeri** (AS dan negara lain milik Google). Ini perlu dinyatakan di **kebijakan privasi** dan syarat layanan (UU PDP).
4. Tenant adalah pengendali data pelanggan mereka; Velora memproses atas nama tenant. Syarat layanan harus menyatakan hal ini dan penyedia AI yang dipakai (sub-prosesor).
5. Retensi kita sendiri: percakapan dihapus **30 hari** (DEC-15). Penyedia AI punya retensi sendiri (di atas) yang tidak dapat kita hapus manual.
6. Jangan menyatakan di materi pemasaran bahwa data "tidak pernah keluar" atau "sepenuhnya privat".

## Checklist sebelum pilot (tindakan pemilik produk)
- [ ] Pastikan API key Gemini berasal dari Cloud project dengan **billing aktif** (Paid Services).
- [ ] Aktifkan **Zero Data Retention** di Groq Console › Data Controls; upgrade ke tier berbayar (juga untuk kapasitas, SRS R-09).
- [ ] Baca dan setujui **DPA** Google (Cloud) dan Groq; simpan salinannya.
- [ ] Susun **kebijakan privasi** dan **syarat layanan** yang menyebut: data apa yang diproses, penyedia AI (Google, Groq), transfer lintas negara, retensi 30 hari, hak pelanggan.
- [ ] Putuskan siapa yang memberi tahu pelanggan akhir (tenant) bahwa percakapan diproses AI (disarankan: teks pembuka bot atau profil WhatsApp bisnis).
- [ ] Periksa ulang halaman ketentuan kedua penyedia setiap kali berganti model/paket.
