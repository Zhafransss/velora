<?php

namespace Tests\Fixtures;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/** Model contoh untuk menguji trait BelongsToTenant (tabel dibuat di dalam test). */
class TenantNote extends Model
{
    use BelongsToTenant;

    protected $table = 'tenant_notes';

    // tenant_id sengaja tidak di-$fillable, sama seperti model bisnis sungguhan.
    protected $fillable = ['body'];
}
