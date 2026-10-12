<?php

namespace App\Console\Commands;

use App\Models\BackupRun;
use Illuminate\Console\Command;

/** Untuk pemantauan eksternal (cron + email, uptime monitor): exit code != 0 bila backup tidak sehat. */
class BackupStatus extends Command
{
    protected $signature = 'backup:status';

    protected $description = 'Periksa kesehatan backup: sukses terakhir cukup baru dan restore teruji. Gagal (exit 1) bila tidak.';

    public function handle(): int
    {
        $problems = [];
        $last = BackupRun::where('status', BackupRun::SUCCEEDED)->latest('finished_at')->first();

        if ($last === null) {
            $problems[] = 'Belum pernah ada backup yang sukses.';
        } elseif ($last->finished_at->lt(now()->subHours((int) config('backup.max_age_hours')))) {
            $problems[] = "Backup sukses terakhir {$last->finished_at->diffForHumans()} (batas " . config('backup.max_age_hours') . ' jam).';
        }

        $verified = BackupRun::whereNotNull('verified_at')->where('verify_result->ok', true)->latest('verified_at')->first();

        if ($verified === null) {
            $problems[] = 'Restore belum pernah teruji.';
        } elseif ($verified->verified_at->lt(now()->subDays((int) config('backup.max_verify_age_days')))) {
            $problems[] = "Uji restore terakhir {$verified->verified_at->diffForHumans()} (batas " . config('backup.max_verify_age_days') . ' hari).';
        }

        $this->table(['', 'Waktu', 'Berkas'], [
            ['Backup sukses terakhir', $last?->finished_at?->toDateTimeString() ?? '-', $last?->file ?? '-'],
            ['Restore teruji terakhir', $verified?->verified_at?->toDateTimeString() ?? '-', $verified?->file ?? '-'],
        ]);

        foreach ($problems as $problem) {
            $this->error($problem);
        }

        $problems === [] && $this->info('Backup sehat.');

        return $problems === [] ? self::SUCCESS : self::FAILURE;
    }
}
