<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Portfolio extends Model
{
    protected $fillable = [
        'category_id',
        'title',
        'slug',
        'cover_image',
        'is_active',
    ];

    protected $casts = [
        'category_id' => 'integer',
        'is_active'   => 'boolean',
    ];

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function images(): HasMany
    {
        return $this->hasMany(PortfolioImage::class)->orderBy('sort_order');
    }

     public function activeImages(): HasMany
    {
        return $this->hasMany(PortfolioImage::class)
            ->where('is_active', true)
            ->orderBy('sort_order');
    }

    /**
     * Kalau kamu mau cover diambil dari table portfolio_images (is_cover=1),
     * bisa pakai relasi ini (opsional).
     */
    public function coverImage(): HasOne
    {
        return $this->hasOne(PortfolioImage::class)
            ->where('is_cover', true);
    }
}