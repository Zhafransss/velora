<?php

namespace App\Support\Admin;

use App\Models\Channel;
use App\Models\Message;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\TenantMember;
use App\Models\User;
use App\Services\Usage\QuotaManager;
use Illuminate\Support\Facades\DB;

/**
 * Data turunan per workspace untuk panel admin platform. Admin berada di luar konteks tenant, jadi semua query ke
 * model ber-BelongsToTenant memakai withoutTenancy() secara eksplisit dan terbatas pada satu tenant_id.
 */
class TenantInsights
{
    public static function owner(Tenant $tenant): ?User
    {
        $member = $tenant->relationLoaded('members')
            ? $tenant->members->firstWhere('role', TenantMember::ROLE_OWNER)
            : $tenant->members()->where('role', TenantMember::ROLE_OWNER)->first();

        return $member?->user;
    }

    public static function currentSubscription(Tenant $tenant): ?Subscription
    {
        return $tenant->relationLoaded('currentSubscription') ? $tenant->currentSubscription : $tenant->currentSubscription()->first();
    }

    public static function channel(Tenant $tenant): ?Channel
    {
        return Channel::withoutTenancy()->where('tenant_id', $tenant->id)->orderBy('id')->first();
    }

    public static function usageToday(Tenant $tenant): int
    {
        return app(QuotaManager::class)->used($tenant);
    }

    /** null = tanpa batas keras; 0 = tidak ada langganan layak */
    public static function dailyLimit(Tenant $tenant): ?int
    {
        return app(QuotaManager::class)->limitFor($tenant);
    }

    /** @return array<string, int> jumlah pesan masuk per status dalam N jam terakhir */
    public static function inboundByStatus(Tenant $tenant, int $hours = 24): array
    {
        return Message::withoutTenancy()
            ->where('tenant_id', $tenant->id)->where('direction', Message::IN)
            ->where('created_at', '>=', now()->subHours($hours))
            ->select('status', DB::raw('count(*) as total'))->groupBy('status')->pluck('total', 'status')
            ->map(fn ($n) => (int) $n)->all();
    }

    /** @return array<string, int> pemakaian harian terakhir (tanggal => unit) */
    public static function usageHistory(Tenant $tenant, int $days = 7): array
    {
        return DB::table('daily_usage')->where('tenant_id', $tenant->id)
            ->orderByDesc('usage_date')->limit($days)->pluck('used', 'usage_date')->map(fn ($n) => (int) $n)->all();
    }

    public static function subscriptionLabel(?Subscription $s): string
    {
        if ($s === null) {
            return 'Belum pernah berlangganan';
        }

        $state = $s->isEntitled() ? $s->status : ($s->status === Subscription::STATUS_ACTIVE ? 'habis' : $s->status);

        return strtoupper($s->plan_code) . ' · ' . $state;
    }
}
