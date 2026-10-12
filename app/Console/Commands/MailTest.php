<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;

/**
 * Memverifikasi konfigurasi SMTP dengan mengirim satu email uji. Tidak pernah mencetak password.
 */
class MailTest extends Command
{
    protected $signature = 'mail:test {to : Alamat email tujuan}';

    protected $description = 'Kirim email uji untuk memverifikasi konfigurasi SMTP.';

    public function handle(): int
    {
        $to = (string) $this->argument('to');

        if (! filter_var($to, FILTER_VALIDATE_EMAIL)) {
            $this->error('Alamat email tujuan tidak valid.');

            return self::FAILURE;
        }

        $mailer = (string) config('mail.default');
        $cfg = (array) config("mail.mailers.{$mailer}");

        $this->line('Mailer   : ' . $mailer);
        $this->line('Server   : ' . ($cfg['host'] ?? '-') . ':' . ($cfg['port'] ?? '-') . ' (' . ($cfg['scheme'] ?? 'auto') . ')');
        $this->line('Akun     : ' . ($cfg['username'] ?? '-') . ' | password: ' . (empty($cfg['password']) ? 'BELUM DIISI' : 'terisi'));
        $this->line('Pengirim : ' . config('mail.from.name') . ' <' . config('mail.from.address') . '>');

        if ($mailer === 'log' || $mailer === 'array') {
            $this->warn("MAIL_MAILER={$mailer}: email TIDAK dikirim sungguhan. Ubah ke smtp di .env.");
        }

        try {
            Mail::raw(
                "Halo,\n\nIni email uji dari Velora AI. Jika Anda menerimanya, konfigurasi email sudah benar.\n\nSalam,\nTim Velora Digital",
                fn ($m) => $m->to($to)->subject('Tes email — Velora Digital'),
            );
        } catch (\Throwable $e) {
            // Pesan galat dibersihkan dari password bila sempat tercantum.
            $message = $e->getMessage();
            foreach (array_filter([$cfg['password'] ?? null]) as $secret) {
                $message = str_replace((string) $secret, '***', $message);
            }

            $this->error('Gagal mengirim: ' . $e::class);
            $this->line(mb_substr($message, 0, 400));

            return self::FAILURE;
        }

        $this->info("Email uji terkirim ke {$to}. Periksa kotak masuk (dan folder Spam).");

        return self::SUCCESS;
    }
}
