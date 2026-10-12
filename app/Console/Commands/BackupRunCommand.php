<?php

namespace App\Console\Commands;

use App\Services\Backup\BackupService;
use Illuminate\Console\Command;
use Throwable;

class BackupRunCommand extends Command
{
    protected $signature = 'backup:run';

    protected $description = 'Backup database terenkripsi ke disk backup, lalu hapus backup yang melewati masa simpan.';

    public function handle(BackupService $backup): int
    {
        if (config('app.env') === 'production' && config("filesystems.disks." . config('backup.disk') . ".driver") === 'local') {
            $this->warn('PERINGATAN: tujuan backup adalah disk LOKAL di server ini, bukan di luar server (NFR-005). Isi BACKUP_S3_*.');
        }

        try {
            $run = $backup->run();
        } catch (Throwable $e) {
            $this->error('Backup gagal: ' . $e->getMessage());

            return self::FAILURE;
        }

        $this->info(sprintf('Backup selesai: %s (%s KB, disk "%s"). Masa simpan %d hari.', $run->file, number_format($run->size_bytes / 1024, 1), $run->disk, $backup->retentionDays()));

        return self::SUCCESS;
    }
}
