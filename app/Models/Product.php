<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Product extends Model
{
    use BelongsToTenant, HasFactory, SoftDeletes;

    public const STOCK_IN = 'in_stock';
    public const STOCK_OUT = 'out_of_stock';
    public const STOCK_UNKNOWN = 'unknown';
    public const STOCK_STATUSES = [self::STOCK_IN, self::STOCK_OUT, self::STOCK_UNKNOWN];

    // tenant_id sengaja tidak fillable (diisi BelongsToTenant dari konteks).
    protected $fillable = [
        'sku', 'name', 'description', 'price', 'currency',
        'stock_quantity', 'stock_status', 'is_active', 'metadata',
    ];

    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
            'stock_quantity' => 'decimal:3',
            'is_active' => 'boolean',
            'metadata' => 'array',
        ];
    }
}
