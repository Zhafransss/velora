<?php

use App\Jobs\ProcessInboundMessage;
use App\Jobs\SendOutboundMessage;
use App\Models\AiCall;
use App\Models\Bot;
use App\Models\Channel;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\Product;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Services\Usage\QuotaManager;
use App\Services\Whatsapp\WhatsAppChannelAdapter;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;

/*
 * Alur penuh webhook -> kuota -> prompt -> AI -> simpan -> kirim lewat konektor.
 * Antrean "sync" (phpunit.xml) sehingga seluruh rantai berjalan di dalam request webhook; HTTP ke Gemini dan konektor dipalsukan.
 */

const PL_SECRET = 'whsec-pipeline';

beforeEach(function () {
    config([
        'whatsapp.webhook_secret' => PL_SECRET,
        'whatsapp.connector_url' => 'http://connector.test',
        'whatsapp.connector_token' => 'conn-token',
        'whatsapp.send_max_attempts' => 4,
        'ai.order' => ['gemini'], 'ai.retries' => 0, 'ai.retry_delay_ms' => 0,
        'ai.providers.gemini.api_key' => 'K', 'ai.providers.gemini.base_url' => 'https://gemini.test',
        'plans.small.daily_ai_messages' => 100,
    ]);
    RateLimiter::clear('wa-send:1');
});

function plGemini(string $text = 'Kopi susu Rp25.000, kak.'): array
{
    return ['candidates' => [['content' => ['parts' => [['text' => $text]]], 'finishReason' => 'STOP']], 'usageMetadata' => ['promptTokenCount' => 300, 'candidatesTokenCount' => 15]];
}

/** Balasan konektor dengan id pesan unik per pengiriman (seperti WhatsApp sungguhan). */
function plSendOk(): Closure
{
    static $n = 0;

    // closure biasa dengan use (&$n): arrow function akan menyalin $n sehingga id tidak pernah naik
    return function () use (&$n) {
        return Http::response(['channelId' => 'x', 'messageId' => 'WAOUT-' . (++$n), 'to' => 'x@s.whatsapp.net']);
    };
}

/** Ganti seluruh stub HTTP. $connector bisa response tunggal atau Http::sequence(). */
function plHttp($gemini = null, $connector = null): void
{
    Http::swap(new HttpFactory());
    Http::fake([
        'gemini.test/*' => $gemini ?? Http::response(plGemini()),
        'connector.test/*' => $connector ?? plSendOk(),
    ]);
}

/** @return array{0: Tenant, 1: Bot, 2: Channel} */
function plSetup(array $o = []): array
{
    $t = Tenant::factory()->create(array_merge(['name' => 'Kopi Senja'], $o['tenant'] ?? []));
    ($o['subscribe'] ?? true) && Subscription::factory()->create(['tenant_id' => $t->id, 'plan_code' => $o['plan'] ?? 'small']);

    [$bot, $ch] = app(CurrentTenant::class)->runAs($t, function () use ($t, $o) {
        $bot = Bot::factory()->create(array_merge(['tenant_id' => $t->id, 'status' => 'active', 'fallback_message' => 'Maaf, hubungi kami langsung.'], $o['bot'] ?? []));
        Product::factory()->create(['tenant_id' => $t->id, 'name' => 'Kopi Susu', 'price' => 25000, 'stock_status' => 'in_stock']);

        return [$bot, Channel::factory()->create(array_merge(['tenant_id' => $t->id, 'bot_id' => $bot->id], $o['channel'] ?? []))];
    });

    return [$t, $bot, $ch];
}

function plIn(Channel $ch, string $text = 'Berapa harga kopi susu?', string $extId = 'WAMID-1', string $from = '6281111111111@s.whatsapp.net'): void
{
    $body = json_encode(['type' => 'message.inbound', 'channelId' => $ch->public_id, 'externalMessageId' => $extId, 'from' => $from, 'text' => $text, 'timestamp' => time()]);
    $ts = (string) time();

    test()->call('POST', '/webhooks/whatsapp', [], [], [], [
        'CONTENT_TYPE' => 'application/json', 'HTTP_X_VELORA_TIMESTAMP' => $ts,
        'HTTP_X_VELORA_SIGNATURE' => hash_hmac('sha256', $ts . '.' . $body, PL_SECRET),
    ], $body)->assertSuccessful();
}

