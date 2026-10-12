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
        ])->layout('tenant.layouts.app', ['title' => 'Dashboard']);
    }
}
