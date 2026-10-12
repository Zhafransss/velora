<?php

namespace App\Console\Commands;

use App\Models\Subscription;
use App\Models\Tenant;
use App\Services\Notifications\TenantNotifier;
use App\Services\Plans\Entitlements;
use Illuminate\Console\Command;

/**
 * Pengingat masa langganan di dashboard (DEC-16): satu notifikasi pada ambang 7, 3, dan 1 hari sebelum berakhir,
 * dan satu notifikasi ketika langganan berakhir. Idempoten (dedupe_key), aman dijalankan berulang.
 */
class NotifySubscriptionExpiry extends Command
{
    private const EXPIRED_LOOKBACK_DAYS = 7;

    protected $signature = 'notifications:subscription-expiry';

    protected $description = 'Buat notifikasi dashboard untuk langganan yang segera/sudah berakhir.';

    public function handle(TenantNotifier $notifier): int
    {
        $expiring = 0;
        $expired = 0;

        // Langganan layak yang berakhir dalam 7 hari ke depan.
        Subscription::whereIn('status', Subscription::ENTITLED_STATUSES)
            ->whereNotNull('current_period_end')
            ->whereBetween('current_period_end', [now(), now()->addDays(7)])
            ->with('tenant')->get()
            ->each(function (Subscription $s) use ($notifier, &$expiring) {
                $daysLeft = (int) ceil(now()->diffInHours($s->current_period_end, false) / 24);
                $bucket = $daysLeft <= 1 ? 1 : ($daysLeft <= 3 ? 3 : 7);

                $notifier->subscriptionExpiring($s->tenant, $s, max(0, $daysLeft), $bucket);
                $expiring++;
            });

        // Langganan yang baru berakhir dan tidak digantikan langganan layak lain.
        Subscription::whereIn('status', [Subscription::STATUS_ACTIVE, Subscription::STATUS_TRIALING])
            ->whereNotNull('current_period_end')
            ->whereBetween('current_period_end', [now()->subDays(self::EXPIRED_LOOKBACK_DAYS), now()])
            ->with('tenant')->get()
            ->each(function (Subscription $s) use ($notifier, &$expired) {
                /** @var Tenant $tenant */
                $tenant = $s->tenant;

                if (Entitlements::currentSubscription($tenant) === null) {
                    $notifier->subscriptionExpired($tenant, $s);
                    $expired++;
                }
            });

        $this->info("Pengingat: {$expiring} langganan akan berakhir, {$expired} sudah berakhir.");

        return self::SUCCESS;
    }
}
