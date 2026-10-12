<?php

namespace App\Services\Admin;

use App\Models\Tenant;

/**
 * Menangguhkan / mengaktifkan kembali workspace. Workspace yang ditangguhkan kehilangan akses ke fitur
 * (SetCurrentTenant/hasActiveSubscription) dan pesan WhatsApp-nya dilewati tanpa AI atau kuota.
 */
class TenantStatusService
{
    public function __construct(private AuditLogger $audit)
    {
    }

    public function suspend(Tenant $tenant, ?string $note = null): Tenant
    {
        return $this->change($tenant, Tenant::STATUS_SUSPENDED, 'tenant.suspended', $note);
    }

    public function reactivate(Tenant $tenant, ?string $note = null): Tenant
    {
        return $this->change($tenant, Tenant::STATUS_ACTIVE, 'tenant.reactivated', $note);
    }

    private function change(Tenant $tenant, string $status, string $action, ?string $note): Tenant
    {
        $before = ['status' => $tenant->status];

        if ($tenant->status !== $status) {
            $tenant->update(['status' => $status]);
        }

        $this->audit->record($action, $tenant, $tenant, $before, ['status' => $tenant->status], $note);

        return $tenant;
    }
}
