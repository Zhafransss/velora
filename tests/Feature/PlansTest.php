<?php

use App\Filament\Resources\Subscriptions\Pages\ListSubscriptions;
use App\Filament\Resources\Tenants\Pages\ViewTenant;
use App\Livewire\Tenant\ConversationHistory;
use App\Models\AuditLog;
use App\Models\Bot;
use App\Models\Channel;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\TenantMember;
use App\Models\User;
use App\Services\Admin\SubscriptionService;
use App\Services\Plans\Entitlements;
use App\Support\Tenancy\CurrentTenant;
use Filament\Facades\Filament;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Livewire\Livewire;

function pxTenant(?string $plan = 'small', string $role = 'owner'): array
{
    $tenant = Tenant::factory()->create();
    $plan && Subscription::factory()->create(['tenant_id' => $tenant->id, 'plan_code' => $plan]);
    $user = User::factory()->create();
    TenantMember::create(['tenant_id' => $tenant->id, 'user_id' => $user->id, 'role' => $role]);
    app(CurrentTenant::class)->set($tenant);

    return [$user, $tenant];
}

function pxConversation(Tenant $t, string $name, int $daysAgo): Conversation
{
    $bot = Bot::factory()->create(['tenant_id' => $t->id]);
    $ch = Channel::factory()->create(['tenant_id' => $t->id, 'bot_id' => $bot->id]);

    return app(CurrentTenant::class)->runAs($t, function () use ($bot, $ch, $name, $daysAgo) {
        $c = Conversation::create(['bot_id' => $bot->id, 'channel_id' => $ch->id, 'customer_external_id' => strtolower($name) . '@s.whatsapp.net', 'customer_name' => $name, 'last_message_at' => now()->subDays($daysAgo)]);
        $m = Message::create(['conversation_id' => $c->id, 'direction' => 'inbound', 'external_message_id' => "m-{$name}", 'body' => "pesan dari {$name}", 'status' => 'answered']);
        \Illuminate\Support\Facades\DB::table('messages')->where('id', $m->id)->update(['created_at' => now()->subDays($daysAgo)]);

        return $c;
    });
}

// ================= Entitlements =================
describe('Entitlements', function () {
    it('derives limits and price from the plan of the current subscription', function (string $plan, ?int $daily, ?int $history, ?int $price) {
        [, $t] = pxTenant($plan);

        $e = Entitlements::forTenant($t);

        expect($e->planCode)->toBe($plan)->and($e->dailyAiMessages)->toBe($daily)->and($e->historyDays)->toBe($history)->and($e->priceIdr)->toBe($price);
    })->with([
        'small' => ['small', 100, 7, 250000],
        'medium' => ['medium', 1000, null, 500000],
        'enterprise' => ['enterprise', null, null, null],
    ]);

    it('has no entitlements without an entitled subscription (fail closed)', function (string $case) {
        [, $t] = pxTenant(null);
        match ($case) {
            'none' => null,
            'expired' => Subscription::factory()->expiredPeriod()->create(['tenant_id' => $t->id]),
            'suspended' => Subscription::factory()->status('suspended')->create(['tenant_id' => $t->id]),
            'unknown plan' => Subscription::factory()->create(['tenant_id' => $t->id, 'plan_code' => 'platinum']),
        };

        $e = Entitlements::forTenant($t);

        expect($e->dailyAiMessages)->toBe(0)->and($e->historyDays)->toBe(0)->and($e->planCode)->toBeNull();
    })->with(['none', 'expired', 'suspended', 'unknown plan']);

    it('keeps null (unlimited) distinct from a missing key (misconfiguration blocks)', function () {
        config(['plans.broken' => ['label' => 'Rusak']]); // tanpa daily_ai_messages
        [, $t] = pxTenant('broken');

        expect(Entitlements::forTenant($t)->dailyAiMessages)->toBe(0)
            ->and(Entitlements::forPlan('enterprise')->dailyAiMessages)->toBeNull();
    });

    it('lists all plans and formats prices in rupiah', function () {
        expect(array_keys(Entitlements::allPlans()))->toBe(['small', 'medium', 'enterprise'])
            ->and(Entitlements::forPlan('small')->priceLabel())->toBe('Rp250.000')
            ->and(Entitlements::forPlan('medium')->priceLabel())->toBe('Rp500.000')
            ->and(Entitlements::forPlan('enterprise')->priceLabel())->toBe('Hubungi kami')
            ->and(Entitlements::forPlan('platinum'))->toBeNull()
            ->and(Entitlements::rupiah(1500000))->toBe('Rp1.500.000')->and(Entitlements::rupiah(null))->toBeNull();
    });

    it('computes the history window start', function () {
        expect(Entitlements::forPlan('small')->historySince()->isSameDay(now()->subDays(7)))->toBeTrue()
            ->and(Entitlements::forPlan('medium')->historySince())->toBeNull();
    });

    it('is what the quota manager uses', function () {
        [, $t] = pxTenant('medium');

        expect(app(\App\Services\Usage\QuotaManager::class)->limitFor($t))->toBe(1000);
    });
});

