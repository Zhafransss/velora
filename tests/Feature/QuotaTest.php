<?php

use App\Models\DailyUsage;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\UsageEvent;
use App\Services\Usage\QuotaDecision;
use App\Services\Usage\QuotaManager;
use App\Support\Tenancy\CurrentTenant;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

function qTenant(string $plan = 'small', array $tenant = []): Tenant
{
    $t = Tenant::factory()->create($tenant);
    Subscription::factory()->create(['tenant_id' => $t->id, 'plan_code' => $plan]);

    return $t;
}

function quota(): QuotaManager
{
    return new QuotaManager();
}

function ledgerCount(Tenant $t): int
{
    return DB::table('usage_events')->where('tenant_id', $t->id)->count();
}

// ---- Batas per paket ----
describe('limit per plan', function () {
    it('reads the daily limit from the plan config', function (string $plan, ?int $limit) {
        expect(quota()->limitFor(qTenant($plan)))->toBe($limit);
    })->with([['small', 100], ['medium', 1000], ['enterprise', null]]);

    it('blocks (limit 0) when there is no entitled subscription, an unknown plan, or an expired period', function () {
        $none = Tenant::factory()->create();
        $unknown = qTenant('platinum');
        $expired = Tenant::factory()->create();
        Subscription::factory()->expiredPeriod()->create(['tenant_id' => $expired->id]);
        $suspended = Tenant::factory()->create();
        Subscription::factory()->status('suspended')->create(['tenant_id' => $suspended->id]);

        foreach ([$none, $unknown, $expired, $suspended] as $t) {
            expect(quota()->limitFor($t))->toBe(0);
        }
    });

    it('denies reservation when the limit is 0', function () {
        $t = Tenant::factory()->create();

        expect(quota()->reserve($t, 'k1')->exceeded())->toBeTrue()->and(ledgerCount($t))->toBe(0);
    });
});

// ---- Reservasi ----
describe('reserve', function () {
    beforeEach(fn () => config(['plans.small.daily_ai_messages' => 3]));

    it('grants units up to the limit and then refuses', function () {
        $t = qTenant();

        $results = array_map(fn ($i) => quota()->reserve($t, "k{$i}")->status, [1, 2, 3, 4, 5]);

        expect($results)->toBe(['granted', 'granted', 'granted', 'exceeded', 'exceeded'])
            ->and(quota()->used($t))->toBe(3)->and(quota()->remaining($t))->toBe(0);
    });

    it('writes no ledger event and does not change usage when refused', function () {
        $t = qTenant();
        foreach ([1, 2, 3] as $i) {
            quota()->reserve($t, "k{$i}");
        }

        $d = quota()->reserve($t, 'k4');

        expect($d->exceeded())->toBeTrue()->and($d->used)->toBe(3)->and($d->limit)->toBe(3)
            ->and(ledgerCount($t))->toBe(3)
            ->and(DB::table('usage_events')->where('idempotency_key', 'k4')->exists())->toBeFalse();
    });

    // TC-QUOTA-02: dua pesan dengan sisa 1 unit -> hanya satu yang lolos.
    it('lets only one of two requests through when a single unit is left', function () {
        $t = qTenant();
        quota()->reserve($t, 'a');
        quota()->reserve($t, 'b');

        $first = quota()->reserve($t, 'c');
        $second = quota()->reserve($t, 'd');

        expect([$first->status, $second->status])->toBe(['granted', 'exceeded'])->and(quota()->used($t))->toBe(3);
    });

    it('uses a conditional UPDATE so a stale view of usage cannot overshoot the limit', function () {
        $t = qTenant();
        $date = quota()->dateFor($t);
        DB::table('daily_usage')->insert(['tenant_id' => $t->id, 'usage_date' => $date, 'used' => 2, 'created_at' => now(), 'updated_at' => now()]);

        $granted = collect(range(1, 6))->map(fn ($i) => quota()->reserve($t, "race{$i}")->granted())->filter()->count();

        expect($granted)->toBe(1)->and(quota()->used($t))->toBe(3);
    });

    // TC-IDEM-01
    it('never charges the same key twice', function () {
        $t = qTenant();

        $a = quota()->reserve($t, 'same');
        $b = quota()->reserve($t, 'same');
        $c = quota()->reserve($t, 'same');

        expect([$a->status, $b->status, $c->status])->toBe(['granted', 'duplicate', 'duplicate'])
            ->and(quota()->used($t))->toBe(1)->and(ledgerCount($t))->toBe(1);
    });

    it('returns duplicate for a known key even when the limit is already reached', function () {
        $t = qTenant();
        foreach (['a', 'b', 'c'] as $k) {
            quota()->reserve($t, $k);
        }

        expect(quota()->reserve($t, 'a')->duplicate())->toBeTrue()->and(quota()->used($t))->toBe(3);
    });

    it('counts test usage in the same daily quota', function () {
        $t = qTenant();

        quota()->reserve($t, 't1', 'test');
        quota()->reserve($t, 'c1', 'chat');

        expect(quota()->used($t))->toBe(2)
            ->and(DB::table('usage_events')->where('tenant_id', $t->id)->pluck('purpose')->sort()->values()->all())->toBe(['chat', 'test']);
    });

    it('keeps tenants independent', function () {
        [$a, $b] = [qTenant(), qTenant()];
        foreach ([1, 2, 3] as $i) {
            quota()->reserve($a, "a{$i}");
        }

        expect(quota()->reserve($a, 'a4')->exceeded())->toBeTrue()->and(quota()->reserve($b, 'b1')->granted())->toBeTrue()
            ->and(quota()->used($b))->toBe(1);
    });

    it('does not enforce a hard limit for unlimited plans but still records usage', function () {
        $t = qTenant('enterprise');

        foreach (range(1, 250) as $i) {
            quota()->reserve($t, "e{$i}");
        }

        expect(quota()->used($t))->toBe(250)->and(quota()->remaining($t))->toBeNull();
    });
});

