<?php

namespace App\Models\Concerns;

use App\Models\Tenant;
use App\Support\Tenancy\CurrentTenant;
use App\Support\Tenancy\TenancyException;
use App\Support\Tenancy\TenantScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Pasang pada setiap model data bisnis tenant (tabel wajib punya kolom tenant_id).
 *
 * - Semua query otomatis difilter ke tenant aktif (termasuk update/delete/route-model binding).
 * - tenant_id diisi dari konteks saat create; jangan masukkan tenant_id ke $fillable.
 * - tenant_id tidak bisa diubah setelah dibuat.
 */
trait BelongsToTenant
{
    public static function bootBelongsToTenant(): void
    {
        static::addGlobalScope(new TenantScope());

        static::creating(function (Model $model) {
            $contextId = app(CurrentTenant::class)->id();

            if ($model->getAttribute('tenant_id') === null) {
                if ($contextId === null) {
                    throw TenancyException::missingContext($model::class);
                }

                $model->setAttribute('tenant_id', $contextId);

                return;
            }

            // Kode sistem tanpa konteks (seeder/job lintas tenant) boleh menyebut tenant_id eksplisit.
            if ($contextId !== null && (int) $model->getAttribute('tenant_id') !== $contextId) {
                throw TenancyException::crossTenantWrite($model::class);
            }
        });

        static::updating(function (Model $model) {
            if ($model->isDirty('tenant_id')) {
                throw TenancyException::tenantIdImmutable($model::class);
            }
        });
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /** Hanya untuk admin platform / tugas sistem. Jangan dipakai di jalur request tenant. */
    public static function withoutTenancy(): Builder
    {
        return static::withoutGlobalScope(TenantScope::class);
    }
}
