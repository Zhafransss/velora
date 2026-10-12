<?php

/*
 * Integrasi konektor WhatsApp (Baileys) — FR-009. Semua secret hanya dari .env di server.
 * Konektor hanya mendengarkan di 127.0.0.1; Laravel memanggilnya dan menerima webhook bertanda tangan HMAC.
 */
return [

    'connector_url' => env('CONNECTOR_URL', 'http://127.0.0.1:3100'),

    // Bearer token ke API konektor (sama dengan CONNECTOR_TOKEN di .env konektor).
    'connector_token' => env('CONNECTOR_TOKEN'),

    // Secret HMAC webhook (sama dengan WEBHOOK_SECRET di .env konektor).
    'webhook_secret' => env('CONNECTOR_WEBHOOK_SECRET'),

    // Webhook dengan timestamp lebih tua/baru dari ini (detik) ditolak (anti replay).
    'webhook_tolerance_seconds' => (int) env('CONNECTOR_WEBHOOK_TOLERANCE', 300),

    // Timeout panggilan kirim (konektor sengaja menunda 2-6 detik per pesan: mitigasi blokir).
    'send_timeout_seconds' => (int) env('CONNECTOR_SEND_TIMEOUT', 40),

    // Batas laju kirim per kanal per menit (FR-009.7); kelebihannya ditunda, bukan dibuang.
    'max_sends_per_minute' => (int) env('WHATSAPP_MAX_SENDS_PER_MINUTE', 20),

    // Tampilkan "sedang mengetik" ke pelanggan segera setelah pesannya diterima dan selama AI bekerja.
    'typing_indicator' => (bool) env('WHATSAPP_TYPING_INDICATOR', true),

    // Jumlah pesan terakhir yang dibawa sebagai konteks percakapan.
    'history_messages' => (int) env('WHATSAPP_HISTORY_MESSAGES', 10),

    // Penyapu pesan menggantung (messages:sweep-stuck, tiap 5 menit).
    //  - requeue_after: pesan yang belum diambil worker (received/pending) lebih lama dari ini diantrekan ulang.
    //  - stuck_after:   pesan yang sedang dipegang (processing/sending) lebih lama dari ini dianggap macet.
    //  - expire_after:  pesan yang masih belum terproses selama ini dinyatakan kedaluwarsa (membalas berjam-jam kemudian
    //                   lebih merugikan daripada tidak membalas).
    'sweep' => [
        'requeue_after_minutes' => (int) env('SWEEP_REQUEUE_AFTER_MINUTES', 5),
        'stuck_after_minutes' => (int) env('SWEEP_STUCK_AFTER_MINUTES', 10),
        'expire_after_minutes' => (int) env('SWEEP_EXPIRE_AFTER_MINUTES', 60),
        'batch' => 200,
    ],

    // Pesan gagal yang lebih tua dari ini tidak bisa dikirim ulang dari dashboard (membalas pertanyaan kemarin tidak berguna).
    'retry_window_hours' => (int) env('WHATSAPP_RETRY_WINDOW_HOURS', 24),

    // Percobaan kirim sebelum dinyatakan gagal.
    'send_max_attempts' => (int) env('WHATSAPP_SEND_MAX_ATTEMPTS', 4),
];