// ---- Zona waktu ----
describe('tenant timezone', function () {
    it('buckets usage by the tenant local date', function () {
        config(['plans.small.daily_ai_messages' => 1]);
        $utc = Carbon::parse('2026-10-14 16:00:00', 'UTC'); // 14 Okt UTC = 15 Okt 01:00 WIT
        $wib = qTenant(tenant: ['timezone' => 'Asia/Jakarta']);   // UTC+7  -> 14 Okt 23:00
        $wit = qTenant(tenant: ['timezone' => 'Asia/Jayapura']);  // UTC+9  -> 15 Okt 01:00

        expect(quota()->dateFor($wib, $utc))->toBe('2026-10-14')->and(quota()->dateFor($wit, $utc))->toBe('2026-10-15');

        quota()->reserve($wit, 'k1', now: $utc);
        // Hari berikutnya menurut zona tenant: kuota segar.
        expect(quota()->reserve($wit, 'k2', now: $utc)->exceeded())->toBeTrue()
            ->and(quota()->reserve($wit, 'k3', now: $utc->copy()->addDay())->granted())->toBeTrue();
    });
});

// ---- Release ----
describe('release', function () {
    beforeEach(fn () => config(['plans.small.daily_ai_messages' => 2]));

    it('returns a reserved unit once and is idempotent', function () {
        $t = qTenant();
        quota()->reserve($t, 'k1');

        expect(quota()->release($t, 'k1'))->toBeTrue()->and(quota()->release($t, 'k1'))->toBeFalse()
            ->and(quota()->used($t))->toBe(0)
            ->and(DB::table('usage_events')->where('tenant_id', $t->id)->orderBy('id')->pluck('type')->all())->toBe(['reserve', 'release']);
    });

    it('frees capacity for another message', function () {
        $t = qTenant();
        quota()->reserve($t, 'a');
        quota()->reserve($t, 'b');
        expect(quota()->reserve($t, 'c')->exceeded())->toBeTrue();

        quota()->release($t, 'a');

        expect(quota()->reserve($t, 'c')->granted())->toBeTrue();
    });

    it('ignores unknown keys and keys of another tenant', function () {
        [$a, $b] = [qTenant(), qTenant()];
        quota()->reserve($a, 'ka');

        expect(quota()->release($a, 'nope'))->toBeFalse()->and(quota()->release($b, 'ka'))->toBeFalse()
            ->and(quota()->used($a))->toBe(1);
    });

    it('credits the day the unit was reserved, not today', function () {
        $t = qTenant();
        $yesterday = now()->subDay();
        quota()->reserve($t, 'old', now: $yesterday);

        quota()->release($t, 'old');

        expect(quota()->used($t, quota()->dateFor($t, $yesterday)))->toBe(0)
            ->and(DB::table('usage_events')->where('idempotency_key', 'release:old')->value('usage_date'))
            ->toStartWith(quota()->dateFor($t, $yesterday));
    });
});

