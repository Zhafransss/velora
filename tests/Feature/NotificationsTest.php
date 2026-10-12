<?php

use App\Livewire\Tenant\NotificationsInbox;
use App\Models\Bot;
use App\Models\Channel;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\TenantMember;
use App\Models\TenantNotification;
use App\Models\User;
use App\Services\Notifications\TenantNotifier;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;

const NT_SECRET = 'whsec-notif';

beforeEach(function () {
    config([
        'whatsapp.webhook_secret' => NT_SECRET, 'whatsapp.connector_url' => 'http://connector.test', 'whatsapp.connector_token' => 'tok',
        'whatsapp.send_max_attempts' => 2, 'whatsapp.typing_indicator' => false,
        'ai.order' => ['gemini'], 'ai.retries' => 0, 'ai.retry_delay_ms' => 0,
        'ai.providers.gemini.api_key' => 'K', 'ai.providers.gemini.base_url' => 'https://gemini.test',
    ]);
});

/** @return array{0: Tenant, 1: Bot, 2: Channel, 3: User} konteks tenant dibersihkan setelahnya */
function ntSetup(array $o = []): array
{
    $t = Tenant::factory()->create($o['tenant'] ?? []);
    ($o['subscribe'] ?? true) && Subscription::factory()->create(['tenant_id' => $t->id, 'plan_code' => $o['plan'] ?? 'small']);
    $user = User::factory()->create();
    TenantMember::create(['tenant_id' => $t->id, 'user_id' => $user->id, 'role' => $o['role'] ?? 'owner']);

    [$bot, $ch] = app(CurrentTenant::class)->runAs($t, function () use ($t, $o) {
        $bot = Bot::factory()->create(array_merge(['tenant_id' => $t->id, 'status' => 'active'], $o['bot'] ?? []));

        return [$bot, Channel::factory()->create(array_merge(['tenant_id' => $t->id, 'bot_id' => $bot->id], $o['channel'] ?? []))];
    });

    return [$t, $bot, $ch, $user];
}

function ntHttp($gemini = null, $connector = null): void
{
    Http::swap(new HttpFactory());
    Http::fake([
        'gemini.test/*' => $gemini ?? Http::response(['candidates' => [['content' => ['parts' => [['text' => 'Halo kak']]], 'finishReason' => 'STOP']]]),
        'connector.test/*' => $connector ?? Http::response(['channelId' => 'x', 'messageId' => 'W' . uniqid(), 'to' => 'x']),
    ]);
}

function ntPost(array $payload): void
{
    $body = json_encode($payload);
    $ts = (string) time();
    test()->call('POST', '/webhooks/whatsapp', [], [], [], [
        'CONTENT_TYPE' => 'application/json', 'HTTP_X_VELORA_TIMESTAMP' => $ts, 'HTTP_X_VELORA_SIGNATURE' => hash_hmac('sha256', $ts . '.' . $body, NT_SECRET),
    ], $body)->assertSuccessful();
}

function ntInbound(Channel $ch, string $text = 'halo', string $id = 'M1'): void
{
    ntPost(['type' => 'message.inbound', 'channelId' => $ch->public_id, 'externalMessageId' => $id, 'from' => '6281@s.whatsapp.net', 'text' => $text, 'timestamp' => time()]);
}

/** Pada Livewire::test() middleware tidak berjalan; tetapkan konteks tenant seperti yang dilakukan SetCurrentTenant. */
function ntAs(Tenant $t): void
{
    app(CurrentTenant::class)->set($t);
}

function ntNotes(Tenant $t)
{
    return DB::table('tenant_notifications')->where('tenant_id', $t->id)->orderBy('id')->get();
}

