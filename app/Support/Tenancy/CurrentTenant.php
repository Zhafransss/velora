<?php

namespace App\Support\Tenancy;

use App\Models\Tenant;
use App\Models\TenantMember;
use App\Models\User;

/**
 * Konteks tenant aktif untuk request/job saat ini.
 *
 * Sumbernya SELALU hasil otorisasi (keanggotaan user), tidak pernah input request (FR-003).
 * Didaftarkan sebagai scoped singleton, sehingga bersih di setiap request/job.
 */
class CurrentTenant
{
    private ?Tenant $tenant = null;

    public function set(?Tenant $tenant): void
    {
        $this->tenant = $tenant;
    }

    public function get(): ?Tenant
    {
        return $this->tenant;
    }

    public function id(): ?int
    {
        return $this->tenant?->getKey();
    }

    public function clear(): void
    {
        $this->tenant = null;
    }

    /**
     * MVP: satu workspace aktif per pengguna (FR-002) — pakai keanggotaan paling awal.
     * Tenant yang di-suspend tidak dijadikan konteks.
     */
    public function resolveFor(User $user): ?Tenant
    {
        return $user->tenants()
            ->where('tenants.status', Tenant::STATUS_ACTIVE)
            ->orderBy('tenant_members.id')
            ->first();
    }

    /** Hanya owner/admin workspace aktif yang boleh mengubah data; viewer hanya membaca. */
    public function userCanWrite(?User $user): bool
    {
        if ($user === null || $this->tenant === null) {
            return false;
        }

        return TenantMember::where('tenant_id', $this->tenant->id)
            ->where('user_id', $user->id)
            ->whereIn('role', [TenantMember::ROLE_OWNER, TenantMember::ROLE_ADMIN])
            ->exists();
    }

    /** Jalankan blok kode sebagai tenant tertentu (job, command, seeder), lalu kembalikan konteks semula. */
    public function runAs(Tenant $tenant, callable $callback): mixed
    {
        $previous = $this->tenant;
        $this->tenant = $tenant;

        try {
            return $callback();
        } finally {
            $this->tenant = $previous;
        }
    }
}
