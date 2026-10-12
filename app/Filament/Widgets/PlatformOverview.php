<?php

namespace App\Filament\Widgets;

use App\Models\Channel;
use App\Models\Message;
use App\Models\Subscription;
use App\Models\Tenant;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Facades\DB;

/** Ringkasan operasional platform di dashboard admin (FR-014). */
class PlatformOverview extends StatsOverviewWidget
{
    protected static ?int $sort = -10;

    protected ?string $heading = 'Ringkasan platform';

    protected function getStats(): array
    {
        $entitled = fn ($q) => $q->whereIn('status', Subscription::ENTITLED_STATUSES)
            ->where(fn ($p) => $p->whereNull('current_period_end')->orWhere('current_period_end', '>', now()));

        $tenants = Tenant::count();
        $subscribed = Tenant::whereHas('subscriptions', $entitled)->count();
        $expiring = Subscription::whereIn('status', Subscription::ENTITLED_STATUSES)
            ->whereBetween('current_period_end', [now(), now()->addDays(7)])->count();

        $today = now('Asia/Jakarta')->toDateString();
        $aiToday = (int) DB::table('daily_usage')->where('usage_date', $today)->sum('used');

        // Kanal yang seharusnya tersambung tetapi tidak (belum dibuat/dipasangkan tidak dihitung).
        $channelsDown = Channel::withoutTenancy()->whereIn('status', ['failed', 'logged_out', 'reconnecting', 'stopped', 'qr_expired'])->count();

        $queued = DB::table('jobs')->count();
        $failedJobs = DB::table('failed_jobs')->count();

        // Pesan yang menggantung lama menandakan worker/konektor bermasalah.
        $stuck = Message::withoutTenancy()
            ->whereIn('status', [Message::RECEIVED, Message::PROCESSING, Message::PENDING, Message::SENDING])
            ->where('updated_at', '<', now()->subMinutes(10))->count();

        return [
            Stat::make('Workspace', $tenants)->description("{$subscribed} berlangganan aktif"),
            Stat::make('Langganan berakhir ≤ 7 hari', $expiring)->color($expiring > 0 ? 'warning' : 'success'),
            Stat::make('Pesan AI hari ini', $aiToday)->description('semua workspace'),
            Stat::make('Kanal WhatsApp bermasalah', $channelsDown)->color($channelsDown > 0 ? 'danger' : 'success'),
            Stat::make('Antrean', $queued)->description("{$failedJobs} job gagal")->color($failedJobs > 0 ? 'danger' : 'success'),
            Stat::make('Pesan menggantung > 10 menit', $stuck)->color($stuck > 0 ? 'danger' : 'success')
                ->description($stuck > 0 ? 'Periksa queue worker & konektor' : 'Normal'),
        ];
    }
}
