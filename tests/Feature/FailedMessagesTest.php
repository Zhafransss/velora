<?php

use App\Jobs\ProcessInboundMessage;
use App\Jobs\SendOutboundMessage;
use App\Livewire\Tenant\FailedMessages;
use App\Models\Bot;
use App\Models\Channel;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\TenantMember;
use App\Models\User;
use App\Services\Usage\QuotaManager;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

beforeEach(function () {
    config([
        'whatsapp.retry_window_hours' => 24, 'plans.small.daily_ai_messages' => 100, 'whatsapp.typing_indicator' => false,
        'ai.order' => ['gemini'], 'ai.retries' => 0, 'ai.retry_delay_ms' => 0,
        'ai.providers.gemini.api_key' => 'K', 'ai.providers.gemini.base_url' => 'https://gemini.test',
    ]);
    Queue::fake();
});

/** @return array{0: Tenant, 1: Channel, 2: Conversation, 3: User} */
function fmSetup(string $role = 'owner', string $channelStatus = 'connected'): array
{
    $t = Tenant::factory()->create();
    Subscription::factory()->create(['tenant_id' => $t->id, 'plan_code' => 'small']);
    $user = User::factory()->create();
    TenantMember::create(['tenant_id' => $t->id, 'user_id' => $user->id, 'role' => $role]);

    [$ch, $c] = app(CurrentTenant::class)->runAs($t, function () use ($t, $channelStatus) {
        $bot = Bot::factory()->create(['tenant_id' => $t->id, 'status' => 'active']);
        $ch = Channel::factory()->create(['tenant_id' => $t->id, 'bot_id' => $bot->id, 'status' => $channelStatus]);

        return [$ch, Conversation::create(['bot_id' => $bot->id, 'channel_id' => $ch->id, 'customer_external_id' => '6281234@s.whatsapp.net', 'customer_name' => 'Budi'])];
    });

    return [$t, $ch, $c, $user];
}

function fmMsg(Tenant $t, Conversation $c, string $dir, string $status, ?string $error = null, int $hoursAgo = 1, ?int $replyTo = null, string $body = 'isi'): Message
{
    static $n = 0;

    return app(CurrentTenant::class)->runAs($t, function () use ($c, $dir, $status, $error, $hoursAgo, $replyTo, $body, &$n) {
        $m = Message::create([
            'conversation_id' => $c->id, 'direction' => $dir, 'status' => $status, 'error_type' => $error, 'body' => $body,
            'in_reply_to_id' => $replyTo, 'send_attempts' => 3, 'external_message_id' => $dir === 'inbound' ? 'FM-' . (++$n) : null,
        ]);
        DB::table('messages')->where('id', $m->id)->update(['created_at' => now()->subHours($hoursAgo), 'updated_at' => now()->subHours($hoursAgo)]);

        return $m;
    });
}

function fmPage(Tenant $t, User $u)
{
    app(CurrentTenant::class)->set($t); // Livewire::test() tidak menjalankan middleware tenant

    return Livewire::actingAs($u)->test(FailedMessages::class);
}

function fmFresh(Message $m): Message
{
    return Message::withoutTenancy()->findOrFail($m->id);
}

