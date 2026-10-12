<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/** Layanan milik tenant (bukan `Service` milik website). */
class CatalogService extends Model
{
    use BelongsToTenant, HasFactory, SoftDeletes;

    protected $table = 'catalog_services';

    protected $fillable = [
        'name', 'description', 'price_from', 'price_to', 'currency',
        'duration_minutes', 'is_active', 'metadata',
    ];

    protected function casts(): array
    {
        return [
            'price_from' => 'decimal:2',
            'price_to' => 'decimal:2',
            'duration_minutes' => 'integer',
            'is_active' => 'boolean',
            'metadata' => 'array',
        ];
    }
}
