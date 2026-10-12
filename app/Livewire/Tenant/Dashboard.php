<?php

namespace App\Livewire\Tenant;

use App\Support\Tenancy\CurrentTenant;
use Livewire\Component;

class Dashboard extends Component
{
    public function render()
    {
        $tenant = app(CurrentTenant::class)->get();

        return view('livewire.tenant.dashboard', [
            'tenant' => $tenant,
            'subscription' => $tenant?->currentSubscription,
            'isSubscribed' => $tenant?->hasActiveSubscription() ?? false,
            // Peringatan belum dibaca yang paling penting, ditampilkan di atas dashboard (DEC-16).
            'alerts' => \App\Models\TenantNotification::whereNull('read_at')
                ->whereIn('severity', ['critical', 'warning'])
                ->orderByRaw("case severity when 'critical' then 0 else 1 end")->orderByDesc('last_occurred_at')->limit(3)->get(),
        ])->layout('tenant.layouts.app', ['title' => 'Dashboard']);
    }
}