// ================= Layanan =================
describe('TenantNotifier', function () {
    it('creates a notification and merges repeats of the same event into one with a count', function () {
        $t = Tenant::factory()->create();
        $n = app(TenantNotifier::class);

        expect($n->notify($t, 'x', 'warning', 'Judul', 'isi', 'app.bot', 'k1'))->toBeTrue()
            ->and($n->notify($t, 'x', 'warning', 'Judul', 'isi', 'app.bot', 'k1'))->toBeFalse()
            ->and($n->notify($t, 'x', 'warning', 'Judul', 'isi', 'app.bot', 'k1'))->toBeFalse();

        $rows = ntNotes($t);
        expect($rows)->toHaveCount(1)->and($rows[0]->occurrences)->toBe(3);
    });

    it('does not increment when asked not to (idempotent reminders), and never dedupes null keys', function () {
        $t = Tenant::factory()->create();
        $n = app(TenantNotifier::class);

        $n->notify($t, 'x', 'info', 'A', null, null, 'k', increment: false);
        $n->notify($t, 'x', 'info', 'A', null, null, 'k', increment: false);
        $n->notify($t, 'y', 'info', 'B', null, null);
        $n->notify($t, 'y', 'info', 'B', null, null);

        expect(ntNotes($t)->pluck('occurrences', 'dedupe_key')->all())->toMatchArray(['k' => 1])->and(ntNotes($t))->toHaveCount(3);
    });

    it('keeps the same dedupe key separate per tenant', function () {
        [$a, $b] = [Tenant::factory()->create(), Tenant::factory()->create()];
        $n = app(TenantNotifier::class);

        $n->notify($a, 'x', 'info', 'A', null, null, 'same');
        $n->notify($b, 'x', 'info', 'B', null, null, 'same');

        expect(ntNotes($a))->toHaveCount(1)->and(ntNotes($b))->toHaveCount(1);
    });

    it('never throws, so a notification problem cannot break message processing', function () {
        $t = Tenant::factory()->create();
        Schema::drop('tenant_notifications');
        Log::spy();

        app(TenantNotifier::class)->quotaExceeded($t);
        app(TenantNotifier::class)->aiFailed($t);

        Log::shouldHaveReceived('warning')->withArgs(fn ($m) => $m === 'tenant_notification.failed')->twice();
    });

    it('explains the quota in terms of the plan', function () {
        [$t] = ntSetup(['plan' => 'medium']);

        app(TenantNotifier::class)->quotaExceeded($t);

        $row = ntNotes($t)->sole();
        expect($row->severity)->toBe('critical')->and($row->title)->toBe('Kuota AI harian habis')
            ->and($row->body)->toContain('Paket Medium')->toContain('1.000 pesan per hari')->and($row->action_route)->toBe('app.subscription');
    });
});