/** Jalankan ProcessInboundMessage langsung; Queue::fake() juga mencegat dispatchSync sehingga tidak bisa dipakai. */
function plProcess(int $tenantId, int $messageId): void
{
    (new ProcessInboundMessage($tenantId, $messageId))->handle(
        app(QuotaManager::class), app(\App\Services\Ai\PromptBuilder::class), app(\App\Services\Ai\AiGateway::class), app(CurrentTenant::class),
    );
}

function plIn_(Tenant $t, Closure $fn)
{
    return app(CurrentTenant::class)->runAs($t, $fn);
}

function plMsgs(Tenant $t, string $direction): \Illuminate\Support\Collection
{
    return plIn_($t, fn () => Message::where('direction', $direction)->orderBy('id')->get());
}

function plAiCalls(): int
{
    return collect(Http::recorded())->filter(fn ($p) => str_contains($p[0]->url(), 'gemini.test'))->count();
}

function plSends(): \Illuminate\Support\Collection
{
    return collect(Http::recorded())->map(fn ($p) => $p[0])->filter(fn ($r) => str_contains($r->url(), 'connector.test') && str_ends_with($r->url(), '/send'))->values();
}

// ---- Jalur utama ----
describe('happy path', function () {
    it('answers a customer question from business data and delivers it through the same channel', function () {
        plHttp();
        [$t, $bot, $ch] = plSetup();

        plIn($ch);

        $in = plMsgs($t, 'inbound')->sole();
        $out = plMsgs($t, 'outbound')->sole();

        expect($in->status)->toBe('answered')->and($in->error_type)->toBeNull();
        expect($out->status)->toBe('sent')->and($out->source)->toBe('ai')->and($out->body)->toBe('Kopi susu Rp25.000, kak.')
            ->and($out->in_reply_to_id)->toBe($in->id)->and($out->external_message_id)->toStartWith('WAOUT-')
            ->and($out->sent_at)->not->toBeNull()->and($out->send_attempts)->toBe(1)
            ->and($out->provider)->toBe('gemini')->and($out->input_tokens)->toBe(300)->and($out->output_tokens)->toBe(15);

        // Prompt memuat data tenant + pesan pelanggan hanya di role user.
        Http::assertSent(function ($r) {
            if (! str_contains($r->url(), 'gemini.test')) {
                return false;
            }
            $b = $r->data();

            return str_contains($b['systemInstruction']['parts'][0]['text'], 'Rp25.000')
                && $b['contents'][0]['parts'][0]['text'] === 'Berapa harga kopi susu?';
        });

        // Pengiriman: lewat channel percakapan, ke jid pelanggan, dengan token.
        $send = plSends()->sole();
        expect($send->url())->toBe("http://connector.test/channels/{$ch->public_id}/send")
            ->and($send->data())->toBe(['to' => '6281111111111@s.whatsapp.net', 'text' => 'Kopi susu Rp25.000, kak.'])
            ->and($send->hasHeader('Authorization', 'Bearer conn-token'))->toBeTrue();

        // Kuota dan log teknis tercatat.
        $quota = app(QuotaManager::class);
        expect($quota->used($t))->toBe(1)->and($quota->reconcile($t)['consistent'])->toBeTrue()
            ->and(plIn_($t, fn () => AiCall::sole()->purpose))->toBe('chat');
    });

    it('carries the earlier conversation as context for the next message', function () {
        plHttp();
        [, , $ch] = plSetup();

        plIn($ch, 'Ada kopi susu?', 'M1');
        plIn($ch, 'Berapa harganya?', 'M2');

        $last = collect(Http::recorded())->map(fn ($p) => $p[0])->filter(fn ($r) => str_contains($r->url(), 'gemini.test'))->last();
        $contents = $last->data()['contents'];

        expect(array_column($contents, 'role'))->toBe(['user', 'model', 'user'])
            ->and($contents[0]['parts'][0]['text'])->toBe('Ada kopi susu?')
            ->and($contents[2]['parts'][0]['text'])->toBe('Berapa harganya?');
    });

    it('processes a redelivered message only once: one AI call, one reply, one unit (TC-IDEM-01)', function () {
        plHttp();
        [$t, , $ch] = plSetup();

        plIn($ch, extId: 'DUP-1');
        plIn($ch, extId: 'DUP-1');
        plIn($ch, extId: 'DUP-1');

        expect(plAiCalls())->toBe(1)->and(plSends())->toHaveCount(1)
            ->and(plMsgs($t, 'inbound'))->toHaveCount(1)->and(plMsgs($t, 'outbound'))->toHaveCount(1)
            ->and(app(QuotaManager::class)->used($t))->toBe(1);
    });
});

