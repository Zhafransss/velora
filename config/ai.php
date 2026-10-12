<?php

/*
 * AI gateway (FR-007). Semua secret hanya dibaca dari .env di server — tidak pernah dari input tenant.
 * Nama model berubah seiring waktu: verifikasi ke dokumentasi provider dan ganti lewat .env, bukan kode.
 */
return [

    // Urutan percobaan. Provider tanpa API key dilewati otomatis.
    'order' => array_filter(array_map('trim', explode(',', env('AI_PROVIDER_ORDER', 'gemini,groq')))),

    // Batas keras token keluaran per jawaban (menjaga biaya; permintaan tidak bisa melebihi ini).
    'max_output_tokens' => (int) env('AI_MAX_OUTPUT_TOKENS', 500),

    // Rendah agar jawaban faktual/konsisten (bot tidak boleh mengarang).
    'temperature' => (float) env('AI_TEMPERATURE', 0.3),

    // Percobaan ulang per provider untuk galat sementara (timeout, 429, 5xx, respons tidak valid).
    'retries' => (int) env('AI_RETRIES', 1),
    'retry_delay_ms' => (int) env('AI_RETRY_DELAY_MS', 400),

    // Total waktu seluruh percobaan + fallback untuk satu jawaban; pelanggan WhatsApp tidak menunggu lama.
    'budget_seconds' => (int) env('AI_BUDGET_SECONDS', 45),

    'providers' => [
        'gemini' => [
            'api_key' => env('GEMINI_API_KEY'),
            'model' => env('GEMINI_MODEL', 'gemini-2.5-flash'),
            'base_url' => env('GEMINI_BASE_URL', 'https://generativelanguage.googleapis.com'),
            'timeout' => (int) env('GEMINI_TIMEOUT', 20),
            // Harga USD per 1 juta token (opsional; kosong = biaya tidak dihitung).
            'price_input_per_mtok' => env('GEMINI_PRICE_INPUT'),
            'price_output_per_mtok' => env('GEMINI_PRICE_OUTPUT'),
        ],
        'groq' => [
            'api_key' => env('GROQ_API_KEY'),
            'model' => env('GROQ_MODEL', 'llama-3.3-70b-versatile'),
            'base_url' => env('GROQ_BASE_URL', 'https://api.groq.com/openai'),
            'timeout' => (int) env('GROQ_TIMEOUT', 20),
            'price_input_per_mtok' => env('GROQ_PRICE_INPUT'),
            'price_output_per_mtok' => env('GROQ_PRICE_OUTPUT'),
        ],
    ],
];
