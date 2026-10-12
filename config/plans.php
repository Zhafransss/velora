<?php

/*
 * Paket langganan (DEC-13). Pembeda paket = fitur yang dapat digunakan. Dibaca HANYA oleh
 * App\Services\Plans\Entitlements (satu layanan entitlement terpusat, FR-004) — jangan baca config ini
 * langsung di controller/komponen.
 *
 *   label              nama tampilan
 *   price_idr          harga per periode (rupiah); null = berdasarkan penawaran
 *   period_days        lama satu periode langganan (asumsi 30 hari)
 *   daily_ai_messages  kuota pesan pelanggan yang diproses AI per hari (FR-012); null = tanpa batas keras (fair use)
 *   history_days       berapa hari terakhir riwayat percakapan yang dapat dilihat; null = seluruh masa simpan
 *                      (percakapan dihapus otomatis setelah retention.conversation_days, DEC-15)
 *
 * Widget website dan Developer API ditunda (FR-010) dan belum menjadi entitlement.
 */
return [
    'small' => [
        'label' => 'Small',
        'price_idr' => 250_000,
        'period_days' => 30,
        'daily_ai_messages' => 100,
        'history_days' => 7,
    ],
    'medium' => [
        'label' => 'Medium',
        'price_idr' => 500_000,
        'period_days' => 30,
        'daily_ai_messages' => 1000,
        'history_days' => null,
    ],
    'enterprise' => [
        'label' => 'Enterprise',
        'price_idr' => null,
        'period_days' => 30,
        'daily_ai_messages' => null,
        'history_days' => null,
    ],
];
