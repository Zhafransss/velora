<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PortfolioImage extends Model
{
    protected $table = 'portfolio_images';

    protected $fillable = [
        'portfolio_id',
        'image_path',
        'sort_order',
        'is_active',
    ];

    protected $casts = [
        'portfolio_id' => 'integer',
        'sort_order'   => 'integer',
        'is_active'    => 'boolean',
    ];

    public function portfolio(): BelongsTo
    {
        return $this->belongsTo(Portfolio::class);
    }
}