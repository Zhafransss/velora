<?php

namespace App\Services\Whatsapp;

use App\Models\Channel;

/**
 * Antarmuka kanal WhatsApp (FR-009.8) agar konektor Baileys dapat diganti (mis. Cloud API) tanpa mengubah
 * logika percakapan. SEMUA pemanggilan memilih socket dari $channel — tidak pernah dari nomor penerima (FR-009.4).
 */
interface WhatsAppChannelAdapter
{
    /** @throws ChannelSendException */
    public function send(Channel $channel, string $to, string $text): SendResult;

    /** Buat/pulihkan session (idempoten). @return array<string, mixed> */
    public function start(Channel $channel): array;

    /** @return array<string, mixed> */
    public function status(Channel $channel): array;

    /** QR terakhir bila ada. Jangan pernah dicatat ke log. */
    public function qr(Channel $channel): ?string;

    /** Hentikan session; $logout=true juga menautkan-lepas perangkat dan menghapus kredensial. */
    public function stop(Channel $channel, bool $logout = false): array;
}
