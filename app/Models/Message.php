<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Message extends Model
{
    use BelongsToTenant, HasFactory;

    public const IN = 'inbound';
    public const OUT = 'outbound';

    // inbound
    public const RECEIVED = 'received';
    public const PROCESSING = 'processing';
    public const ANSWERED = 'answered';
    public const FAILED = 'failed';
    public const QUOTA_EXCEEDED = 'quota_exceeded';
    public const SKIPPED = 'skipped';

    // outbound (FAILED dipakai bersama)
    public const PENDING = 'pending';
    public const SENDING = 'sending'; // dipegang satu pengirim; yang menggantung TIDAK dikirim ulang (hasil ambigu)
    public const SENT = 'sent';

    public const SOURCE_AI = 'ai';
    public const SOURCE_FALLBACK = 'fallback';

    protected $fillable = [
        'conversation_id', 'direction', 'external_message_id', 'body', 'status', 'source', 'error_type',
        'in_reply_to_id', 'provider', 'model', 'input_tokens', 'output_tokens', 'send_attempts', 'process_attempts', 'is_test', 'sent_at',
    ];

    protected function casts(): array
    {
        return ['is_test' => 'boolean', 'sent_at' => 'datetime'];
    }

    /**
     * Kunci idempotensi kuota untuk memproses pesan masuk ini. Percobaan pertama memakai kunci dasar (kompatibel
     * dengan data lama); tiap proses ulang manual memakai kunci baru karena kunci sebelumnya sudah dikembalikan.
     */
    public function quotaKey(string $channelPublicId): string
    {
        $key = "msg:{$channelPublicId}:{$this->external_message_id}";

        return $this->process_attempts > 0 ? "{$key}:r{$this->process_attempts}" : $key;
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    public function inReplyTo(): BelongsTo
    {
        return $this->belongsTo(self::class, 'in_reply_to_id');
    }
}
