<?php

namespace App\Models;

use App\Support\Tenancy\TenantScope;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Tenant extends Model
{
    use HasFactory;

    public const STATUS_ACTIVE = 'active';
    public const STATUS_SUSPENDED = 'suspended';

    protected $fillable = ['name', 'slug', 'status', 'timezone'];

    public function members(): HasMany
    {
        return $this->hasMany(TenantMember::class);
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'tenant_members')->withPivot('role')->withTimestamps();
    }

    public function businessProfile(): HasOne
    {
        return $this->hasOne(BusinessProfile::class);
    }

    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class);
    }

    public function currentSubscription(): HasOne
    {
        return $this->hasOne(Subscription::class)->latestOfMany();
    }

    /** Langganan berstatus layak (trialing/active), terbaru dulu. Masa berlaku diperiksa di PHP (Subscription::isEntitled). */
    public function entitledSubscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class)->whereIn('status', Subscription::ENTITLED_STATUSES)->latest('id');
    }

    /**
     * Pemakaian AI beberapa hari terakhir, untuk eager loading di panel admin (di luar konteks tenant). Scope tenant
     * sengaja dilepas karena relasi ini sudah dibatasi ke tenant_id model ini; rentang 2 hari menutup perbedaan zona waktu.
     */
    public function recentDailyUsage(): HasMany
    {
        return $this->hasMany(DailyUsage::class)->withoutGlobalScope(TenantScope::class)
            ->where('usage_date', '>=', now()->subDays(2)->toDateString());
    }

    /** Gerbang akses fitur: tenant harus aktif DAN punya langganan yang layak. */
    public function hasActiveSubscription(): bool
    {
        if ($this->status !== self::STATUS_ACTIVE) {
            return false;
        }

        return $this->subscriptions()
            ->whereIn('status', Subscription::ENTITLED_STATUSES)
            ->get()
            ->contains(fn (Subscription $s) => $s->isEntitled());
    }
}
