<?php

/*
 * Retensi data (DEC-15): percakapan dihapus otomatis dari database sistem setelah N hari.
 * Penyedia AI punya retensi sendiri (lihat docs/DATA-PROCESSING.md). Cadangan (backup) harus berumur <= nilai ini
 * agar penghapusan benar-benar efektif.
 */
return [
    // Pesan, percakapan, dan riwayat tes chatbot.
    'conversation_days' => (int) env('CONVERSATION_RETENTION_DAYS', 30),

    // Notifikasi dashboard (DEC-16) — tidak memuat isi pesan pelanggan.
    'notification_days' => (int) env('NOTIFICATION_RETENTION_DAYS', 30),
];
