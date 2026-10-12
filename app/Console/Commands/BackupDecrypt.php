<?php

namespace App\Console\Commands;

use App\Services\Backup\BackupCipher;
use Illuminate\Console\Command;
use Throwable;

/** Pemulihan bencana: ubah berkas .vbk yang diunduh menjadi SQL biasa, lalu impor dengan `mysql < berkas.sql`. */
class BackupDecrypt extends Command
{
    protected $signature = 'backup:decrypt {file : Berkas .vbk} {output : Berkas .sql tujuan (tidak boleh sudah ada)}';

    protected $description = 'Dekripsi + gunzip sebuah backup menjadi berkas SQL (untuk pemulihan di server baru).';

    public function handle(): int
    {
        $output = $this->argument('output');

        if (file_exists($output)) {
            $this->error('Berkas tujuan sudah ada; tidak ditimpa.');

            return self::FAILURE;
        }

        try {
            $key = BackupCipher::parseKey(config('backup.encryption_key'));
            $handle = fopen($output, 'xb');
            BackupCipher::decrypt($this->argument('file'), $key, fn (string $chunk) => fwrite($handle, $chunk));
            fclose($handle);
        } catch (Throwable $e) {
            isset($handle) && is_resource($handle) && fclose($handle);
            @unlink($output); // jangan tinggalkan SQL parsial yang tampak valid
            $this->error('Gagal: ' . $e->getMessage());

            return self::FAILURE;
        }

        $this->info("Selesai: {$output}. Impor dengan: mysql -u <user> -p <database> < {$output}");
        $this->warn('Berkas SQL berisi data TANPA enkripsi. Hapus setelah impor.');

        return self::SUCCESS;
    }
}
