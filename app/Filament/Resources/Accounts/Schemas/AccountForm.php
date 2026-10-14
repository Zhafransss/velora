<?php

namespace App\Filament\Resources\Accounts\Schemas;

use App\Models\Tenant;
use App\Models\TenantMember;
use App\Models\User;
use App\Notifications\AccountInvitation;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class AccountForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Akun')->columns(2)->components([
                TextInput::make('name')->label('Nama')->required()->maxLength(255),
                TextInput::make('email')->label('Email')->email()->required()->maxLength(255)
                    ->unique(User::class, 'email', ignoreRecord: true)
                    ->helperText(fn (string $operation) => $operation === 'create'
                        ? 'Undangan untuk membuat password dikirim ke email ini.'
                        : 'Bila diubah, pemilik akun harus memverifikasi email baru saat login berikutnya.'),
            ]),

            Section::make('Workspace')->columns(2)->components([
                // MVP satu workspace per akun (FR-002): ditentukan saat dibuat, tidak dipindah.
                // Buat akun: hanya workspace aktif (yang ditangguhkan ditolak juga oleh WorkspaceAccountService).
                // Edit: semua, karena field hanya menampilkan workspace akun ini (bisa saja sedang ditangguhkan).
                Select::make('tenant_id')->label('Workspace')
                    ->options(fn (string $operation) => self::workspaceOptions(activeOnly: $operation === 'create'))
                    ->searchable()->required()
                    ->default(fn () => request()->integer('tenant') ?: null)
                    ->disabled(fn (string $operation) => $operation === 'edit')
                    ->dehydrated(fn (string $operation) => $operation === 'create'),
                Select::make('role')->label('Peran')->options(AccountInvitation::ROLE_LABELS)->required()
                    ->helperText('Owner/admin dapat mengubah data workspace; viewer hanya dapat melihat.'),
            ]),
        ]);
    }

    /**
     * Label "nama — email owner": nama workspace tidak unik (mis. dua workspace "psp"), email owner yang membedakan.
     *
     * @return array<int, string>
     */
    public static function workspaceOptions(bool $activeOnly): array
    {
        return Tenant::query()
            ->when($activeOnly, fn ($query) => $query->where('status', Tenant::STATUS_ACTIVE))
            ->with(['members' => fn ($query) => $query->where('role', TenantMember::ROLE_OWNER)->orderBy('id')->with('user:id,email')])
            ->orderBy('name')
            ->get()
            ->mapWithKeys(fn (Tenant $t) => [$t->id => $t->name
                . ' — ' . ($t->members->first()?->user?->email ?? $t->slug)
                . ($t->status === Tenant::STATUS_ACTIVE ? '' : ' (ditangguhkan)')])
            ->all();
    }
}