// ================= Pemicu pada pipeline =================
describe('triggers', function () {
    it('notifies once per day when the daily quota runs out, counting missed messages', function () {
        config(['plans.small.daily_ai_messages' => 1]);
        ntHttp();
        [$t, , $ch] = ntSetup();

        ntInbound($ch, 'satu', 'A1');
        expect(ntNotes($t))->toHaveCount(0);

        ntInbound($ch, 'dua', 'A2');
        ntInbound($ch, 'tiga', 'A3');
        ntInbound($ch, 'empat', 'A4');

        $row = ntNotes($t)->sole();
        expect($row->type)->toBe('quota_exceeded')->and($row->severity)->toBe('critical')->and($row->occurrences)->toBe(3);
    });

    it('notifies when every AI provider fails, merged per hour', function () {
        ntHttp(gemini: Http::response('boom', 500));
        [$t, , $ch] = ntSetup();

        ntInbound($ch, 'a', 'B1');
        ntInbound($ch, 'b', 'B2');

        $row = ntNotes($t)->sole();
        expect($row->type)->toBe('ai_failed')->and($row->severity)->toBe('warning')->and($row->occurrences)->toBe(2)->and($row->action_route)->toBe('app.conversations');
    });

    it('does not notify when the AI answers normally', function () {
        ntHttp();
        [$t, , $ch] = ntSetup();

        ntInbound($ch);

        expect(ntNotes($t))->toHaveCount(0);
    });

    it('tells the owner when messages arrive but the bot is not active', function () {
        ntHttp();
        [$t, , $ch] = ntSetup(['bot' => ['status' => 'draft']]);

        ntInbound($ch, 'a', 'C1');
        ntInbound($ch, 'b', 'C2');

        $row = ntNotes($t)->sole();
        expect($row->type)->toBe('bot_inactive')->and($row->occurrences)->toBe(2)->and($row->action_route)->toBe('app.bot');
    });

    it('tells the owner when the subscription is inactive', function () {
        ntHttp();
        [$t, , $ch] = ntSetup(['subscribe' => false]);

        ntInbound($ch);

        $row = ntNotes($t)->sole();
        expect($row->type)->toBe('subscription_inactive')->and($row->severity)->toBe('critical');
    });

    it('stays silent for a suspended workspace', function () {
        ntHttp();
        [$t, , $ch] = ntSetup(['tenant' => ['status' => 'suspended']]);

        ntInbound($ch);

        expect(ntNotes($t))->toHaveCount(0);
    });

    it('notifies when a reply finally cannot be delivered, with a human reason', function () {
        ntHttp(connector: Http::response(['error' => ['code' => 'CHANNEL_NOT_CONNECTED']], 409));
        [$t, , $ch] = ntSetup();

        ntInbound($ch);

        $row = ntNotes($t)->sole();
        expect($row->type)->toBe('send_failed')->and($row->body)->toContain('WhatsApp sedang tidak terhubung')->and($row->action_route)->toBe('app.whatsapp');
    });

    it('never puts customer message content into a notification', function () {
        config(['plans.small.daily_ai_messages' => 1]);
        ntHttp(connector: Http::response([], 409));
        [$t, , $ch] = ntSetup(['bot' => ['status' => 'draft']]);
        ntInbound($ch, 'RAHASIA-PELANGGAN-123 nomor 0812-3456', 'D1');
        app(CurrentTenant::class)->runAs($t, fn () => Bot::query()->update(['status' => 'active']));
        ntInbound($ch, 'RAHASIA-PELANGGAN-123 lagi', 'D2');
        ntInbound($ch, 'RAHASIA-PELANGGAN-123 lagi', 'D3');

        expect(ntNotes($t)->count())->toBeGreaterThan(0)->and(json_encode(ntNotes($t)))->not->toContain('RAHASIA')->not->toContain('0812');
    });

    describe('WhatsApp channel status', function () {
        it('notifies when a connected channel is logged out or fails permanently, once per state', function (string $state, string $needle) {
            [$t, , $ch] = ntSetup();

            ntPost(['type' => 'channel.status', 'channelId' => $ch->public_id, 'state' => $state]);
            ntPost(['type' => 'channel.status', 'channelId' => $ch->public_id, 'state' => $state]); // pengulangan: tidak ada notifikasi baru

            $row = ntNotes($t)->sole();
            expect($row->type)->toBe('channel_down')->and($row->severity)->toBe('critical')->and($row->body)->toContain($needle)
                ->and($row->occurrences)->toBe(1)->and($row->action_route)->toBe('app.whatsapp');
        })->with([['logged_out', 'terlepas dari WhatsApp'], ['failed', 'gagal dipulihkan']]);

        it('stays silent for transient or healthy states', function (string $state) {
            [$t, , $ch] = ntSetup();

            ntPost(['type' => 'channel.status', 'channelId' => $ch->public_id, 'state' => $state]);

            expect(ntNotes($t))->toHaveCount(0);
        })->with(['reconnecting', 'connected', 'starting', 'qr', 'stopped']);
    });
});

