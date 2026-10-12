<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Append-only. Dibuat hanya lewat AuditLogger; tidak ada jalur aplikasi untuk mengubah/menghapus. */
class AuditLog extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['before' => 'array', 'after' => 'array'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new \LogicException('audit_logs is append-only.'));
        static::deleting(fn () => throw new \LogicException('audit_logs is append-only.'));
    }
}
