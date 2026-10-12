<?php

namespace App\Livewire\Tenant;

use App\Models\TenantNotification;
use App\Support\Tenancy\CurrentTenant;
use Livewire\Component;

/**
 * Kotak masuk notifikasi workspace (DEC-16). Semua anggota dapat membaca; hanya owner/admin yang menandai dibaca
 * (status baca dibagi per workspace). Tidak di balik gerbang langganan: pemilik harus tetap bisa melihat peringatan
 * "langganan berakhir". Tenant-scoped lewat BelongsToTenant (id milik tenant lain = 404).
 */
class NotificationsInbox extends Component
{
    private const LIMIT = 50;

    public bool $onlyUnread = false;

    public function markRead(int $id): void
    {
        abort_unless($this->canManage(), 403);

        TenantNotification::findOrFail($id)->update(['read_at' => now()]);
    }

    public function markAllRead(): void
    {
        abort_unless($this->canManage(), 403);

        TenantNotification::whereNull('read_at')->update(['read_at' => now()]);
    }

    public function canManage(): bool
    {
        return app(CurrentTenant::class)->userCanWrite(auth()->user());
    }

    public function render()
    {
        $notifications = TenantNotification::query()
            ->when($this->onlyUnread, fn ($q) => $q->whereNull('read_at'))
            ->orderByRaw('read_at is null desc')
            ->orderByDesc('last_occurred_at')->orderByDesc('id')
            ->limit(self::LIMIT)->get();

        return view('livewire.tenant.notifications-inbox', [
            'notifications' => $notifications,
            'unread' => TenantNotification::whereNull('read_at')->count(),
            'canManage' => $this->canManage(),
            'retentionDays' => (int) config('retention.notification_days', 30),
        ])->layout('tenant.layouts.app', ['title' => 'Notifikasi']);
    }
}
