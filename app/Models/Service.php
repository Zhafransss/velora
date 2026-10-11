<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Service extends Model
{
    protected $fillable = [
        'category_id',
        'hero_description',
        'hero_image',
        'is_popular',
        'is_active',
        'sort_order',
    ];

    protected $casts = [
        'is_popular' => 'boolean',
        'is_active'  => 'boolean',
        'sort_order' => 'integer',
    ];

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function packages(): HasMany
    {
        return $this->hasMany(ServicePackage::class);
    }

    public function servicefeatures(): HasMany
    {
        return $this->hasMany(ServiceFeature::class);
    }

    /**
     * Paket termurah (untuk card di /services)
     * Catatan: pakai price asc sesuai pilihan kamu (min price).
     */
    public function cheapestPackage(): HasOne
    {
        return $this->hasOne(ServicePackage::class)
            ->where('is_active', true)
            ->orderBy('price', 'asc');
    }
}