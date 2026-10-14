<?php

namespace App\Http\Controllers\Tenant\Auth;

/**
 * Pesan validasi password berbahasa Indonesia untuk form daftar, atur ulang password, dan undangan akun
 * (APP_LOCALE masih `en` dan belum ada file terjemahan). Rule Password::defaults() meneruskan pesan kustom ini.
 */
final class PasswordMessages
{
    public const MESSAGES = [
        'password.required' => 'Password wajib diisi.',
        'password.confirmed' => 'Ulangi password tidak sama dengan password.',
        'password.min' => 'Password minimal :min karakter.',
    ];
}
