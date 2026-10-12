<?php

namespace App\Services\Admin;

use App\Models\AuditLog;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Model;

/**
 * Mencatat tindakan administratif. Aktor diambil dari pengguna yang login (panel admin); di CLI/queue
 * (tanpa pengguna) dicatat sebagai "cli". Nilai sebelum/sesudah harus berupa data yang aman — jangan memasukkan
 * secret atau isi pesan pelanggan.
 */
class AuditLogger
{
    /** @param array<string, mixed> $before @param array<string, mixed> $after */
    public function record(string $action, ?Model $subject = null, ?Tenant $tenant = null, array $before = [], array $after = [], ?string $note = null): AuditLog
    {
        $actor = auth()->user();

        return AuditLog::create([
            'actor_id' => $actor?->id,
            'actor_label' => $actor?->email ?? (app()->runningInConsole() ? 'cli' : 'system'),
            'action' => $action,
            'tenant_id' => $tenant?->id,
            'tenant_label' => $tenant?->name,
            'subject_type' => $subject ? class_basename($subject) : null,
            'subject_id' => $subject?->getKey(),
            'before' => $before ?: null,
            'after' => $after ?: null,
            'note' => $note !== null ? mb_substr($note, 0, 500) : null,
            'ip_address' => app()->runningInConsole() ? null : request()->ip(),
        ]);
    }
}
