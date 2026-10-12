<?php

namespace App\Filament\Resources\Tenants\Tables;

use App\Models\Subscription;
use App\Models\Tenant;
use App\Support\Admin\TenantInsights;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class TenantsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['members.user', 'currentSubscription']))
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('name')->label('Workspace')->searchable()->sortable()
                    ->description(fn (Tenant $t) => $t->slug),

                TextColumn::make('owner')->label('Pemilik')
                    ->state(fn (Tenant $t) => TenantInsights::owner($t)?->email ?? '—')
                    ->searchable(query: fn (Builder $query, string $search) => $query->whereHas('users', fn (Builder $u) => $u->where('email', 'like', "%{$search}%"))),

                TextColumn::make('status')->label('Status')->badge()
                    ->color(fn (string $state) => $state === Tenant::STATUS_ACTIVE ? 'success' : 'danger')
                    ->sortable(),

                TextColumn::make('subscription')->label('Langganan')->badge()
                    ->state(fn (Tenant $t) => TenantInsights::subscriptionLabel(TenantInsights::currentSubscription($t)))
                    ->color(function (Tenant $t) {
                        $s = TenantInsights::currentSubscription($t);

                        return $s?->isEntitled() ? 'success' : ($s ? 'danger' : 'gray');
                    }),

                TextColumn::make('period_end')->label('Berakhir')
                    ->state(fn (Tenant $t) => TenantInsights::currentSubscription($t)?->current_period_end)
                    ->date('d M Y')->placeholder('—'),

                TextColumn::make('usage')->label('AI hari ini')
                    ->state(function (Tenant $t) {
                        $limit = TenantInsights::dailyLimit($t);

                        return TenantInsights::usageToday($t) . ' / ' . ($limit === null ? '∞' : $limit);
                    }),

                TextColumn::make('channel')->label('WhatsApp')->badge()
                    ->state(fn (Tenant $t) => TenantInsights::channel($t)?->status ?? 'belum ada')
                    ->color(fn (string $state) => match ($state) {
                        'connected' => 'success',
                        'belum ada', 'created' => 'gray',
                        'qr', 'starting', 'reconnecting' => 'warning',
                        default => 'danger',
                    }),

                TextColumn::make('created_at')->label('Terdaftar')->dateTime('d M Y')->sortable()->toggleable(),
            ])
            ->filters([
                SelectFilter::make('status')->label('Status workspace')->options([
                    Tenant::STATUS_ACTIVE => 'Aktif',
                    Tenant::STATUS_SUSPENDED => 'Ditangguhkan',
                ]),

                SelectFilter::make('subscription_state')->label('Langganan')->options([
                    'entitled' => 'Berlangganan aktif',
                    'inactive' => 'Langganan tidak aktif / habis',
                    'none' => 'Belum pernah berlangganan',
                ])->query(function (Builder $query, array $data) {
                    $entitled = fn (Builder $q) => $q->whereIn('status', Subscription::ENTITLED_STATUSES)
                        ->where(fn (Builder $p) => $p->whereNull('current_period_end')->orWhere('current_period_end', '>', now()));

                    return match ($data['value'] ?? null) {
                        'entitled' => $query->whereHas('subscriptions', $entitled),
                        'inactive' => $query->whereHas('subscriptions')->whereDoesntHave('subscriptions', $entitled),
                        'none' => $query->whereDoesntHave('subscriptions'),
                        default => $query,
                    };
                }),
            ])
            ->recordActions([ViewAction::make()->label('Detail')]);
    }
}
