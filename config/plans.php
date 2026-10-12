<?php

/*
 * Batas paket (sementara; tabel plans/entitlements menyusul di Tahap 4 — FR-004).
 * daily_ai_messages = kuota pesan pelanggan yang diproses AI per hari (FR-012).
 * null = tanpa batas keras (fair use; tetap dicatat). Harga paket belum diputuskan (OQ-02).
 */
return [
    'small' => ['daily_ai_messages' => 100],
    'medium' => ['daily_ai_messages' => 1000],
    'enterprise' => ['daily_ai_messages' => null],
];