// ================= Harga pada langganan =================
describe('subscription price snapshot', function () {
    it('stores the plan price at activation and audits it', function () {
        [, $t] = pxTenant(null);

        $s = app(SubscriptionService::class)->activate($t, 'medium', 30);

        expect((int) $s->price_idr)->toBe(500000)
            ->and(AuditLog::where('action', 'subscription.activated')->sole()->after['price_idr'])->toBe(500000);
    });

    it('keeps an agreed discount and allows a free period', function () {
        [, $t] = pxTenant(null);

        expect((int) app(SubscriptionService::class)->activate($t, 'medium', 30, null, 400000)->price_idr)->toBe(400000)
            ->and((int) app(SubscriptionService::class)->activate($t, 'small', 30, null, 0)->price_idr)->toBe(0);
    });

    it('rejects a negative price and records no price for quote-based plans', function () {
        [, $t] = pxTenant(null);

        expect(fn () => app(SubscriptionService::class)->activate($t, 'small', 30, null, -1))->toThrow(InvalidArgumentException::class);
        expect(app(SubscriptionService::class)->activate($t, 'enterprise', 30)->price_idr)->toBeNull();
    });

    it('is not rewritten when the plan price changes later (old invoices stay correct)', function () {
        [, $t] = pxTenant(null);
        $s = app(SubscriptionService::class)->activate($t, 'small', 30);

        config(['plans.small.price_idr' => 300000]);

        expect((int) $s->fresh()->price_idr)->toBe(250000);
        expect((int) app(SubscriptionService::class)->activate($t, 'small', 30)->price_idr)->toBe(300000);
    });

    it('is set by the CLI command from the plan', function () {
        [, $t] = pxTenant(null);

        $this->artisan('subscription:activate', ['tenant' => $t->slug, 'plan' => 'small', '--days' => 30])->assertSuccessful();

        expect((int) $t->subscriptions()->sole()->price_idr)->toBe(250000);
    });
});

// ================= Panel admin =================
describe('admin panel pricing', function () {
    beforeEach(fn () => Filament::setCurrentPanel(Filament::getPanel('admin')));

    it('pre-fills price and duration from the selected plan', function () {
        [, $t] = pxTenant(null);
        $admin = User::factory()->platformAdmin()->create();

        Livewire::actingAs($admin)->test(ViewTenant::class, ['record' => $t->getKey()])
            ->mountAction('activateSubscription')
            ->assertActionDataSet(['plan' => 'small', 'price' => 250000, 'days' => 30])
            ->set('mountedActions.0.data.plan', 'medium')
            ->assertActionDataSet(['plan' => 'medium', 'price' => 500000, 'days' => 30]);
    });

    it('activates with the plan price by default and with an overridden discount price', function () {
        [, $t] = pxTenant(null);
        $admin = User::factory()->platformAdmin()->create();
        $page = fn () => Livewire::actingAs($admin)->test(ViewTenant::class, ['record' => $t->getKey()]);

        $page()->callAction('activateSubscription', ['plan' => 'small', 'price' => null, 'days' => 30])->assertHasNoActionErrors();
        expect((int) $t->subscriptions()->latest('id')->first()->price_idr)->toBe(250000);

        $page()->callAction('activateSubscription', ['plan' => 'medium', 'price' => 450000, 'days' => 30, 'notes' => 'Diskon pelanggan awal'])->assertHasNoActionErrors();
        $s = $t->subscriptions()->latest('id')->first();
        expect((int) $s->price_idr)->toBe(450000)->and($s->plan_code)->toBe('medium');
    });

    it('shows the agreed price in the subscription list', function () {
        [, $t] = pxTenant(null);
        $admin = User::factory()->platformAdmin()->create();
        $s = app(SubscriptionService::class)->activate($t, 'medium', 30);

        Livewire::actingAs($admin)->test(ListSubscriptions::class)->assertCanSeeTableRecords([$s])->assertSee('Rp500.000');
    });
});

