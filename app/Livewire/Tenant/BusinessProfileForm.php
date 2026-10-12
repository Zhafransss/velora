<?php

namespace App\Livewire\Tenant;

use App\Support\Tenancy\CurrentTenant;
use Illuminate\Validation\Rule;
use Livewire\Component;

/** FR-002: profil bisnis workspace. Hanya owner/admin yang boleh mengubah. */
class BusinessProfileForm extends Component
{
    public const TIMEZONES = ['Asia/Jakarta', 'Asia/Makassar', 'Asia/Jayapura'];

    public string $workspace_name = '';
    public string $timezone = 'Asia/Jakarta';
    public string $business_type = '';
    public string $description = '';
    public string $phone = '';
    public string $address = '';
    public string $opening_hours = '';

    public function mount(): void
    {
        $tenant = app(CurrentTenant::class)->get();
        $profile = $tenant->businessProfile;

        $this->workspace_name = $tenant->name;
        $this->timezone = $tenant->timezone;
        $this->business_type = $profile->business_type ?? '';
        $this->description = $profile->description ?? '';
        $this->phone = $profile->phone ?? '';
        $this->address = $profile->address ?? '';
        $this->opening_hours = $profile->opening_hours['text'] ?? '';
    }

    public function save(): void
    {
        $tenant = app(CurrentTenant::class)->get();

        // Tenant selalu dari konteks (keanggotaan), bukan dari state komponen yang bisa dimanipulasi klien.
        abort_unless($this->canEdit(), 403);

        $data = $this->validate([
            'workspace_name' => ['required', 'string', 'max:150'],
            'timezone' => ['required', Rule::in(self::TIMEZONES)],
            'business_type' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'phone' => ['nullable', 'string', 'max:30'],
            'address' => ['nullable', 'string', 'max:1000'],
            'opening_hours' => ['nullable', 'string', 'max:500'],
        ]);

        $tenant->update(['name' => $data['workspace_name'], 'timezone' => $data['timezone']]);

        $tenant->businessProfile()->updateOrCreate([], [
            'business_type' => $data['business_type'],
            'description' => $data['description'] ?: null,
            'phone' => $data['phone'] ?: null,
            'address' => $data['address'] ?: null,
            'opening_hours' => $data['opening_hours'] ? ['text' => $data['opening_hours']] : null,
        ]);

        session()->flash('status', 'Profil bisnis tersimpan.');
    }

    public function canEdit(): bool
    {
        return app(CurrentTenant::class)->userCanWrite(auth()->user());
    }

    public function render()
    {
        return view('livewire.tenant.business-profile-form', [
            'timezones' => self::TIMEZONES,
            'canEdit' => $this->canEdit(),
        ])->layout('tenant.layouts.app', ['title' => 'Profil Bisnis']);
    }
}
