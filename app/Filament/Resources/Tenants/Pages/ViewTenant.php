<?php

namespace App\Filament\Resources\Tenants\Pages;

use App\Filament\Resources\Tenants\TenantResource;
use App\Models\Tenant;
use App\Services\Admin\SubscriptionService;
use App\Services\Admin\TenantStatusService;
use App\Services\Plans\Entitlements;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;

class ViewTenant extends ViewRecord
{
    protected static string $resource = TenantResource::class;

    protected function getHeaderActions(): array
    {
        return [
            // Penagihan manual (DEC-07): admin mencatat pembayaran yang sudah diterima di luar sistem.
            Action::make('activateSubscription')
                ->label('Aktifkan langganan')
                ->icon('heroicon-o-check-badge')
                ->color('success')
                ->modalHeading('Aktifkan langganan')
                ->modalDescription('Dicatat setelah pembayaran diterima. Langganan yang masih berlaku akan digantikan paket baru.')
                ->schema([
                    Select::make('plan')->label('Paket')->options(self::planOptions())->required()->default('small')->live()
                        // Harga dan lama terisi otomatis dari paket; tetap bisa diubah (mis. diskon).
                        ->afterStateUpdated(function ($state, $set) {
                            $set('price', config("plans.{$state}.price_idr"));
                            $set('days', config("plans.{$state}.period_days", 30));
                        }),
                    TextInput::make('price')->label('Harga disepakati (Rp)')->numeric()->minValue(0)->default(config('plans.small.price_idr'))
                        ->helperText('Kosong = harga paket (paket penawaran tanpa harga tetap dicatat tanpa harga).'),
                    TextInput::make('days')->label('Lama (hari)')->numeric()->minValue(1)->maxValue(SubscriptionService::MAX_DAYS)->default(config('plans.small.period_days', 30))->required(),
                    Textarea::make('notes')->label('Catatan pembayaran (mis. nomor invoice, tanggal transfer)')->rows(2)->maxLength(500),
                ])
                ->action(function (array $data, Tenant $record, SubscriptionService $service) {
                    $subscription = $service->activate(
                        $record, $data['plan'], (int) $data['days'], $data['notes'] ?? null,
                        ($data['price'] ?? '') === '' || $data['price'] === null ? null : (int) $data['price'],
                    );

                    Notification::make()->success()->title('Langganan aktif')
                        ->body(strtoupper($subscription->plan_code) . ' sampai ' . $subscription->current_period_end->format('d M Y'))->send();
                }),

            Action::make('suspendTenant')
                ->label('Tangguhkan workspace')
                ->icon('heroicon-o-no-symbol')
                ->color('danger')
                ->visible(fn (Tenant $record) => $record->status === Tenant::STATUS_ACTIVE)
                ->requiresConfirmation()
                ->modalDescription('Workspace kehilangan akses ke seluruh fitur dan chatbot berhenti membalas pelanggan sampai diaktifkan kembali.')
                ->schema([Textarea::make('note')->label('Alasan')->required()->rows(2)->maxLength(500)])
                ->action(function (array $data, Tenant $record, TenantStatusService $service) {
                    $service->suspend($record, $data['note']);
                    Notification::make()->warning()->title('Workspace ditangguhkan')->send();
                }),

            Action::make('reactivateTenant')
                ->label('Aktifkan kembali workspace')
                ->icon('heroicon-o-play')
                ->visible(fn (Tenant $record) => $record->status === Tenant::STATUS_SUSPENDED)
                ->requiresConfirmation()
                ->schema([Textarea::make('note')->label('Catatan')->rows(2)->maxLength(500)])
                ->action(function (array $data, Tenant $record, TenantStatusService $service) {
                    $service->reactivate($record, $data['note'] ?? null);
                    Notification::make()->success()->title('Workspace diaktifkan kembali')->send();
                }),
        ];
    }

    /** @return array<string, string> */
    private static function planOptions(): array
    {
        return collect(Entitlements::allPlans())
            ->mapWithKeys(fn (Entitlements $e, $code) => [$code => strtoupper($e->label) . ' — ' . $e->priceLabel() . ' / ' . $e->periodDays . ' hari'])->all();
    }
}
