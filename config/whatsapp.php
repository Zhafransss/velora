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

    // Percobaan kirim sebelum dinyatakan gagal.
    'send_max_attempts' => (int) env('WHATSAPP_SEND_MAX_ATTEMPTS', 4),
];
