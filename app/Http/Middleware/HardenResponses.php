<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Pengamanan dasar semua respons (website, /app, /admin, Livewire). Dipasang global di bootstrap/app.php.
 *
 * - Anti clickjacking: halaman tidak boleh disematkan di situs lain (aksi seperti "Putuskan WhatsApp" atau
 *   "Tangguhkan workspace" tidak bisa dipancing lewat iframe tersembunyi).
 * - Sengaja TIDAK memasang CSP penuh (script-src dsb.): website memakai GTM, Alpine dari CDN, reCAPTCHA, dan skrip
 *   inline, sehingga CSP penuh perlu inventaris sumber dan uji tersendiri. Hanya `frame-ancestors` yang dipasang.
 */
class HardenResponses
{
    private const HSTS_MAX_AGE = 31536000; // 1 tahun

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $headers = [
            'X-Frame-Options' => 'SAMEORIGIN',
            'Content-Security-Policy' => "frame-ancestors 'self'",
            'X-Content-Type-Options' => 'nosniff',
            'Referrer-Policy' => 'strict-origin-when-cross-origin',
            'Permissions-Policy' => 'camera=(), microphone=(), geolocation=()',
        ];

        // HSTS hanya lewat HTTPS dan hanya di produksi: header ini membuat browser menolak HTTP selama setahun.
        // Tanpa includeSubDomains agar subdomain yang belum ber-HTTPS tidak ikut terkunci.
        if ($request->isSecure() && app()->isProduction()) {
            $headers['Strict-Transport-Security'] = 'max-age=' . self::HSTS_MAX_AGE;
        }

        foreach ($headers as $name => $value) {
            // Jangan menimpa header yang sengaja diset oleh respons tertentu.
            if (! $response->headers->has($name)) {
                $response->headers->set($name, $value);
            }
        }

        return $response;
    }
}
