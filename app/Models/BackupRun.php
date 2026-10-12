<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Catatan satu eksekusi backup (global, bukan milik tenant). */
class BackupRun extends Model
{
    public const RUNNING = 'running';
    public const SUCCEEDED = 'succeeded';
    public const FAILED = 'failed';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'manifest' => 'array', 'verify_result' => 'array',
            'started_at' => 'datetime', 'finished_at' => 'datetime', 'verified_at' => 'datetime',
        ];
    }
}