// ================= Pengingat langganan =================
describe('notifications:subscription-expiry', function () {
    it('reminds at the 7, 3 and 1 day thresholds and not earlier', function () {
        [$t7] = ntSetup(['subscribe' => false]);
        [$t3] = ntSetup(['subscribe' => false]);
        [$t1] = ntSetup(['subscribe' => false]);
        [$far] = ntSetup(['subscribe' => false]);
        foreach ([[$t7, 6], [$t3, 2.5], [$t1, 0.5], [$far, 10]] as [$t, $days]) {
            Subscription::factory()->create(['tenant_id' => $t->id, 'current_period_end' => now()->addMinutes((int) ($days * 1440))]);
        }

        $this->artisan('notifications:subscription-expiry')->expectsOutputToContain('3 langganan akan berakhir')->assertSuccessful();

        expect(ntNotes($t7)->sole()->severity)->toBe('warning')->and(ntNotes($t7)->sole()->dedupe_key)->toEndWith(':7')
            ->and(ntNotes($t3)->sole()->dedupe_key)->toEndWith(':3')
            ->and(ntNotes($t1)->sole()->severity)->toBe('critical')->and(ntNotes($t1)->sole()->dedupe_key)->toEndWith(':1')
            ->and(ntNotes($far))->toHaveCount(0);
        expect(ntNotes($t7)->sole()->title)->toContain('dalam')->and(ntNotes($t7)->sole()->body)->toContain('SMALL');
    });

    it('is idempotent: running it twice does not duplicate or inflate the count', function () {
        [$t] = ntSetup(['subscribe' => false]);
        Subscription::factory()->create(['tenant_id' => $t->id, 'current_period_end' => now()->addDays(2)]);

        $this->artisan('notifications:subscription-expiry')->assertSuccessful();
        $this->artisan('notifications:subscription-expiry')->assertSuccessful();

        expect(ntNotes($t)->sole()->occurrences)->toBe(1);
    });

    it('sends one reminder per threshold as the date approaches', function () {
        [$t] = ntSetup(['subscribe' => false]);
        $s = Subscription::factory()->create(['tenant_id' => $t->id, 'current_period_end' => now()->addDays(6)]);

        $this->artisan('notifications:subscription-expiry');
        $s->update(['current_period_end' => now()->addDays(2)]);
        $this->artisan('notifications:subscription-expiry');
        $s->update(['current_period_end' => now()->addHours(10)]);
        $this->artisan('notifications:subscription-expiry');

        expect(ntNotes($t)->pluck('dedupe_key')->map(fn ($k) => substr($k, -1))->all())->toBe(['7', '3', '1']);
    });

    it('reports a recently lapsed subscription once, unless it was replaced', function () {
        [$lapsed] = ntSetup(['subscribe' => false]);
        Subscription::factory()->create(['tenant_id' => $lapsed->id, 'current_period_start' => now()->subDays(40), 'current_period_end' => now()->subDays(2)]);
        [$renewed] = ntSetup(['subscribe' => false]);
        Subscription::factory()->create(['tenant_id' => $renewed->id, 'current_period_start' => now()->subDays(40), 'current_period_end' => now()->subDays(2)]);
        Subscription::factory()->create(['tenant_id' => $renewed->id]); // sudah diperpanjang
        [$longAgo] = ntSetup(['subscribe' => false]);
        Subscription::factory()->create(['tenant_id' => $longAgo->id, 'current_period_end' => now()->subDays(30)]);
        [$canceled] = ntSetup(['subscribe' => false]);
        Subscription::factory()->status('canceled')->create(['tenant_id' => $canceled->id, 'current_period_end' => now()->subDays(2)]);

        $this->artisan('notifications:subscription-expiry')->expectsOutputToContain('1 sudah berakhir')->assertSuccessful();
        $this->artisan('notifications:subscription-expiry')->assertSuccessful();

        $row = ntNotes($lapsed)->sole();
        expect($row->type)->toBe('subscription_expired')->and($row->severity)->toBe('critical')->and($row->occurrences)->toBe(1)
            ->and(ntNotes($renewed))->toHaveCount(0)->and(ntNotes($longAgo))->toHaveCount(0)->and(ntNotes($canceled))->toHaveCount(0);
    });

    it('is scheduled daily', function () {
        $events = collect(app(Schedule::class)->events())->filter(fn ($e) => str_contains($e->command, 'notifications:subscription-expiry'));

        expect($events)->toHaveCount(1)->and($events->first()->expression)->toBe('0 8 * * *');
    });
});

