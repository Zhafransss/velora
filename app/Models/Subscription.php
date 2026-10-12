<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Sengaja tidak memakai BelongsToTenant: langganan dikelola platform (admin/command), bukan ditulis tenant.
 * Tenant hanya membacanya lewat Tenant::currentSubscription().
 */
class Subscription extends Model
{
    use HasFactory;

    public const STATUS_TRIALING = 'trialing';
    public const STATUS_ACTIVE = 'active';
    public const STATUS_PAST_DUE = 'past_due';
    public const STATUS_SUSPENDED = 'suspended';
    public const STATUS_CANCELED = 'canceled';
    public const STATUS_EXPIRED = 'expired';

    /** Hanya status ini yang boleh memakai fitur. past_due/suspended/canceled/expired = terkunci. */
    public const ENTITLED_STATUSES = [self::STATUS_TRIALING, self::STATUS_ACTIVE];

    protected $fillable = ['tenant_id', 'plan_code', 'price_idr', 'status', 'current_period_start', 'current_period_end', 'notes'];

    protected function casts(): array
    {
        return [
            'current_period_start' => 'datetime',
            'current_period_end' => 'datetime',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /** Status layak DAN periode belum lewat (periode kosong = tanpa batas, mis. diatur manual). */
    public function isEntitled(): bool
    {
        if (! in_array($this->status, self::ENTITLED_STATUSES, true)) {
            return false;
        }

        return $this->current_period_end === null || $this->current_period_end->isFuture();
    }
}
