<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class TenantNotification extends Model
{
    use BelongsToTenant;

    public const UPDATED_AT = null;

    public const INFO = 'info';
    public const WARNING = 'warning';
    public const CRITICAL = 'critical';

    protected $fillable = ['type', 'severity', 'title', 'body', 'action_route', 'dedupe_key', 'occurrences', 'last_occurred_at', 'read_at'];

    protected function casts(): array
    {
        return ['occurrences' => 'integer', 'last_occurred_at' => 'datetime', 'read_at' => 'datetime'];
    }

    public function isRead(): bool
    {
        return $this->read_at !== null;
    }
}
