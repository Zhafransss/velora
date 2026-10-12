<?php

namespace App\Livewire\Tenant;

use App\Models\Bot;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Validation\Rule;
use Livewire\Component;

/**
 * FR-006: konfigurasi bot. MVP: satu bot per workspace — dibuat saat pertama kali disimpan.
 * Bot dicari lewat model ber-BelongsToTenant sehingga selalu milik workspace aktif.
 */
class BotSettingsForm extends Component
{
    public string $name = '';
    public string $persona = '';
    public string $tone = 'ramah';
    public string $language = 'id';
    public string $system_instructions = '';
    public string $welcome_message = '';
    public string $fallback_message = '';
    public string $uncertainty_rule = '';
    public string $status = Bot::STATUS_DRAFT;

    public function mount(): void
    {
        $bot = Bot::orderBy('id')->first();

        foreach (Bot::defaults() as $field => $default) {
            $this->{$field} = (string) ($bot?->{$field} ?? $default);
        }
    }

    public function save(): void
    {
        abort_unless($this->canEdit(), 403);

        $data = $this->validate([
            'name' => ['required', 'string', 'max:100'],
            'persona' => ['nullable', 'string', 'max:1000'],
            'tone' => ['required', Rule::in(Bot::TONES)],
            'language' => ['required', Rule::in(array_keys(Bot::LANGUAGES))],
            'system_instructions' => ['nullable', 'string', 'max:4000'],
            'welcome_message' => ['required', 'string', 'max:500'],
            'fallback_message' => ['required', 'string', 'max:500'],
            'uncertainty_rule' => ['required', 'string', 'max:1000'],
            'status' => ['required', Rule::in(Bot::STATUSES)],
        ]);

        foreach (['persona', 'system_instructions'] as $optional) {
            $data[$optional] = $data[$optional] === '' ? null : $data[$optional];
        }

        $bot = Bot::orderBy('id')->first();
        $bot ? $bot->update($data) : Bot::create($data);

        session()->flash('status', 'Pengaturan bot tersimpan.');
    }

    public function canEdit(): bool
    {
        return app(CurrentTenant::class)->userCanWrite(auth()->user());
    }

    public function render()
    {
        return view('livewire.tenant.bot-settings-form', [
            'tones' => Bot::TONES,
            'languages' => Bot::LANGUAGES,
            'canEdit' => $this->canEdit(),
        ])->layout('tenant.layouts.app', ['title' => 'Pengaturan Bot']);
    }
}
