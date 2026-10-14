<?php

namespace App\Filament\Resources\Accounts\Tables;

use App\Filament\Resources\Accounts\AccountActions;
use App\Models\User;
use App\Notifications\AccountInvitation;
use Filament\Actions\ActionGroup;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class AccountsTable
{
    public const STATUS_LABELS = ['active' => 'Aktif', 'pending' => 'Belum aktivasi', 'disabled' => 'Nonaktif'];

    public static function status(User $user): string
    {
        return match (true) {
            $user->isDisabled() => 'disabled',
            $user->email_verified_at === null => 'pending',
            default => 'active',
        };
    }

    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with('tenants'))
            ->defaultSort('id', 'desc')
            ->columns([
                TextColumn::make('name')->label('Nama')->searchable()->sortable(),
                TextColumn::make('email')->label('Email')->searchable()->copyable(),
                TextColumn::make('workspace')->label('Workspace')
                    ->state(fn (User $u) => $u->tenants->first()?->name)->placeholder('—'),
                TextColumn::make('role')->label('Peran')->badge()
                    ->state(fn (User $u) => $u->tenants->first()?->pivot->role)
                    ->formatStateUsing(fn (?string $state) => AccountInvitation::ROLE_LABELS[$state] ?? $state)
                    ->color(fn (?string $state) => $state === 'viewer' ? 'gray' : 'primary')->placeholder('—'),
                TextColumn::make('status')->label('Status')->badge()
                    ->state(fn (User $u) => self::status($u))
                    ->formatStateUsing(fn (string $state) => self::STATUS_LABELS[$state])
                    ->color(fn (string $state) => ['active' => 'success', 'pending' => 'warning', 'disabled' => 'danger'][$state]),
                TextColumn::make('created_at')->label('Dibuat')->dateTime('d M Y H:i')->sortable()->toggleable(),
            ])
            ->filters([
                SelectFilter::make('workspace')->label('Workspace')->relationship('tenants', 'name')->searchable()->preload(),
                SelectFilter::make('role')->label('Peran')->options(AccountInvitation::ROLE_LABELS)
                    // Filament menyuntikkan argumen closure berdasarkan NAMA: harus $query, bukan $q.
                    ->query(fn (Builder $query, array $data) => filled($data['value'])
                        ? $query->whereHas('tenants', fn (Builder $t) => $t->where('tenant_members.role', $data['value']))
                        : $query),
                SelectFilter::make('status')->label('Status')->options(self::STATUS_LABELS)
                    ->query(fn (Builder $query, array $data) => match ($data['value'] ?? null) {
                        'disabled' => $query->whereNotNull('disabled_at'),
                        'pending' => $query->whereNull('disabled_at')->whereNull('email_verified_at'),
                        'active' => $query->whereNull('disabled_at')->whereNotNull('email_verified_at'),
                        default => $query,
                    }),
            ])
            ->recordActions([
                EditAction::make(),
                ActionGroup::make([
                    AccountActions::invite(),
                    AccountActions::disable(),
                    AccountActions::enable(),
                    AccountActions::delete(),
                ]),
            ]);
    }
}
