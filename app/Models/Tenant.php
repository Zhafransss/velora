<?php

namespace App\Models;

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