// ================= Kotak masuk =================
describe('inbox page', function () {
    it('opens even without an active subscription so the owner can read the warning', function () {
        [$t, , , $user] = ntSetup(['subscribe' => false]);
        app(TenantNotifier::class)->subscriptionInactive($t);

        $this->actingAs($user)->get('/app/notifications')->assertOk()->assertSee('langganan tidak aktif')->assertSee('Buka');
        $this->actingAs($user)->get('/app/whatsapp')->assertRedirect(route('app.subscription')); // fitur lain tetap terkunci
    });

    it('requires a login', function () {
        $this->get('/app/notifications')->assertRedirect(route('login'));
    });

    it('lists unread first, newest first, with counts, severity and a link to the relevant page', function () {
        [$t, , , $user] = ntSetup();
        $n = app(TenantNotifier::class);
        $n->notify($t, 'a', 'info', 'Lama dibaca', 'x', null, 'k1');
        DB::table('tenant_notifications')->where('dedupe_key', 'k1')->update(['read_at' => now()]);
        $n->notify($t, 'b', 'warning', 'Gagal beberapa kali', 'isi', 'app.whatsapp', 'k2');
        $n->notify($t, 'b', 'warning', 'Gagal beberapa kali', 'isi', 'app.whatsapp', 'k2');
        $n->notify($t, 'c', 'critical', 'Kritis baru', 'isi', 'app.subscription', 'k3');
        ntAs($t);

        Livewire::actingAs($user)->test(NotificationsInbox::class)
            ->assertSeeInOrder(['Kritis baru', 'Gagal beberapa kali', 'Lama dibaca'])
            ->assertSee('(2 kali)')->assertSee('Penting')->assertSee('Perhatian')
            ->assertSeeHtml('href="' . route('app.whatsapp') . '"');
    });

    it('filters to unread only and shows a friendly empty state', function () {
        [$t, , , $user] = ntSetup();
        app(TenantNotifier::class)->notify($t, 'a', 'info', 'Sudah dibaca', null, null, 'k1');
        DB::table('tenant_notifications')->update(['read_at' => now()]);
        ntAs($t);

        Livewire::actingAs($user)->test(NotificationsInbox::class)->assertSee('Sudah dibaca')
            ->set('onlyUnread', true)->assertDontSee('Sudah dibaca')->assertSee('yang belum dibaca');
    });

    it('lets owners and admins mark notifications as read', function (string $role) {
        [$t, , , $user] = ntSetup(['role' => $role]);
        $n = app(TenantNotifier::class);
        $n->notify($t, 'a', 'info', 'Satu', null, null, 'k1');
        $n->notify($t, 'a', 'info', 'Dua', null, null, 'k2');
        $id = ntNotes($t)->first()->id;
        ntAs($t);

        $c = Livewire::actingAs($user)->test(NotificationsInbox::class)->call('markRead', $id);
        expect(DB::table('tenant_notifications')->whereNull('read_at')->count())->toBe(1);

        $c->call('markAllRead');
        expect(DB::table('tenant_notifications')->whereNull('read_at')->count())->toBe(0);
    })->with(['owner', 'admin']);

    it('lets viewers read but not change state', function () {
        [$t, , , $user] = ntSetup(['role' => 'viewer']);
        app(TenantNotifier::class)->notify($t, 'a', 'info', 'Terbaca viewer', null, null, 'k1');
        $id = ntNotes($t)->sole()->id;
        ntAs($t);

        Livewire::actingAs($user)->test(NotificationsInbox::class)->assertSee('Terbaca viewer')->assertDontSee('Tandai dibaca');
        Livewire::actingAs($user)->test(NotificationsInbox::class)->call('markRead', $id)->assertForbidden();
        Livewire::actingAs($user)->test(NotificationsInbox::class)->call('markAllRead')->assertForbidden();

        expect(DB::table('tenant_notifications')->whereNull('read_at')->count())->toBe(1);
    });

    it('never shows or touches another workspace notifications', function () {
        [$a, , , $userA] = ntSetup();
        [$b] = ntSetup();
        app(TenantNotifier::class)->notify($a, 'a', 'info', 'Milik A', null, null, 'k1');
        app(TenantNotifier::class)->notify($b, 'a', 'critical', 'RAHASIA MILIK B', null, null, 'k1');
        $bId = ntNotes($b)->sole()->id;
        ntAs($a);

        Livewire::actingAs($userA)->test(NotificationsInbox::class)->assertSee('Milik A')->assertDontSee('RAHASIA MILIK B');
        expect(fn () => Livewire::actingAs($userA)->test(NotificationsInbox::class)->call('markRead', $bId))->toThrow(ModelNotFoundException::class);
        expect(DB::table('tenant_notifications')->where('id', $bId)->value('read_at'))->toBeNull();
    });
});

