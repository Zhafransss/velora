<?php

namespace App\Services\Usage;

use App\Models\Subscription;
use App\Models\Tenant;
use Carbon\CarbonInterface;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Kuota harian pesan AI per tenant (FR-012).
 *
 * Aturan:
 *  - Satu pesan pelanggan yang diproses AI = 1 unit; retry provider dalam satu request tidak menambah unit
 *    (dipanggil sekali per pesan, dengan kunci idempotency per pesan).
 *  - Reservasi ATOMIK: kenaikan dilakukan oleh satu UPDATE bersyarat (`used < limit`) sehingga dua proses paralel
 *    dengan sisa 1 unit tidak mungkin sama-sama lolos (baris dikunci oleh database).
 *  - Idempotent: kunci yang sama tidak pernah memakai unit dua kali.
 *  - Ledger `usage_events` append-only; `daily_usage` adalah agregat yang dapat direkonsiliasi terhadap ledger.
 *  - Hari dihitung menurut zona waktu tenant.
 */
class QuotaManager
{
    /** Percobaan ulang transaksi bila MySQL melaporkan deadlock/lock-wait (konkurensi tinggi pada satu tenant). */
    private const DEADLOCK_RETRIES = 5;

    /** Batas harian tenant. null = tanpa batas keras. Tanpa langganan layak = 0 (diblokir). */
    public function limitFor(Tenant $tenant): ?int
    {
        $subscription = $tenant->subscriptions()
            ->whereIn('status', Subscription::ENTITLED_STATUSES)
            ->latest('id')
            ->get()
            ->first(fn (Subscription $s) => $s->isEntitled());

        if ($subscription === null) {
            return 0;
        }

        $plan = config("plans.{$subscription->plan_code}");

        // Paket tak dikenal: gagal tertutup, bukan tanpa batas.
        return $plan === null ? 0 : $plan['daily_ai_messages'];
    }

    public function reserve(Tenant $tenant, string $key, string $purpose = 'chat', ?int $messageId = null, ?CarbonInterface $now = null): QuotaDecision
    {
        $date = $this->dateFor($tenant, $now);
        $limit = $this->limitFor($tenant);

        // Jalur cepat: kunci yang sudah pernah dipakai tidak perlu masuk transaksi.
        if (DB::table('usage_events')->where('idempotency_key', $key)->exists()) {
            return new QuotaDecision(QuotaDecision::DUPLICATE, $limit, $this->used($tenant, $date), $date);
        }

        $this->ensureDailyRow($tenant, $date);

        try {
            // Urutan kunci SELALU: baris agregat dulu, lalu ledger. Urutan yang konsisten mencegah deadlock antar
            // proses paralel; DB::transaction juga mengulang otomatis bila deadlock tetap terjadi.
            DB::transaction(function () use ($tenant, $key, $purpose, $messageId, $date, $limit) {
                $query = DB::table('daily_usage')->where('tenant_id', $tenant->id)->where('usage_date', $date);

                if ($limit !== null) {
                    $query->where('used', '<', $limit);
                }

                // Titik atomik: hanya satu proses yang dapat menaikkan baris saat sisa tinggal 1.
                if ($query->increment('used') === 0) {
                    throw new QuotaSignal();
                }

                DB::table('usage_events')->insert([
                    'tenant_id' => $tenant->id, 'usage_date' => $date, 'type' => 'reserve', 'units' => 1,
                    'idempotency_key' => $key, 'purpose' => $purpose, 'message_id' => $messageId,
                ]);
            }, self::DEADLOCK_RETRIES);
        } catch (QuotaSignal) {
            return new QuotaDecision(QuotaDecision::EXCEEDED, $limit, $this->used($tenant, $date), $date);
        } catch (UniqueConstraintViolationException) {
            // Balapan: proses lain mencatat kunci yang sama; transaksi ini sudah di-rollback (kenaikan ikut batal).
            return new QuotaDecision(QuotaDecision::DUPLICATE, $limit, $this->used($tenant, $date), $date);
        }

        return new QuotaDecision(QuotaDecision::GRANTED, $limit, $this->used($tenant, $date), $date);
    }