describe('listing', function () {
    it('shows failed messages of the last 24h in plain Indonesian, hides old, sent and non-fixable ones', function () {
        [$t, , $c, $u] = fmSetup();
        $q = fmMsg($t, $c, 'inbound', 'answered', null, 1, null, 'Berapa harga kopi?');
        fmMsg($t, $c, 'outbound', 'failed', 'channel_not_connected', 1, $q->id, 'Kopi Rp25.000');
        fmMsg($t, $c, 'outbound', 'failed', 'connector_unreachable', 30);        // terlalu lama
        fmMsg($t, $c, 'outbound', 'failed', 'channel_revoked', 1);               // tidak bisa diperbaiki
        fmMsg($t, $c, 'outbound', 'sent');                                       // berhasil
        fmMsg($t, $c, 'inbound', 'failed', 'stuck_processing', 2, null, 'Pesan macet');

        fmPage($t, $u)
            ->assertSee('Budi')->assertSee('Berapa harga kopi?')->assertSee('Kopi Rp25.000')
            ->assertSee('WhatsApp sedang tidak terhubung saat balasan dikirim')
            ->assertSee('Pesan macet')->assertSee('Proses jawaban terhenti di tengah jalan')
            ->assertDontSee('Layanan WhatsApp tidak dapat dihubungi')
            ->assertDontSee('dicabut');
    });

    it('does not list an unanswered-looking inbound that already received a reply (e.g. fallback sent)', function () {
        [$t, , $c, $u] = fmSetup();
        $in = fmMsg($t, $c, 'inbound', 'failed', 'rate_limited', 1, null, 'Tanya sudah dibalas cadangan');
        fmMsg($t, $c, 'outbound', 'sent', null, 1, $in->id);

        fmPage($t, $u)->assertDontSee('Tanya sudah dibalas cadangan');
        app(CurrentTenant::class)->set($t);
        expect(FailedMessages::recentFailedQuery()->count())->toBe(0);
    });

    it('never shows another tenant\'s failures, nor test-chat messages', function () {
        [$a, , $ca, $ua] = fmSetup();
        [$b, , $cb] = fmSetup();
        fmMsg($b, $cb, 'inbound', 'failed', 'stale_expired', 1, null, 'RAHASIA TENANT B');
        $test = fmMsg($a, $ca, 'inbound', 'failed', 'stale_expired', 1, null, 'pesan uji');
        DB::table('messages')->where('id', $test->id)->update(['is_test' => true]);

        fmPage($a, $ua)->assertDontSee('RAHASIA TENANT B')->assertDontSee('pesan uji')->assertSee('Tidak ada pesan gagal');
    });

    it('shows an empty state when everything is fine', function () {
        [$t, , , $u] = fmSetup();

        fmPage($t, $u)->assertSee('Tidak ada pesan gagal');
    });

    it('is reachable from the navigation, with a count badge, for a subscribed workspace', function () {
        [$t, , $c, $u] = fmSetup();
        fmMsg($t, $c, 'outbound', 'failed', 'connector_unreachable', 1);

        $this->actingAs($u)->get(route('app.failed-messages'))->assertOk()->assertSee('Pesan Gagal')->assertSee('class="count">1<', false);
    });

    it('requires an active subscription like the other WhatsApp pages', function () {
        [$t, , , $u] = fmSetup();
        Subscription::where('tenant_id', $t->id)->delete();

        $this->actingAs($u)->get(route('app.failed-messages'))->assertRedirect();
    });
});

