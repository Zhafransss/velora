<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/** Ledger append-only: tidak ada update/delete pada jalur aplikasi; koreksi lewat event baru. */
class UsageEvent extends Model
{
    use BelongsToTenant;

    public const UPDATED_AT = null;

    protected $fillable = ['usage_date', 'type', 'units', 'idempotency_key', 'purpose', 'message_id', 'note'];

    protected function casts(): array
    {
        return ['usage_date' => 'date:Y-m-d'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new \LogicException('usage_events is append-only.'));
        static::deleting(fn () => throw new \LogicException('usage_events is append-only.'));
    }
}
