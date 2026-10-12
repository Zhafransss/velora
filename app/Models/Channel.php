<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class Channel extends Model
{
    use BelongsToTenant, HasFactory;

    public const TYPE_WHATSAPP = 'whatsapp';

    public const STATUS_CREATED = 'created';
    public const STATUS_CONNECTED = 'connected';
    public const STATUS_REVOKED = 'revoked';

    /** Status yang boleh dilaporkan konektor (event channel.status). */
    public const CONNECTOR_STATES = ['starting', 'qr', 'qr_expired', 'reconnecting', 'connected', 'failed', 'logged_out', 'stopped'];

    // tenant_id dan public_id sengaja tidak fillable.
    protected $fillable = ['bot_id', 'type', 'name', 'status', 'external_account_id', 'last_status_at', 'settings'];

    protected function casts(): array
    {
        return ['last_status_at' => 'datetime', 'settings' => 'array'];
    }

    protected static function booted(): void
    {
        static::creating(function (Channel $channel) {
            $channel->public_id ??= (string) Str::ulid();

            // Bot harus milik tenant yang sama dengan kanal (mencegah balasan memakai bot tenant lain).
            if ((int) Bot::withoutTenancy()->whereKey($channel->bot_id)->value('tenant_id') !== (int) $channel->tenant_id) {
                throw new \InvalidArgumentException('Channel bot must belong to the same tenant.');
            }
        });
    }

    public function bot(): BelongsTo
    {
        return $this->belongsTo(Bot::class);
    }

    public function conversations(): HasMany
    {
        return $this->hasMany(Conversation::class);
    }

    public function isConnected(): bool
    {
        return $this->status === self::STATUS_CONNECTED;
    }

    /** Dipakai pada jalur webhook, yang belum punya konteks tenant (tenant ditentukan dari channel). */
    public static function findByPublicId(string $publicId): ?self
    {
        return static::withoutTenancy()->where('public_id', $publicId)->first();
    }
}
