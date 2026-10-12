<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;

/**
 * Menandai email pengguna terverifikasi tanpa tautan email. Berguna untuk pengujian manual (MAIL_MAILER=log)
 * dan dukungan; tidak mengubah hal lain pada akun.
 */
class VerifyUserEmail extends Command
{
    protected $signature = 'user:verify {email : Email akun}';

    protected $description = 'Tandai email sebuah akun sebagai terverifikasi.';

    public function handle(): int
    {
        $user = User::where('email', strtolower($this->argument('email')))->first();

        if (! $user) {
            $this->error('Akun tidak ditemukan.');

            return self::FAILURE;
        }

        if ($user->hasVerifiedEmail()) {
            $this->info('Email sudah terverifikasi.');

            return self::SUCCESS;
        }

        $user->markEmailAsVerified();
        $this->info("Email {$user->email} ditandai terverifikasi.");

        return self::SUCCESS;
    }
}