// ---- Gerbang: tidak ada AI, tidak ada kuota, tidak ada balasan ----
describe('gates before AI', function () {
    it('skips without AI, quota or reply', function (array $setup, string $reason) {
        plHttp();
        [$t, , $ch] = plSetup($setup);

        plIn($ch);

        $in = plMsgs($t, 'inbound')->sole();
        expect($in->status)->toBe('skipped')->and($in->error_type)->toBe($reason)
            ->and(plMsgs($t, 'outbound'))->toHaveCount(0)->and(plAiCalls())->toBe(0)->and(plSends())->toHaveCount(0)
            ->and(app(QuotaManager::class)->used($t))->toBe(0);
    })->with([
        'bot still draft' => [['bot' => ['status' => 'draft']], 'bot_not_active'],
        'bot paused' => [['bot' => ['status' => 'paused']], 'bot_not_active'],
        'no subscription' => [['subscribe' => false], 'no_subscription'],
        'tenant suspended' => [['tenant' => ['status' => 'suspended']], 'tenant_inactive'],
    ]);

    it('skips for an expired subscription', function () {
        plHttp();
        [$t, , $ch] = plSetup(['subscribe' => false]);
        Subscription::factory()->expiredPeriod()->create(['tenant_id' => $t->id]);

        plIn($ch);

        expect(plMsgs($t, 'inbound')->sole()->error_type)->toBe('no_subscription')->and(plAiCalls())->toBe(0);
    });
});

// ---- Kuota ----
describe('daily quota', function () {
    it('stops answering at the limit without calling AI or replying (TC-QUOTA-01)', function () {
        config(['plans.small.daily_ai_messages' => 2]);
        plHttp();
        [$t, , $ch] = plSetup();

        plIn($ch, 'satu', 'Q1');
        plIn($ch, 'dua', 'Q2');
        plIn($ch, 'tiga', 'Q3');
        plIn($ch, 'empat', 'Q4');

        $statuses = plMsgs($t, 'inbound')->pluck('status')->all();
        expect($statuses)->toBe(['answered', 'answered', 'quota_exceeded', 'quota_exceeded'])
            ->and(plMsgs($t, 'inbound')[2]->error_type)->toBe('daily_quota')
            ->and(plAiCalls())->toBe(2)->and(plSends())->toHaveCount(2)->and(plMsgs($t, 'outbound'))->toHaveCount(2)
            ->and(app(QuotaManager::class)->used($t))->toBe(2);
    });

    it('keeps tenants separate: one tenant exhausting its quota does not affect another', function () {
        config(['plans.small.daily_ai_messages' => 1]);
        plHttp();
        [$a, , $chA] = plSetup();
        [$b, , $chB] = plSetup();

        plIn($chA, 'a1', 'A1');
        plIn($chA, 'a2', 'A2');
        plIn($chB, 'b1', 'B1');

        expect(plMsgs($a, 'inbound')->pluck('status')->all())->toBe(['answered', 'quota_exceeded'])
            ->and(plMsgs($b, 'inbound')->pluck('status')->all())->toBe(['answered']);
    });

    it('returns the unit and sends the bot fallback when every AI provider fails', function () {
        plHttp(gemini: Http::response('boom', 500));
        [$t, $bot, $ch] = plSetup();

        plIn($ch);

        $in = plMsgs($t, 'inbound')->sole();
        $out = plMsgs($t, 'outbound')->sole();

        expect($in->status)->toBe('failed')->and($in->error_type)->toBe('server_error')
            ->and($out->source)->toBe('fallback')->and($out->body)->toBe('Maaf, hubungi kami langsung.')->and($out->status)->toBe('sent')
            ->and($out->provider)->toBeNull()
            ->and(app(QuotaManager::class)->used($t))->toBe(0)
            ->and(app(QuotaManager::class)->reconcile($t)['consistent'])->toBeTrue();

        // Ledger mencatat reserve lalu release (audit), bukan menghapus riwayat.
        expect(DB::table('usage_events')->where('tenant_id', $t->id)->orderBy('id')->pluck('type')->all())->toBe(['reserve', 'release']);
    });
});