// ================= Halaman langganan tenant =================
describe('subscription page', function () {
    it('shows every plan with price and features, highlighting the current one', function () {
        [$user] = pxTenant('small');

        $this->actingAs($user)->get('/app/subscription')->assertOk()
            ->assertSee('Rp250.000')->assertSee('Rp500.000')->assertSee('Hubungi kami')
            ->assertSee('100 pesan AI per hari')->assertSee('1.000 pesan AI per hari')->assertSee('tanpa batas keras')
            ->assertSee('7 hari terakhir')->assertSee('seluruh 30 hari')
            ->assertSee('Paket Anda')->assertSee('dihapus otomatis dari sistem setelah 30 hari');
    });

    it('shows the price list and how to subscribe to workspaces without a subscription', function () {
        [$user] = pxTenant(null);

        $this->actingAs($user)->get('/app/subscription')->assertOk()
            ->assertSee('Belum berlangganan')->assertSee('Cara berlangganan')->assertSee('Rp250.000')->assertDontSee('Paket Anda');
    });
});

// ================= Jendela riwayat percakapan =================
describe('conversation history window', function () {
    it('limits Small to the last 7 days and hides older conversations', function () {
        [$user, $t] = pxTenant('small');
        pxConversation($t, 'Baru', 2);
        pxConversation($t, 'Lama', 20);

        Livewire::actingAs($user)->test(ConversationHistory::class)
            ->assertSee('Baru')->assertDontSee('Lama')
            ->assertSee('7 hari terakhir')->assertSee('Lihat paket');
    });

    it('lets Medium and Enterprise see the whole retention period without a banner', function (string $plan) {
        [$user, $t] = pxTenant($plan);
        pxConversation($t, 'Baru', 2);
        pxConversation($t, 'Lama', 20);

        Livewire::actingAs($user)->test(ConversationHistory::class)
            ->assertSee('Baru')->assertSee('Lama')->assertDontSee('menampilkan riwayat');
    })->with(['medium', 'enterprise']);

    it('cannot open a conversation outside the window by id or query string', function () {
        [$user, $t] = pxTenant('small');
        $old = pxConversation($t, 'Lama', 20);

        expect(fn () => Livewire::actingAs($user)->test(ConversationHistory::class)->call('select', $old->id))->toThrow(ModelNotFoundException::class);
        expect(fn () => Livewire::withQueryParams(['c' => $old->id])->actingAs($user)->test(ConversationHistory::class))->toThrow(ModelNotFoundException::class);
    });

    it('hides messages older than the window inside a visible conversation', function () {
        [$user, $t] = pxTenant('small');
        $conv = pxConversation($t, 'Aktif', 1);
        app(CurrentTenant::class)->runAs($t, function () use ($conv) {
            $m = Message::create(['conversation_id' => $conv->id, 'direction' => 'inbound', 'external_message_id' => 'old', 'body' => 'PESAN SANGAT LAMA', 'status' => 'answered']);
            \Illuminate\Support\Facades\DB::table('messages')->where('id', $m->id)->update(['created_at' => now()->subDays(15)]);
        });

        Livewire::actingAs($user)->test(ConversationHistory::class)->call('select', $conv->id)
            ->assertSee('pesan dari Aktif')->assertDontSee('PESAN SANGAT LAMA');
    });

    it('uses created_at for conversations without a last message time', function () {
        [$user, $t] = pxTenant('small');
        $c = pxConversation($t, 'TanpaWaktu', 30);
        \Illuminate\Support\Facades\DB::table('conversations')->where('id', $c->id)->update(['last_message_at' => null, 'created_at' => now()->subDays(1)]);

        Livewire::actingAs($user)->test(ConversationHistory::class)->assertSee('TanpaWaktu');
    });
});
