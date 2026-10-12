<?php

namespace App\Services\Whatsapp;

use chillerlan\QRCode\Common\EccLevel;
use chillerlan\QRCode\Output\QROutputInterface;
use chillerlan\QRCode\QRCode;
use chillerlan\QRCode\QROptions;

/**
 * Mengubah payload QR WhatsApp menjadi gambar (data URI SVG) di sisi server. Tidak memakai layanan/CDN pihak ketiga
 * sehingga payload QR (yang memberi akses ke akun WhatsApp) tidak keluar dari server. Hasilnya TIDAK disimpan dan
 * tidak dicatat ke log.
 */
class QrRenderer
{
    public function dataUri(string $payload): string
    {
        $options = new QROptions([
            'outputType' => QROutputInterface::MARKUP_SVG,
            'outputBase64' => true,
            'eccLevel' => EccLevel::L,
            'scale' => 6,
            'addQuietzone' => true,
            'quietzoneSize' => 2,
        ]);

        return (new QRCode($options))->render($payload);
    }
}