    /**
     * Kembalikan unit yang sudah direservasi (mis. AI gagal total sehingga pelanggan tidak menerima nilai).
     * Idempotent; mengembalikan true hanya bila unit benar-benar dikembalikan sekarang.
     */
    public function release(Tenant $tenant, string $reserveKey, ?int $messageId = null): bool
    {
        $reserve = DB::table('usage_events')
            ->where('tenant_id', $tenant->id)->where('idempotency_key', $reserveKey)->where('type', 'reserve')
            ->first();

        if ($reserve === null) {
            return false;
        }

        $releaseKey = 'release:' . $reserveKey;

        if (DB::table('usage_events')->where('idempotency_key', $releaseKey)->exists()) {
            return false;
        }

        try {
            DB::transaction(function () use ($tenant, $reserve, $releaseKey, $messageId) {
                DB::table('daily_usage')
                    ->where('tenant_id', $tenant->id)->where('usage_date', $reserve->usage_date)->where('used', '>', 0)
                    ->decrement('used');

                DB::table('usage_events')->insert([
                    'tenant_id' => $tenant->id, 'usage_date' => $reserve->usage_date, 'type' => 'release', 'units' => -1,
                    'idempotency_key' => $releaseKey, 'purpose' => $reserve->purpose, 'message_id' => $messageId ?? $reserve->message_id,
                ]);
            }, self::DEADLOCK_RETRIES);
        } catch (UniqueConstraintViolationException) {
            return false; // proses lain sudah mengembalikan; decrement ikut di-rollback
        }

        return true;
    }

    /** Koreksi manual oleh admin platform (Tahap 4): tercatat sebagai event, tidak mengubah riwayat. */
    public function adjust(Tenant $tenant, int $units, string $note, string $key, ?CarbonInterface $now = null): void
    {
        $date = $this->dateFor($tenant, $now);
        $this->ensureDailyRow($tenant, $date);

        DB::transaction(function () use ($tenant, $units, $note, $key, $date) {
            // Agregat tidak boleh negatif.
            DB::table('daily_usage')->where('tenant_id', $tenant->id)->where('usage_date', $date)
                ->update(['used' => DB::raw('CASE WHEN used + (' . (int) $units . ') < 0 THEN 0 ELSE used + (' . (int) $units . ') END')]);

            DB::table('usage_events')->insert([
                'tenant_id' => $tenant->id, 'usage_date' => $date, 'type' => 'adjustment', 'units' => $units,
                'idempotency_key' => $key, 'purpose' => 'chat', 'note' => mb_substr($note, 0, 200),
            ]);
        }, self::DEADLOCK_RETRIES);
    }

    /** Pastikan baris agregat ada (di luar transaksi utama, idempoten) agar UPDATE bersyarat selalu punya baris untuk dikunci. */
    private function ensureDailyRow(Tenant $tenant, string $date): void
    {
        DB::table('daily_usage')->insertOrIgnore([
            'tenant_id' => $tenant->id, 'usage_date' => $date, 'used' => 0, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function used(Tenant $tenant, ?string $date = null): int
    {
        return (int) DB::table('daily_usage')
            ->where('tenant_id', $tenant->id)->where('usage_date', $date ?? $this->dateFor($tenant))
            ->value('used');
    }

    public function remaining(Tenant $tenant): ?int
    {
        $limit = $this->limitFor($tenant);

        return $limit === null ? null : max(0, $limit - $this->used($tenant));
    }

    /** TC-USG-01: agregat harian harus sama dengan jumlah ledger untuk tanggal tersebut. */
    public function reconcile(Tenant $tenant, ?string $date = null): array
    {
        $date ??= $this->dateFor($tenant);
        $ledger = (int) DB::table('usage_events')->where('tenant_id', $tenant->id)->where('usage_date', $date)->sum('units');
        $aggregate = $this->used($tenant, $date);

        return ['date' => $date, 'ledger' => $ledger, 'aggregate' => $aggregate, 'consistent' => $ledger === $aggregate];
    }

    public function dateFor(Tenant $tenant, ?CarbonInterface $now = null): string
    {
        // Model yang baru dibuat belum memuat default kolom dari DB; jangan bergantung pada itu.
        return ($now ?? now())->copy()->setTimezone($tenant->timezone ?: 'Asia/Jakarta')->toDateString();
    }
}

/** Sinyal internal untuk membatalkan transaksi reservasi saat kuota habis (bukan galat). */
final class QuotaSignal extends \Exception
{
}