describe('retry outbound', function () {
    it('requeues a failed reply atomically, resets its attempts, and only once', function () {
        [$t, , $c, $u] = fmSetup();
        $m = fmMsg($t, $c, 'outbound', 'failed', 'channel_not_connected');

        fmPage($t, $u)->call('retry', $m->id)->assertSee('Dikirim ulang');

        Queue::assertPushed(SendOutboundMessage::class, fn ($j) => $j->messageId === $m->id && $j->tenantId === $t->id);
        $fresh = fmFresh($m);
        expect($fresh->status)->toBe('pending')->and($fresh->send_attempts)->toBe(0)->and($fresh->error_type)->toBeNull();

        // Klik kedua (atau tab lain) pada pesan yang sama: tidak ada pengiriman kedua.
        Queue::fake();
        expect(fn () => fmPage($t, $u)->call('retry', $m->id))->toThrow(ModelNotFoundException::class);
        Queue::assertNothingPushed();
    });

    it('really delivers through the connector when the queued job runs', function () {
        [$t, $ch, $c, $u] = fmSetup();
        $in = fmMsg($t, $c, 'inbound', 'answered');
        $m = fmMsg($t, $c, 'outbound', 'failed', 'connector_unreachable', 1, $in->id, 'Halo kak');
        Http::swap(new HttpFactory());
        Http::fake(['connector.test/*' => Http::response(['channelId' => 'x', 'messageId' => 'WA-RETRY-1', 'to' => 'x'])]);
        config(['whatsapp.connector_url' => 'http://connector.test', 'whatsapp.connector_token' => 't']);

        fmPage($t, $u)->call('retry', $m->id);
        (new SendOutboundMessage($t->id, $m->id))->handle(app(\App\Services\Whatsapp\WhatsAppChannelAdapter::class), app(CurrentTenant::class));

        expect(fmFresh($m)->status)->toBe('sent')->and(fmFresh($m)->external_message_id)->toBe('WA-RETRY-1');
        Http::assertSentCount(1);
    });

    it('asks for confirmation before re-sending an unconfirmed delivery (risk of a duplicate)', function () {
        [$t, , $c, $u] = fmSetup();
        $m = fmMsg($t, $c, 'outbound', 'failed', 'send_timeout_unknown');

        fmPage($t, $u)->assertSeeHtml('wire:confirm=')->assertSee('Berisiko terkirim ganda')
            ->assertSee('Pengiriman tidak terkonfirmasi');

        $normal = fmMsg($t, $c, 'outbound', 'failed', 'connector_unreachable');
        $html = fmPage($t, $u)->html();
        expect(substr_count($html, 'wire:confirm='))->toBe(1);
    });

    it('refuses to retry when WhatsApp is not connected, leaving the message failed', function () {
        [$t, , $c, $u] = fmSetup('owner', 'disconnected');
        $m = fmMsg($t, $c, 'outbound', 'failed', 'channel_not_connected');

        fmPage($t, $u)->call('retry', $m->id)->assertSee('WhatsApp belum terhubung');

        Queue::assertNothingPushed();
        expect(fmFresh($m)->status)->toBe('failed');
    });

    it('cannot retry a revoked-channel failure or one older than the window (404)', function () {
        [$t, , $c, $u] = fmSetup();
        $revoked = fmMsg($t, $c, 'outbound', 'failed', 'channel_revoked');
        $old = fmMsg($t, $c, 'outbound', 'failed', 'connector_unreachable', 30);

        foreach ([$revoked, $old] as $m) {
            expect(fn () => fmPage($t, $u)->call('retry', $m->id))->toThrow(ModelNotFoundException::class);
        }
        Queue::assertNothingPushed();
    });
});

describe('retry inbound', function () {
    it('re-processes an unanswered question and uses a NEW quota key so the AI call is counted', function () {
        [$t, $ch, $c, $u] = fmSetup();
        $m = fmMsg($t, $c, 'inbound', 'failed', 'stuck_processing', 2, null, 'Berapa harga?');
        $quota = app(QuotaManager::class);
        $firstKey = $m->quotaKey($ch->public_id);
        $quota->reserve($t, $firstKey, 'chat', $m->id);
        $quota->release($t, $firstKey, $m->id);
        expect($quota->used($t))->toBe(0);

        fmPage($t, $u)->call('retry', $m->id)->assertSee('Dikirim ulang');

        Queue::assertPushed(ProcessInboundMessage::class, fn ($j) => $j->messageId === $m->id);
        $fresh = fmFresh($m);
        expect($fresh->status)->toBe('received')->and($fresh->process_attempts)->toBe(1)->and($fresh->error_type)->toBeNull();

        // Kunci baru berbeda dari yang lama, sehingga reservasi dihitung (bukan DUPLICATE gratis).
        expect($fresh->quotaKey($ch->public_id))->not->toBe($firstKey);
        expect($quota->reserve($t, $fresh->quotaKey($ch->public_id), 'chat', $m->id)->granted())->toBeTrue()
            ->and($quota->used($t))->toBe(1);
    });

    it('end to end: the retried message is answered by the AI and charged exactly once more', function () {
        [$t, $ch, $c, $u] = fmSetup();
        $m = fmMsg($t, $c, 'inbound', 'failed', 'stuck_processing', 2, null, 'Berapa harga kopi?');
        Http::swap(new HttpFactory());
        Http::fake(['gemini.test/*' => Http::response(['candidates' => [['content' => ['parts' => [['text' => 'Kopi Rp25.000']]], 'finishReason' => 'STOP']]])]);

        fmPage($t, $u)->call('retry', $m->id);
        (new ProcessInboundMessage($t->id, $m->id))->handle(app(QuotaManager::class), app(\App\Services\Ai\PromptBuilder::class), app(\App\Services\Ai\AiGateway::class), app(CurrentTenant::class));

        expect(fmFresh($m)->status)->toBe('answered')->and(app(QuotaManager::class)->used($t))->toBe(1);
        $reply = Message::withoutTenancy()->where('in_reply_to_id', $m->id)->sole();
        expect($reply->body)->toBe('Kopi Rp25.000')->and($reply->status)->toBe('pending');
    });

    it('does not re-process a question that already has any reply', function () {
        [$t, , $c, $u] = fmSetup();
        $in = fmMsg($t, $c, 'inbound', 'failed', 'rate_limited');
        fmMsg($t, $c, 'outbound', 'sent', null, 1, $in->id);

        expect(fn () => fmPage($t, $u)->call('retry', $in->id))->toThrow(ModelNotFoundException::class);
        Queue::assertNothingPushed();
        expect(fmFresh($in)->process_attempts)->toBe(0);
    });
});