// ================= Navigasi & dashboard =================
describe('badge and dashboard banner', function () {
    it('shows the unread count in the navigation and drops it after reading', function () {
        [$t, , , $user] = ntSetup();
        app(TenantNotifier::class)->notify($t, 'a', 'critical', 'Satu', null, null, 'k1');
        app(TenantNotifier::class)->notify($t, 'a', 'warning', 'Dua', null, null, 'k2');

        $this->actingAs($user)->get('/app/whatsapp')->assertSeeHtml('<span class="count">2</span>');

        DB::table('tenant_notifications')->update(['read_at' => now()]);
        $this->actingAs($user)->get('/app/whatsapp')->assertDontSeeHtml('class="count"');
    });

    it('caps the badge at 99+', function () {
        [$t, , , $user] = ntSetup();
        foreach (range(1, 105) as $i) {
            app(TenantNotifier::class)->notify($t, 'a', 'info', "N{$i}", null, null);
        }

        $this->actingAs($user)->get('/app/whatsapp')->assertSeeHtml('<span class="count">99+</span>');
    });

    it('shows at most three unread critical/warning alerts on the dashboard, most severe first', function () {
        [$t, , , $user] = ntSetup();
        $n = app(TenantNotifier::class);
        $n->notify($t, 'a', 'warning', 'Peringatan satu', null, null);
        $n->notify($t, 'a', 'warning', 'Peringatan dua', null, null);
        $n->notify($t, 'a', 'critical', 'Kritis satu', null, null);
        $n->notify($t, 'a', 'warning', 'Peringatan tiga', null, null);
        $n->notify($t, 'a', 'info', 'Hanya info', null, null);
        $n->notify($t, 'a', 'critical', 'Sudah dibaca', null, null, 'dibaca');
        DB::table('tenant_notifications')->where('dedupe_key', 'dibaca')->update(['read_at' => now()]);

        $res = $this->actingAs($user)->get('/app');

        $res->assertOk()->assertSee('Kritis satu')->assertSee('Lihat semua notifikasi')
            ->assertDontSee('Hanya info')->assertDontSee('Sudah dibaca');
        $html = $res->getContent();
        expect(substr_count($html, 'Peringatan') + substr_count($html, 'Kritis satu'))->toBe(3)
            ->and(strpos($html, 'Kritis satu'))->toBeLessThan(strpos($html, 'Peringatan'));
    });

    it('shows no banner and no link when there is nothing to report', function () {
        [, , , $user] = ntSetup();

        $this->actingAs($user)->get('/app')->assertOk()->assertDontSee('Lihat semua notifikasi');
    });
});

// ================= Retensi notifikasi =================
it('prunes notifications older than the notification retention period', function () {
    $t = Tenant::factory()->create();
    $n = app(TenantNotifier::class);
    $n->notify($t, 'a', 'info', 'Lama', null, null, 'k1');
    $n->notify($t, 'a', 'info', 'Baru', null, null, 'k2');
    DB::table('tenant_notifications')->where('dedupe_key', 'k1')->update(['created_at' => now()->subDays(45)]);

    $this->artisan('conversations:prune')->expectsOutputToContain('1 notifikasi lama dihapus')->assertSuccessful();

    expect(DB::table('tenant_notifications')->pluck('title')->all())->toBe(['Baru']);
});