// ---- Pengiriman (3.4) ----
describe('outbound delivery', function () {
    it('retries a certain-not-sent failure without calling the AI again (FR-012)', function () {
        plHttp(connector: Http::sequence()
            ->push(['error' => ['code' => 'CHANNEL_NOT_CONNECTED']], 409)
            ->push(['error' => ['code' => 'CHANNEL_NOT_CONNECTED']], 409)
            ->push(['channelId' => 'x', 'messageId' => 'WAOUT-OK', 'to' => 'x']));
        [$t, , $ch] = plSetup();

        plIn($ch);

        $out = plMsgs($t, 'outbound')->sole();
        expect($out->status)->toBe('sent')->and($out->send_attempts)->toBe(3)->and($out->external_message_id)->toBe('WAOUT-OK')
            ->and(plAiCalls())->toBe(1)->and(plSends())->toHaveCount(3)
            ->and(app(QuotaManager::class)->used($t))->toBe(1);
    });

    it('marks the reply failed after the attempt limit while the AI answer stays stored', function () {
        plHttp(connector: Http::response(['error' => ['code' => 'CHANNEL_NOT_CONNECTED']], 409));
        [$t, , $ch] = plSetup();

        plIn($ch);

        $in = plMsgs($t, 'inbound')->sole();
        $out = plMsgs($t, 'outbound')->sole();

        // "AI selesai" dan "terkirim" adalah status terpisah (FR-011).
        expect($in->status)->toBe('answered')->and($out->status)->toBe('failed')->and($out->error_type)->toBe('channel_not_connected')
            ->and($out->body)->toBe('Kopi susu Rp25.000, kak.')->and($out->send_attempts)->toBe(4)
            ->and(plAiCalls())->toBe(1)->and(plSends())->toHaveCount(4);
    });

    it('never retries an ambiguous timeout, to avoid a duplicate reply to the customer', function () {
        plHttp(connector: fn () => throw new ConnectionException('cURL error 28: Operation timed out'));
        [$t, , $ch] = plSetup();

        plIn($ch);

        $out = plMsgs($t, 'outbound')->sole();
        expect($out->status)->toBe('failed')->and($out->error_type)->toBe('send_timeout_unknown')->and($out->send_attempts)->toBe(1);
    });

    it('does not send while the channel is not connected and gives up after the limit', function () {
        plHttp();
        [$t, , $ch] = plSetup(['channel' => ['status' => 'reconnecting']]);

        plIn($ch);

        $out = plMsgs($t, 'outbound')->sole();
        expect($out->status)->toBe('failed')->and($out->error_type)->toBe('channel_not_connected')
            ->and(plSends())->toHaveCount(0);
    });

    // WA-MT-03 pada sisi Laravel
    it('always replies through the channel that owns the conversation', function () {
        plHttp();
        [$t, $bot, $chA] = plSetup();
        $chB = app(CurrentTenant::class)->runAs($t, fn () => Channel::factory()->create(['tenant_id' => $t->id, 'bot_id' => $bot->id, 'name' => 'WA Kedua']));

        plIn($chB, 'halo dari B', 'B-1', '6283333333333@s.whatsapp.net');
        plIn($chA, 'halo dari A', 'A-1', '6281111111111@s.whatsapp.net');

        $urls = plSends()->map(fn ($r) => [$r->url(), $r->data()['to']])->all();

        expect($urls)->toBe([
            ["http://connector.test/channels/{$chB->public_id}/send", '6283333333333@s.whatsapp.net'],
            ["http://connector.test/channels/{$chA->public_id}/send", '6281111111111@s.whatsapp.net'],
        ]);
    });

    it('replies to the LID jid the customer wrote from', function () {
        plHttp();
        [, , $ch] = plSetup();

        plIn($ch, from: '98765432101@lid');

        expect(plSends()->sole()->data()['to'])->toBe('98765432101@lid');
    });

    it('refuses to send a message that is not a reply to an inbound message (FR-009.7)', function () {
        plHttp();
        [$t, , $ch] = plSetup();
        $out = plIn_($t, function () use ($ch) {
            $c = Conversation::create(['bot_id' => $ch->bot_id, 'channel_id' => $ch->id, 'customer_external_id' => '62811@s.whatsapp.net']);

            return Message::create(['conversation_id' => $c->id, 'direction' => 'outbound', 'body' => 'Promo besar!', 'status' => 'pending', 'source' => 'ai']);
        });

        SendOutboundMessage::dispatchSync($t->id, $out->id);

        expect(plIn_($t, fn () => $out->fresh()->only(['status', 'error_type'])))->toBe(['status' => 'failed', 'error_type' => 'not_a_reply'])
            ->and(plSends())->toHaveCount(0);
    });

    it('refuses to send a reply that points at an inbound message of another conversation', function () {
        plHttp();
        [$t, , $ch] = plSetup();
        $out = plIn_($t, function () use ($ch) {
            $c1 = Conversation::create(['bot_id' => $ch->bot_id, 'channel_id' => $ch->id, 'customer_external_id' => '62811@s.whatsapp.net']);
            $c2 = Conversation::create(['bot_id' => $ch->bot_id, 'channel_id' => $ch->id, 'customer_external_id' => '62822@s.whatsapp.net']);
            $other = Message::create(['conversation_id' => $c2->id, 'direction' => 'inbound', 'external_message_id' => 'X', 'body' => 'hi', 'status' => 'received']);

            return Message::create(['conversation_id' => $c1->id, 'direction' => 'outbound', 'body' => 'salah alamat', 'status' => 'pending', 'in_reply_to_id' => $other->id]);
        });

        SendOutboundMessage::dispatchSync($t->id, $out->id);

        expect(plIn_($t, fn () => $out->fresh()->error_type))->toBe('not_a_reply')->and(plSends())->toHaveCount(0);
    });

    it('does not send twice when the job runs twice', function () {
        plHttp();
        [$t, , $ch] = plSetup();
        plIn($ch);
        $out = plMsgs($t, 'outbound')->sole();

        SendOutboundMessage::dispatchSync($t->id, $out->id);
        SendOutboundMessage::dispatchSync($t->id, $out->id);

        expect(plSends())->toHaveCount(1);
    });

    it('does not resend a message left in "sending" (ambiguous outcome)', function () {
        plHttp();
        [$t, , $ch] = plSetup();
        plIn($ch);
        $out = plMsgs($t, 'outbound')->sole();
        plIn_($t, fn () => Message::whereKey($out->id)->update(['status' => 'sending']));
        Http::swap(new HttpFactory());
        Http::fake();

        SendOutboundMessage::dispatchSync($t->id, $out->id);

        Http::assertNothingSent();
    });

    it('defers sends beyond the per-channel rate limit instead of dropping them (FR-009.7)', function () {
        config(['whatsapp.max_sends_per_minute' => 2]);
        plHttp();
        Queue::fake([SendOutboundMessage::class]); // pengiriman dijalankan manual di bawah
        [$t, , $ch] = plSetup();

        $ids = plIn_($t, function () use ($ch) {
            $c = Conversation::create(['bot_id' => $ch->bot_id, 'channel_id' => $ch->id, 'customer_external_id' => '62811@s.whatsapp.net']);
            $in = Message::create(['conversation_id' => $c->id, 'direction' => 'inbound', 'external_message_id' => 'R0', 'body' => 'hi', 'status' => 'answered']);

            return collect(range(1, 3))->map(fn ($i) => Message::create(['conversation_id' => $c->id, 'direction' => 'outbound', 'body' => "balasan {$i}", 'status' => 'pending', 'in_reply_to_id' => $in->id, 'source' => 'ai'])->id);
        });

        foreach ($ids as $id) {
            (new SendOutboundMessage($t->id, $id))->handle(app(WhatsAppChannelAdapter::class), app(CurrentTenant::class));
        }

        expect(plSends())->toHaveCount(2)
            ->and(plIn_($t, fn () => Message::whereKey($ids[2])->value('status')))->toBe('pending');
        Queue::assertPushed(SendOutboundMessage::class, fn ($j) => $j->messageId === $ids[2] && $j->delay > 0);
    });
});

