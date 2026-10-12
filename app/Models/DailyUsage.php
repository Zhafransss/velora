<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/** Agregat pemakaian harian. Ditulis hanya oleh QuotaManager (UPDATE atomik), bukan lewat mass assignment. */
class DailyUsage extends Model
{
    use BelongsToTenant;

    protected $table = 'daily_usage';

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return ['usage_date' => 'date:Y-m-d', 'used' => 'integer'];
    }
}
