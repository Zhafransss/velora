<?php

namespace App\Console\Commands;

use App\Services\Backup\BackupCipher;
use Illuminate\Console\Command;

class BackupKey extends Command
{
    protected $signature = 'backup:key';

    protected $description = 'Buat kunci enkripsi backup baru (BACKUP_ENCRYPTION_KEY). Tidak menyimpan apa pun.';

    public function handle(): int
    {
        $this->line('BACKUP_ENCRYPTION_KEY=' . BackupCipher::generateKey());
        $this->newLine();
        $this->warn('Salin ke .env dan CADANGKAN di password manager, terpisah dari backup. Kunci hilang = semua backup tidak bisa dibuka.');

        return self::SUCCESS;
    }
}