// ---- Ketahanan job ----
describe('job resilience', function () {
    it('processes a received message only once even if the job is dispatched twice', function () {
        Queue::fake([ProcessInboundMessage::class]);
        plHttp();
        [$t, , $ch] = plSetup();
        plIn($ch);
        $in = plMsgs($t, 'inbound')->sole();

        plProcess($t->id, $in->id);
        plProcess($t->id, $in->id);

        expect(plAiCalls())->toBe(1)->and(app(QuotaManager::class)->used($t))->toBe(1);
    });

    it('never runs a job for a message of another tenant', function () {
        plHttp();
        Queue::fake([ProcessInboundMessage::class]);
        [$a, , $chA] = plSetup();
        [$b] = plSetup();
        plIn($chA);
        $inA = plMsgs($a, 'inbound')->sole();

        plProcess($b->id, $inA->id); // id tenant salah

        expect(plIn_($a, fn () => Message::whereKey($inA->id)->value('status')))->toBe('received')->and(plAiCalls())->toBe(0);
    });

    it('marks a crashed message failed and returns its unit', function () {
        plHttp();
        Queue::fake([ProcessInboundMessage::class]);
        [$t, , $ch] = plSetup();
        plIn($ch);
        $in = plMsgs($t, 'inbound')->sole();

        // Simulasikan: sudah diklaim + kuota direservasi, lalu proses mati.
        plIn_($t, fn () => Message::whereKey($in->id)->update(['status' => 'processing']));
        app(QuotaManager::class)->reserve($t, "msg:{$ch->public_id}:{$in->external_message_id}", 'chat', $in->id);
        expect(app(QuotaManager::class)->used($t))->toBe(1);

        (new ProcessInboundMessage($t->id, $in->id))->failed(new RuntimeException('boom'));

        expect(plIn_($t, fn () => Message::whereKey($in->id)->first()->only(['status', 'error_type'])))->toBe(['status' => 'failed', 'error_type' => 'exception'])
            ->and(app(QuotaManager::class)->used($t))->toBe(0);
    });

    it('skips an empty-after-cleaning message without charging quota', function () {
        plHttp();
        [$t, , $ch] = plSetup();

        plIn($ch, "\u{0007}\u{0000}  ");

        expect(plMsgs($t, 'inbound')->sole()->error_type)->toBe('empty_message')
            ->and(app(QuotaManager::class)->used($t))->toBe(0)->and(plAiCalls())->toBe(0);
    });
});

// ---- Isolasi ----
it('keeps conversations, messages and usage of different tenants apart end to end', function () {
    plHttp();
    [$a, , $chA] = plSetup();
    [$b, , $chB] = plSetup();

    plIn($chA, 'pesan untuk A', 'A1');
    plIn($chB, 'pesan untuk B', 'B1');

    expect(plIn_($a, fn () => Message::where('direction', 'inbound')->pluck('body')->all()))->toBe(['pesan untuk A'])
        ->and(plIn_($b, fn () => Message::where('direction', 'inbound')->pluck('body')->all()))->toBe(['pesan untuk B'])
        ->and(app(QuotaManager::class)->used($a))->toBe(1)->and(app(QuotaManager::class)->used($b))->toBe(1);

    // Prompt tenant A tidak memuat data/percakapan tenant B.
    $prompts = collect(Http::recorded())->map(fn ($p) => $p[0])->filter(fn ($r) => str_contains($r->url(), 'gemini.test'))
        ->map(fn ($r) => json_encode($r->data()))->values()->all();
    expect($prompts[0])->not->toContain('pesan untuk B')->and($prompts[1])->not->toContain('pesan untuk A');
});
