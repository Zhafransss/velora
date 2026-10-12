<?php

namespace App\Services\Admin;

use App\Models\Subscription;
use App\Models\Tenant;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Satu-satunya jalur mengubah langganan (FR-004: entitlement terpusat, bukan logika di controller/UI).
 * Dipakai bersama oleh panel admin Filament dan command CLI. Setiap perubahan tercatat di audit_logs.
 * Penagihan masih manual (DEC-07): admin mencatat pembayaran yang sudah diterima di luar sistem.
 */
class SubscriptionService
{
    public const MAX_DAYS = 3650;

    public function __construct(private AuditLogger $audit)
    {
    }

    /** @return list<string> */
    public static function planCodes(): array
    {
        return array_keys(config('plans'));
    }

    /**
     * Aktifkan paket baru. Langganan yang masih berlaku digantikan (status canceled, riwayat tetap ada) sehingga
     * hanya ada satu langganan berlaku per workspace.
     */
    public function activate(Tenant $tenant, string $plan, int $days, ?string $notes = null): Subscription
    {
        $this->assertPlan($plan);
        $this->assertDays($days);

        return DB::transaction(function () use ($tenant, $plan, $days, $notes) {
            $tenant->subscriptions()
                ->whereIn('status', Subscription::ENTITLED_STATUSES)->get()
                ->each(fn (Subscription $old) => $this->setStatus($old, Subscription::STATUS_CANCELED, 'Digantikan oleh paket baru'));

            $subscription = $tenant->subscriptions()->create([
                'plan_code' => $plan,
                'status' => Subscription::STATUS_ACTIVE,
                'current_period_start' => now(),
                'current_period_end' => now()->addDays($days),
                'notes' => $notes,
            ]);

            $this->audit->record('subscription.activated', $subscription, $tenant, [], $this->snapshot($subscription), $notes);

            return $subscription;
        });
    }

    /**
     * Perpanjang: bila masih berlaku, hari ditambahkan ke tanggal berakhir; bila sudah habis/ditangguhkan, dihitung
     * mulai sekarang. Status menjadi active.
     */
    public function extend(Subscription $subscription, int $days, ?string $notes = null): Subscription
    {
        $this->assertDays($days);

        return DB::transaction(function () use ($subscription, $days, $notes) {
            $before = $this->snapshot($subscription);
            $base = ($subscription->isEntitled() && $subscription->current_period_end?->isFuture())
                ? $subscription->current_period_end
                : now();

            $subscription->update([
                'status' => Subscription::STATUS_ACTIVE,
                'current_period_start' => $subscription->isEntitled() ? $subscription->current_period_start : now(),
                'current_period_end' => $base->copy()->addDays($days),
                'notes' => $this->appendNote($subscription->notes, $notes),
            ]);

            $this->audit->record('subscription.extended', $subscription, $subscription->tenant, $before, $this->snapshot($subscription), $notes);

            return $subscription->refresh();
        });
    }

    public function setStatus(Subscription $subscription, string $status, ?string $notes = null): Subscription
    {
        $valid = [Subscription::STATUS_TRIALING, Subscription::STATUS_ACTIVE, Subscription::STATUS_PAST_DUE, Subscription::STATUS_SUSPENDED, Subscription::STATUS_CANCELED, Subscription::STATUS_EXPIRED];

        if (! in_array($status, $valid, true)) {
            throw new InvalidArgumentException("Unknown subscription status '{$status}'.");
        }

        $before = $this->snapshot($subscription);
        $subscription->update(['status' => $status, 'notes' => $this->appendNote($subscription->notes, $notes)]);

        $this->audit->record('subscription.status_changed', $subscription, $subscription->tenant, $before, $this->snapshot($subscription), $notes);

        return $subscription->refresh();
    }

    private function assertPlan(string $plan): void
    {
        if (! in_array($plan, self::planCodes(), true)) {
            throw new InvalidArgumentException("Unknown plan '{$plan}'. Valid: " . implode(', ', self::planCodes()) . '.');
        }
    }

    private function assertDays(int $days): void
    {
        if ($days < 1 || $days > self::MAX_DAYS) {
            throw new InvalidArgumentException('Days must be between 1 and ' . self::MAX_DAYS . '.');
        }
    }

    /** @return array<string, mixed> */
    private function snapshot(Subscription $s): array
    {
        return [
            'plan_code' => $s->plan_code,
            'status' => $s->status,
            'period_start' => $s->current_period_start?->toDateTimeString(),
            'period_end' => $s->current_period_end?->toDateTimeString(),
        ];
    }

    private function appendNote(?string $existing, ?string $new): ?string
    {
        if ($new === null || trim($new) === '') {
            return $existing;
        }

        return trim(($existing ? $existing . "\n" : '') . '[' . now()->format('d M Y') . '] ' . $new);
    }
}
