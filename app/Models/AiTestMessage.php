<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class AiTestMessage extends Model
{
    use BelongsToTenant;

    public const UPDATED_AT = null;

    protected $fillable = [
        'user_id', 'bot_id', 'question', 'answer', 'status', 'error_type', 'provider', 'model',
        'input_tokens', 'output_tokens', 'latency_ms', 'context_truncated',
    ];

    protected function casts(): array
    {
        return ['context_truncated' => 'boolean'];
    }
}