describe('authorization', function () {
    it('lets a viewer see failures but not retry them (403)', function () {
        [$t, , $c, $u] = fmSetup('viewer');
        $m = fmMsg($t, $c, 'outbound', 'failed', 'connector_unreachable');

        fmPage($t, $u)->assertSee('Hanya pemilik atau admin workspace')->assertDontSee('Kirim ulang balasan');
        fmPage($t, $u)->call('retry', $m->id)->assertForbidden();

        Queue::assertNothingPushed();
        expect(fmFresh($m)->status)->toBe('failed');
    });

    it('cannot retry a message of another tenant, even by guessing the id (404)', function () {
        [$a, , , $ua] = fmSetup();
        [$b, , $cb] = fmSetup();
        $theirs = fmMsg($b, $cb, 'outbound', 'failed', 'connector_unreachable');

        expect(fn () => fmPage($a, $ua)->call('retry', $theirs->id))->toThrow(ModelNotFoundException::class);

        Queue::assertNothingPushed();
        expect(fmFresh($theirs)->status)->toBe('failed');
    });

    it('is not exposed in the platform admin panel (admins cannot send WhatsApp messages)', function () {
        $resources = collect(glob(app_path('Filament/**/*.php')))->merge(glob(app_path('Filament/*/*/*.php')))->merge(glob(app_path('Filament/*/*.php')))->unique();
        $mentions = $resources->filter(fn ($f) => str_contains(file_get_contents($f), 'SendOutboundMessage') || str_contains(file_get_contents($f), 'FailedMessages'));

        expect($mentions)->toBeEmpty();
    });
});

describe('integration with the sweeper and notifications', function () {
    it('a message failed by the sweeper shows up here and can be retried', function () {
        [$t, , $c, $u] = fmSetup();
        $m = fmMsg($t, $c, 'outbound', 'sending', null, 1);
        DB::table('messages')->where('id', $m->id)->update(['updated_at' => now()->subMinutes(30)]);

        $this->artisan('messages:sweep-stuck')->assertSuccessful();
        expect(fmFresh($m)->error_type)->toBe('send_timeout_unknown');

        fmPage($t, $u)->assertSee('Pengiriman tidak terkonfirmasi')->call('retry', $m->id);
        expect(fmFresh($m)->status)->toBe('pending');
    });

    it('the send-failed notification now points to this page', function () {
        $t = Tenant::factory()->create();
        app(\App\Services\Notifications\TenantNotifier::class)->sendFailed($t, 'connector_unreachable');

        expect(DB::table('tenant_notifications')->where('tenant_id', $t->id)->value('action_route'))->toBe('app.failed-messages');
    });
});
