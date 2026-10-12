<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class Bot extends Model
{
    use BelongsToTenant, HasFactory;

    public const STATUS_DRAFT = 'draft';
    public const STATUS_ACTIVE = 'active';
    public const STATUS_PAUSED = 'paused';
    public const STATUSES = [self::STATUS_DRAFT, self::STATUS_ACTIVE, self::STATUS_PAUSED];

    public const TONES = ['ramah', 'formal', 'santai', 'profesional'];
    public const LANGUAGES = ['id' => 'Bahasa Indonesia', 'en' => 'English'];

    // tenant_id dan public_id sengaja tidak fillable.
    protected $fillable = [
        'name', 'persona', 'tone', 'language', 'system_instructions',
        'welcome_message', 'fallback_message', 'uncertainty_rule', 'status', 'settings',
    ];

    protected function casts(): array
    {
        return ['settings' => 'array'];
    }

    protected static function booted(): void
    {
        static::creating(function (Bot $bot) {
            $bot->public_id ??= (string) Str::ulid();
        });
    }

    /** Nilai awal untuk bot baru. Aturan ketidakpastian mencerminkan aturan akurasi FR-007. */
    public static function defaults(): array
    {
        return [
            'name' => 'Asisten Virtual',
            'persona' => '',
            'tone' => 'ramah',
            'language' => 'id',
            'system_instructions' => '',
            'welcome_message' => 'Halo! Ada yang bisa kami bantu?',
            'fallback_message' => 'Maaf, saya belum memiliki informasi tentang hal itu. Silakan hubungi kami langsung untuk bantuan lebih lanjut.',
            'uncertainty_rule' => 'Jika informasi (harga, stok, produk, layanan, kebijakan) tidak ada di data bisnis, katakan dengan jujur bahwa Anda tidak tahu dan tawarkan langkah berikutnya. Jangan pernah mengarang.',
            'status' => self::STATUS_DRAFT,
        ];
    }
}
