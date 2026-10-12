<?php

namespace App\Console\Commands;

use App\Services\Backup\BackupService;
use Illuminate\Console\Command;
use Throwable;

class BackupVerify extends Command
{
    protected $signature = 'backup:verify {file? : Nama berkas (bawaan: backup terbaru)}';

    protected $description = 'Uji restore: impor backup ke database sementara dan bandingkan jumlah baris. Database sementara dihapus.';

    public function handle(BackupService $backup): int
    {
        try {
            $result = $backup->verify($this->argument('file'));
        } catch (Throwable $e) {
            $this->error('Uji restore gagal: ' . $e->getMessage());

            return self::FAILURE;
        }

        foreach ($result['problems'] as $problem) {
            $this->error($problem);
        }

        if (! $result['ok']) {
            $this->error('Restore TIDAK sesuai backup.');

            return self::FAILURE;
        }

        $this->info("Restore berhasil: {$result['tables']} tabel cocok dengan manifest.");

        return self::SUCCESS;
    }
}
