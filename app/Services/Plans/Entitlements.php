<?php

namespace App\Services\Plans;

use App\Models\Subscription;
use App\Models\Tenant;

/**
 * Hak akses yang dimiliki sebuah workspace saat ini, diturunkan dari langganan yang layak (status trialing/active
 * dan periode belum lewat) dan paketnya di config/plans.php. Satu-satunya tempat yang menerjemahkan paket
 * menjadi hak/batas (FR-004). Tanpa langganan layak: tidak ada hak (kuota 0).
 */
final class Entitlements
{
    private function __construct(
        public readonly ?string $planCode,
        public readonly string $label,
        /** null = tanpa batas keras; 0 = tidak boleh memakai AI */
        public readonly ?int $dailyAiMessages,
        /** null = seluruh masa simpan */
        public readonly ?int $historyDays,
        public readonly ?int $priceIdr,
        public readonly int $periodDays,
    ) {
    }

    public static function forTenant(Tenant $tenant): self
    {
        $subscription = self::currentSubscription($tenant);
        $plan = $subscription ? config("plans.{$subscription->plan_code}") : null;

        // Tanpa langganan layak, atau paket tak dikenal: gagal tertutup (bukan tanpa batas).
        if ($subscription === null || $plan === null) {
            return new self(null, 'Belum berlangganan', 0, 0, null, 30);
        }

        return self::fromPlan($subscription->plan_code, $plan);
    }

    public static function forPlan(string $code): ?self
    {
        $plan = config("plans.{$code}");

        return $plan === null ? null : self::fromPlan($code, $plan);
    }

    /** @return array<string, self> semua paket, untuk halaman harga */
    public static function allPlans(): array
    {
        return collect(array_keys(config('plans')))->mapWithKeys(fn ($code) => [$code => self::forPlan($code)])->all();
    }

    /** Langganan layak terbaru, atau null. Memakai relasi yang sudah di-eager-load bila ada (tabel admin, tanpa N+1). */
    public static function currentSubscription(Tenant $tenant): ?Subscription
    {
        $candidates = $tenant->relationLoaded('entitledSubscriptions')
            ? $tenant->entitledSubscriptions
            : $tenant->entitledSubscriptions()->get();

        return $candidates->first(fn (Subscription $s) => $s->isEntitled());
    }

    /** Batas awal (tanggal) riwayat yang boleh dilihat, atau null bila tanpa batas selain masa simpan. */
    public function historySince(): ?\Carbon\CarbonInterface
    {
        return $this->historyDays === null ? null : now()->subDays($this->historyDays);
    }

    public function priceLabel(): string
    {
        return self::rupiah($this->priceIdr) ?? 'Hubungi kami';
    }

    public static function rupiah(int|float|string|null $amount): ?string
    {
        return $amount === null ? null : 'Rp' . number_format((float) $amount, 0, ',', '.');
    }

    private static function fromPlan(string $code, array $plan): self
    {
        return new self(
            $code,
            $plan['label'] ?? ucfirst($code),
            // null berarti "tanpa batas keras" (Enterprise); kunci yang hilang berarti paket salah konfigurasi -> 0 (diblokir).
            array_key_exists('daily_ai_messages', $plan) ? $plan['daily_ai_messages'] : 0,
            array_key_exists('history_days', $plan) ? $plan['history_days'] : null,
            $plan['price_idr'] ?? null,
            (int) ($plan['period_days'] ?? 30),
        );
    }
}
