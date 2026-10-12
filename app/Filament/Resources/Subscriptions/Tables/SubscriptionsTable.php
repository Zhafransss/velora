<?php

namespace App\Filament\Resources\Subscriptions\Tables;

use App\Models\Subscription;
use App\Services\Admin\SubscriptionService;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class SubscriptionsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with('tenant'))
            ->defaultSort('id', 'desc')
            ->columns([
                TextColumn::make('tenant.name')->label('Workspace')->searchable()->sortable(),
                TextColumn::make('plan_code')->label('Paket')->badge()->formatStateUsing(fn (string $state) => strtoupper($state)),
                TextColumn::make('status')->label('Status')->badge()
                    ->color(fn (Subscription $s) => $s->isEntitled() ? 'success' : ($s->status === Subscription::STATUS_ACTIVE ? 'danger' : 'warning'))
                    ->formatStateUsing(fn (Subscription $s) => $s->isEntitled() ? $s->status : ($s->status === Subscription::STATUS_ACTIVE ? 'active (periode habis)' : $s->status)),
                TextColumn::make('current_period_start')->label('Mulai')->date('d M Y')->toggleable(),
                TextColumn::make('current_period_end')->label('Berakhir')->date('d M Y')->sortable()
                    ->color(fn (Subscription $s) => $s->current_period_end?->isPast() ? 'danger' : null),
                TextColumn::make('notes')->label('Catatan')->limit(40)->tooltip(fn (Subscription $s) => $s->notes)->placeholder('—')->toggleable(),
                TextColumn::make('created_at')->label('Dibuat')->dateTime('d M Y H:i')->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('status')->options(collect([
                    Subscription::STATUS_TRIALING, Subscription::STATUS_ACTIVE, Subscription::STATUS_PAST_DUE,
                    Subscription::STATUS_SUSPENDED, Subscription::STATUS_CANCELED, Subscription::STATUS_EXPIRED,
                ])->mapWithKeys(fn ($s) => [$s => $s])->all()),
                SelectFilter::make('plan_code')->label('Paket')->options(collect(SubscriptionService::planCodes())->mapWithKeys(fn ($c) => [$c => strtoupper($c)])->all()),
            ])
            ->recordActions([
                Action::make('extend')
                    ->label('Perpanjang')->icon('heroicon-o-arrow-path')->color('success')
                    ->modalDescription('Dicatat setelah pembayaran perpanjangan diterima. Bila masih berlaku, hari ditambahkan ke tanggal berakhir.')
                    ->schema([
                        TextInput::make('days')->label('Tambah (hari)')->numeric()->minValue(1)->maxValue(SubscriptionService::MAX_DAYS)->default(30)->required(),
                        Textarea::make('notes')->label('Catatan pembayaran')->rows(2)->maxLength(500),
                    ])
                    ->action(function (array $data, Subscription $record, SubscriptionService $service) {
                        $s = $service->extend($record, (int) $data['days'], $data['notes'] ?? null);
                        Notification::make()->success()->title('Langganan diperpanjang')->body('Berakhir ' . $s->current_period_end->format('d M Y'))->send();
                    }),

                Action::make('suspend')
                    ->label('Tangguhkan')->icon('heroicon-o-pause-circle')->color('warning')
                    ->visible(fn (Subscription $s) => in_array($s->status, Subscription::ENTITLED_STATUSES, true))
                    ->requiresConfirmation()
                    ->modalDescription('Workspace langsung kehilangan akses fitur sampai langganan diperpanjang/diaktifkan lagi.')
                    ->schema([Textarea::make('notes')->label('Alasan')->required()->rows(2)->maxLength(500)])
                    ->action(function (array $data, Subscription $record, SubscriptionService $service) {
                        $service->setStatus($record, Subscription::STATUS_SUSPENDED, $data['notes']);
                        Notification::make()->warning()->title('Langganan ditangguhkan')->send();
                    }),

                Action::make('cancel')
                    ->label('Batalkan')->icon('heroicon-o-x-circle')->color('danger')
                    ->visible(fn (Subscription $s) => ! in_array($s->status, [Subscription::STATUS_CANCELED, Subscription::STATUS_EXPIRED], true))
                    ->requiresConfirmation()
                    ->schema([Textarea::make('notes')->label('Alasan')->required()->rows(2)->maxLength(500)])
                    ->action(function (array $data, Subscription $record, SubscriptionService $service) {
                        $service->setStatus($record, Subscription::STATUS_CANCELED, $data['notes']);
                        Notification::make()->danger()->title('Langganan dibatalkan')->send();
                    }),
            ]);
    }
}
