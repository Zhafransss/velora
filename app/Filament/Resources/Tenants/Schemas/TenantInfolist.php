<?php

namespace App\Filament\Resources\Tenants\Schemas;

use App\Models\Bot;
use App\Models\Tenant;
use App\Support\Admin\TenantInsights;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class TenantInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Workspace')->columns(3)->components([
                TextEntry::make('name')->label('Nama'),
                TextEntry::make('slug')->label('Slug')->copyable(),
                TextEntry::make('status')->label('Status')->badge()
                    ->color(fn (string $state) => $state === Tenant::STATUS_ACTIVE ? 'success' : 'danger'),
                TextEntry::make('timezone')->label('Zona waktu'),
                TextEntry::make('created_at')->label('Terdaftar')->dateTime('d M Y H:i'),
            ]),

            // Pemilik & anggota: tabel "Akun & anggota" (MembersRelationManager) di bawah halaman ini.

            Section::make('Langganan')->columns(3)->components([
                TextEntry::make('current_plan')->label('Paket')
                    ->state(fn (Tenant $t) => TenantInsights::subscriptionLabel(TenantInsights::currentSubscription($t)))
                    ->badge(),
                TextEntry::make('period')->label('Periode')
                    ->state(function (Tenant $t) {
                        $s = TenantInsights::currentSubscription($t);

                        return $s ? $s->current_period_start?->format('d M Y') . ' → ' . ($s->current_period_end?->format('d M Y') ?? 'tanpa batas') : '—';
                    }),
                TextEntry::make('limit')->label('Batas AI per hari')
                    ->state(fn (Tenant $t) => TenantInsights::dailyLimit($t) ?? 'Tanpa batas keras (fair use)'),
                TextEntry::make('sub_notes')->label('Catatan penagihan')->columnSpanFull()->prose()
                    ->state(fn (Tenant $t) => TenantInsights::currentSubscription($t)?->notes ?? '—'),
            ]),

            Section::make('Pemakaian')->columns(2)->components([
                TextEntry::make('usage_today')->label('Pesan AI hari ini')
                    ->state(function (Tenant $t) {
                        $limit = TenantInsights::dailyLimit($t);

                        return TenantInsights::usageToday($t) . ' / ' . ($limit === null ? '∞' : $limit);
                    }),
                TextEntry::make('usage_week')->label('7 hari terakhir')
                    ->state(function (Tenant $t) {
                        $h = TenantInsights::usageHistory($t);

                        return $h === [] ? '—' : collect($h)->map(fn ($n, $d) => substr((string) $d, 5, 5) . ': ' . $n)->implode(' · ');
                    }),
                TextEntry::make('inbound_24h')->label('Pesan pelanggan 24 jam terakhir (per status)')->columnSpanFull()
                    ->state(function (Tenant $t) {
                        $c = TenantInsights::inboundByStatus($t);

                        return $c === [] ? 'Belum ada pesan' : collect($c)->map(fn ($n, $s) => "{$s}: {$n}")->implode(' · ');
                    }),
            ]),

            Section::make('Bot & WhatsApp')->columns(3)->components([
                TextEntry::make('bot')->label('Bot')
                    ->state(fn (Tenant $t) => ($b = Bot::withoutTenancy()->where('tenant_id', $t->id)->orderBy('id')->first()) ? "{$b->name} ({$b->status})" : 'Belum dikonfigurasi'),
                TextEntry::make('channel_status')->label('Status kanal')->badge()
                    ->state(fn (Tenant $t) => TenantInsights::channel($t)?->status ?? 'belum ada'),
                TextEntry::make('channel_account')->label('Akun tertaut')
                    ->state(fn (Tenant $t) => ($a = TenantInsights::channel($t)?->external_account_id) ? preg_replace('/@.*/', '', $a) : '—'),
            ]),
        ]);
    }
}
