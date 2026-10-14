<?php

namespace App\Filament\Resources\Tenants\RelationManagers;

use App\Filament\Resources\Accounts\AccountResource;
use App\Filament\Resources\Accounts\Tables\AccountsTable;
use App\Models\Tenant;
use App\Models\TenantMember;
use App\Notifications\AccountInvitation;
use Filament\Actions\Action;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Anggota workspace di halaman detail Workspace. Hanya daftar + tautan; perubahan dilakukan di menu Akun
 * (satu tempat untuk aturan owner terakhir dan audit, lihat WorkspaceAccountService).
 */
class MembersRelationManager extends RelationManager
{
    protected static string $relationship = 'members';

    protected static ?string $title = 'Akun & anggota';

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with('user'))
            ->defaultSort('id')
            ->paginated(false)
            ->columns([
                TextColumn::make('user.name')->label('Nama'),
                TextColumn::make('user.email')->label('Email')->copyable(),
                TextColumn::make('role')->label('Peran')->badge()
                    ->formatStateUsing(fn (string $state) => AccountInvitation::ROLE_LABELS[$state] ?? $state)
                    ->color(fn (string $state) => $state === TenantMember::ROLE_VIEWER ? 'gray' : 'primary'),
                TextColumn::make('status')->label('Status')->badge()
                    ->state(fn (TenantMember $m) => AccountsTable::status($m->user))
                    ->formatStateUsing(fn (string $state) => AccountsTable::STATUS_LABELS[$state])
                    ->color(fn (string $state) => ['active' => 'success', 'pending' => 'warning', 'disabled' => 'danger'][$state]),
            ])
            ->headerActions([
                Action::make('addAccount')->label('Tambah akun')->icon('heroicon-o-user-plus')
                    ->url(fn () => AccountResource::getUrl('create', ['tenant' => $this->getOwnerRecord()->getKey()]))
                    ->disabled(fn () => $this->getOwnerRecord()->status !== Tenant::STATUS_ACTIVE)
                    ->tooltip(fn () => $this->getOwnerRecord()->status !== Tenant::STATUS_ACTIVE
                        ? 'Workspace ditangguhkan: aktifkan kembali sebelum menambah akun.' : null),
            ])
            ->recordActions([
                Action::make('manage')->label('Kelola')->icon('heroicon-o-pencil-square')
                    ->url(fn (TenantMember $m) => AccountResource::getUrl('edit', ['record' => $m->user_id])),
            ]);
    }
}
