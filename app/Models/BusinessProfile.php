<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BusinessProfile extends Model
{
    use HasFactory;

    // tenant_id sengaja tidak fillable: selalu di-set lewat relasi tenant, bukan input request.
    protected $fillable = ['business_type', 'description', 'phone', 'address', 'opening_hours', 'settings'];

    protected function casts(): array
    {
        return [
            'opening_hours' => 'array',
            'settings' => 'array',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}