// ---- Ledger & rekonsiliasi ----
describe('ledger and reconciliation', function () {
    it('keeps the aggregate equal to the ledger through reserve/release/adjust (TC-USG-01)', function () {
        $t = qTenant();
        foreach (['a', 'b', 'c', 'd'] as $k) {
            quota()->reserve($t, $k);
        }
        quota()->release($t, 'b');
        quota()->adjust($t, 5, 'koreksi manual', 'adj-1');
        quota()->adjust($t, -2, 'koreksi balik', 'adj-2');

        $r = quota()->reconcile($t);

        expect($r['consistent'])->toBeTrue()->and($r['ledger'])->toBe(6)->and($r['aggregate'])->toBe(6);
    });

    it('detects drift when the aggregate is tampered with', function () {
        $t = qTenant();
        quota()->reserve($t, 'a');
        DB::table('daily_usage')->where('tenant_id', $t->id)->update(['used' => 9]);

        expect(quota()->reconcile($t))->toMatchArray(['ledger' => 1, 'aggregate' => 9, 'consistent' => false]);
    });

    it('never lets an adjustment drive the aggregate below zero', function () {
        $t = qTenant();
        quota()->reserve($t, 'a');

        quota()->adjust($t, -10, 'terlalu besar', 'adj-x');

        expect(quota()->used($t))->toBe(0);
    });

    it('rejects a repeated adjustment key', function () {
        $t = qTenant();
        quota()->adjust($t, 1, 'sekali', 'adj-1');

        expect(fn () => quota()->adjust($t, 1, 'sekali', 'adj-1'))->toThrow(\Illuminate\Database\UniqueConstraintViolationException::class);
        expect(quota()->used($t))->toBe(1);
    });

    it('treats the ledger as append-only', function () {
        $t = qTenant();
        quota()->reserve($t, 'a');
        $event = app(CurrentTenant::class)->runAs($t, fn () => UsageEvent::first());

        expect(fn () => $event->update(['units' => 5]))->toThrow(LogicException::class)
            ->and(fn () => $event->delete())->toThrow(LogicException::class);
    });

    it('does not allow mass assignment on the aggregate model', function () {
        $t = qTenant();

        expect(fn () => app(CurrentTenant::class)->runAs($t, fn () => DailyUsage::create(['usage_date' => '2026-01-01', 'used' => 99])))
            ->toThrow(\Illuminate\Database\Eloquent\MassAssignmentException::class);
    });
});

// ---- Isolasi tenant pada tabel metering ----
it('isolates usage ledger and aggregate by tenant', function () {
    [$a, $b] = [qTenant(), qTenant()];
    quota()->reserve($a, 'a1');
    quota()->reserve($b, 'b1');
    quota()->reserve($b, 'b2');

    $ctx = app(CurrentTenant::class);
    $ctx->set($a);
    expect(UsageEvent::count())->toBe(1)->and(DailyUsage::sole()->used)->toBe(1);

    $ctx->set($b);
    expect(UsageEvent::count())->toBe(2)->and(DailyUsage::sole()->used)->toBe(2);

    $ctx->clear();
    expect(UsageEvent::count())->toBe(0)->and(DailyUsage::count())->toBe(0); // gagal tertutup
});
