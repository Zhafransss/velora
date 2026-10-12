<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class AiCall extends Model
{
    use BelongsToTenant;

    public const UPDATED_AT = null;

    protected $fillable = [
        'purpose', 'provider', 'model', 'status', 'input_tokens', 'output_tokens',
        'latency_ms', 'attempts', 'fallback_used', 'error_type', 'cost_usd',
    ];

    protected function casts(): array
    {
        return ['fallback_used' => 'boolean', 'cost_usd' => 'decimal:6'];
    }
}
