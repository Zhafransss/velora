<?php

use App\Jobs\ProcessInboundMessage;
use App\Models\Bot;
use App\Models\Channel;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\Tenant;
use App\Services\Whatsapp\BaileysConnectorAdapter;
use App\Services\Whatsapp\ChannelSendException as SendEx;
use App\Services\Whatsapp\WebhookSignature;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

const WA_SECRET = 'whsec-test-secret';

beforeEach(function () {
    config([
        'whatsapp.webhook_secret' => WA_SECRET,
        'whatsapp.webhook_tolerance_seconds' => 300,
        'whatsapp.connector_url' => 'http://connector.test',
        'whatsapp.connector_token' => 'conn-token-123',
    ]);
});

/** Tenant + bot + kanal; konteks tenant dibersihkan agar tes tidak "kebetulan" lolos karena konteks tertinggal. */
function waChannel(array $tenant = [], array $channel = []): array
{
    $t = Tenant::factory()->create($tenant);
    $ctx = app(CurrentTenant::class);
    $bot = $ctx->runAs($t, fn () => Bot::factory()->create(['tenant_id' => $t->id]));
    $ch = $ctx->runAs($t, fn () => Channel::factory()->create(['tenant_id' => $t->id, 'bot_id' => $bot->id] + $channel));

    return [$t, $bot, $ch];
}

function inTenant(Tenant $t, Closure $fn): mixed
{
    return app(CurrentTenant::class)->runAs($t, $fn);
}

function waInbound(Channel $ch, array $o = []): array
{
    return array_merge([
        'type' => 'message.inbound',
        'channelId' => $ch->public_id,
        'externalMessageId' => 'WAMID-1',
        'from' => '6281111111111@s.whatsapp.net',
        'fromAlt' => null,
        'pushName' => 'Budi',
        'text' => 'Halo kak',
        'timestamp' => time(),
    ], $o);
}

function waPost(array $payload, array $o = [])
{
    $body = $o['raw'] ?? json_encode($payload);
    $ts = $o['ts'] ?? (string) time();
    $sig = $o['sig'] ?? hash_hmac('sha256', $ts . '.' . $body, $o['secret'] ?? WA_SECRET);
    $server = ['CONTENT_TYPE' => 'application/json'];

    if (! ($o['noHeaders'] ?? false)) {
        $server += ['HTTP_X_VELORA_TIMESTAMP' => $ts, 'HTTP_X_VELORA_SIGNATURE' => $sig];
    }

    return test()->call('POST', '/webhooks/whatsapp', [], [], [], $server, $body);
}

// ---- WebhookSignature ----
describe('WebhookSignature', function () {
    it('accepts a correct signature and rejects every kind of tampering', function () {
        $s = new WebhookSignature('secret', 300);
        $body = '{"a":1}';
        $sig = $s->sign($body, '1000');

        expect($s->valid($body, '1000', $sig, 1000))->toBeTrue()
            ->and($s->valid($body, '1000', strtoupper($sig), 1000))->toBeTrue()          // hex tak peka huruf besar
            ->and($s->valid('{"a":2}', '1000', $sig, 1000))->toBeFalse()                 // body diubah
            ->and($s->valid($body, '1001', $sig, 1001))->toBeFalse()                     // timestamp diubah
            ->and($s->valid($body, '1000', 'abc', 1000))->toBeFalse()
            ->and($s->valid($body, null, $sig, 1000))->toBeFalse()
            ->and($s->valid($body, '1000', null, 1000))->toBeFalse()
            ->and($s->valid($body, '10x0', $sig, 1000))->toBeFalse();                    // timestamp non-angka
    });

    it('rejects stale and future timestamps (replay protection)', function () {
        $s = new WebhookSignature('secret', 300);
        $sig = fn ($ts) => $s->sign('b', (string) $ts);

        expect($s->valid('b', '1000', $sig(1000), 1300))->toBeTrue()
            ->and($s->valid('b', '1000', $sig(1000), 1301))->toBeFalse()
            ->and($s->valid('b', '1000', $sig(1000), 699))->toBeFalse();
    });

    it('fails closed without a configured secret', function (?string $secret) {
        $s = new WebhookSignature($secret, 300);

        expect($s->valid('b', '1000', hash_hmac('sha256', '1000.b', (string) $secret), 1000))->toBeFalse();
    })->with([[null], ['']]);
});

// ---- Endpoint: otentikasi ----
describe('webhook authentication', function () {
    beforeEach(fn () => Queue::fake());

    it('rejects requests without signature headers', function () {
        [, , $ch] = waChannel();

        waPost(waInbound($ch), ['noHeaders' => true])->assertUnauthorized();
        Queue::assertNothingPushed();
    });

    it('rejects a wrong secret, a tampered body and a replayed old request', function () {
        [, , $ch] = waChannel();
        $payload = waInbound($ch);

        waPost($payload, ['secret' => 'other-secret'])->assertUnauthorized();
        waPost($payload, ['sig' => hash_hmac('sha256', time() . '.' . json_encode(['x' => 1]), WA_SECRET)])->assertUnauthorized();
        waPost($payload, ['ts' => (string) (time() - 3600)])->assertUnauthorized();

        expect(Message::withoutTenancy()->count())->toBe(0);
        Queue::assertNothingPushed();
    });

    it('rejects everything when no secret is configured', function () {
        config(['whatsapp.webhook_secret' => null]);
        [, , $ch] = waChannel();

        waPost(waInbound($ch))->assertUnauthorized();
    });

    it('requires valid JSON and a type even with a valid signature', function () {
        waPost([], ['raw' => 'not json'])->assertStatus(422);
        waPost([], ['raw' => json_encode(['foo' => 'bar'])])->assertStatus(422);
        waPost(['type' => 'message.unknown'])->assertStatus(202)->assertJson(['status' => 'ignored']);
    });
});

// ---- Endpoint: pesan masuk ----
describe('inbound messages', function () {
    beforeEach(fn () => Queue::fake());

    it('stores the message under the tenant of the channel and queues processing', function () {
        [$t, $bot, $ch] = waChannel();

        waPost(waInbound($ch, ['fromAlt' => '6281111111111@s.whatsapp.net', 'from' => '123456@lid']))
            ->assertOk()->assertJson(['status' => 'accepted']);

        $m = inTenant($t, fn () => Message::with('conversation')->sole());
        expect($m->tenant_id)->toBe($t->id)->and($m->direction)->toBe('inbound')->and($m->status)->toBe('received')
            ->and($m->body)->toBe('Halo kak')->and($m->external_message_id)->toBe('WAMID-1')
            ->and($m->conversation->channel_id)->toBe($ch->id)->and($m->conversation->bot_id)->toBe($bot->id)
            ->and($m->conversation->customer_external_id)->toBe('123456@lid')
            ->and($m->conversation->customer_alt_id)->toBe('6281111111111@s.whatsapp.net')
            ->and($m->conversation->customer_name)->toBe('Budi');

        Queue::assertPushed(ProcessInboundMessage::class, fn ($j) => $j->tenantId === $t->id && $j->messageId === $m->id);
    });

    it('does not call any AI inside the webhook request (fast response, work is queued)', function () {
        Http::fake();
        [, , $ch] = waChannel();

        waPost(waInbound($ch))->assertOk();

        Http::assertNothingSent();
    });

    // TC-IDEM-01 / WA-MT-05
    it('ignores a redelivered message: one row, one job', function () {
        [$t, , $ch] = waChannel();

        waPost(waInbound($ch))->assertJson(['status' => 'accepted']);
        waPost(waInbound($ch))->assertOk()->assertJson(['status' => 'duplicate']);
        waPost(waInbound($ch))->assertOk()->assertJson(['status' => 'duplicate']);

        expect(inTenant($t, fn () => Message::count()))->toBe(1);
        Queue::assertPushed(ProcessInboundMessage::class, 1);
    });

    it('keeps one conversation per customer and refreshes the customer name', function () {
        [$t, , $ch] = waChannel();

        waPost(waInbound($ch, ['externalMessageId' => 'A', 'pushName' => 'Budi']));
        waPost(waInbound($ch, ['externalMessageId' => 'B', 'pushName' => 'Budi S.']));
        waPost(waInbound($ch, ['externalMessageId' => 'C', 'from' => '6282222222222@s.whatsapp.net']));

        $convos = inTenant($t, fn () => Conversation::orderBy('id')->get());
        expect($convos)->toHaveCount(2)->and($convos[0]->customer_name)->toBe('Budi S.')
            ->and(inTenant($t, fn () => Message::where('conversation_id', $convos[0]->id)->count()))->toBe(2);
    });

    it('allows the same WhatsApp message id on different channels', function () {
        [$ta, , $a] = waChannel();
        [$tb, , $b] = waChannel();

        waPost(waInbound($a, ['externalMessageId' => 'SAME']))->assertJson(['status' => 'accepted']);
        waPost(waInbound($b, ['externalMessageId' => 'SAME']))->assertJson(['status' => 'accepted']);

        expect(inTenant($ta, fn () => Message::count()))->toBe(1)->and(inTenant($tb, fn () => Message::count()))->toBe(1);
    });

    it('takes the tenant from the channel, never from the payload', function () {
        [$a, , $chA] = waChannel();
        [$b] = waChannel();

        waPost(waInbound($chA, ['tenantId' => $b->id, 'tenant_id' => $b->id]))->assertOk();

        expect(inTenant($a, fn () => Message::count()))->toBe(1)->and(inTenant($b, fn () => Message::count()))->toBe(0);
    });

    it('answers 404 for an unknown channel and ignores revoked ones', function () {
        [$t, , $ch] = waChannel(channel: ['status' => 'revoked']);

        waPost(waInbound($ch, ['channelId' => '01JXXXXXXXXXXXXXXXXXXXXXXX']))->assertNotFound();
        waPost(waInbound($ch))->assertOk()->assertJson(['status' => 'ignored']);

        expect(inTenant($t, fn () => Message::count()))->toBe(0);
        Queue::assertNothingPushed();
    });

    it('validates the payload', function (array $override) {
        [, , $ch] = waChannel();

        waPost(waInbound($ch, $override))->assertStatus(422);
        Queue::assertNothingPushed();
    })->with([
        'no text' => [['text' => '']],
        'bad jid' => [['from' => '../etc/passwd']],
        'group jid' => [['from' => '1203@g.us']],
        'bad alt' => [['fromAlt' => 'x@evil.com']],
        'bad channel id' => [['channelId' => '../../x']],
        'no message id' => [['externalMessageId' => '']],
        'long id' => [['externalMessageId' => str_repeat('a', 101)]],
        'bad timestamp' => [['timestamp' => 'kemarin']],
    ]);

    it('caps very long texts instead of failing', function () {
        [$t, , $ch] = waChannel();

        waPost(waInbound($ch, ['text' => str_repeat('a', 10000)]))->assertOk();

        expect(mb_strlen(inTenant($t, fn () => Message::sole()->body)))->toBe(4000);
    });
});

// ---- Endpoint: status kanal ----
describe('channel status events', function () {
    it('updates the channel status and account', function () {
        [$t, , $ch] = waChannel(channel: ['status' => 'created']);

        waPost(['type' => 'channel.status', 'channelId' => $ch->public_id, 'state' => 'connected', 'me' => ['id' => '6281234@s.whatsapp.net'], 'at' => now()->toIso8601String()])
            ->assertOk();

        $ch = inTenant($t, fn () => Channel::sole());
        expect($ch->status)->toBe('connected')->and($ch->external_account_id)->toBe('6281234@s.whatsapp.net')
            ->and($ch->last_status_at)->not->toBeNull();

        waPost(['type' => 'channel.status', 'channelId' => $ch->public_id, 'state' => 'reconnecting'])->assertOk();
        expect(inTenant($t, fn () => Channel::sole()->status))->toBe('reconnecting');
    });

    it('ignores unknown states and never reactivates a revoked channel', function () {
        [$t, , $ch] = waChannel(channel: ['status' => 'revoked']);

        waPost(['type' => 'channel.status', 'channelId' => $ch->public_id, 'state' => 'connected'])->assertJson(['status' => 'ignored']);
        expect(inTenant($t, fn () => Channel::sole()->status))->toBe('revoked');

        [$t2, , $ch2] = waChannel();
        waPost(['type' => 'channel.status', 'channelId' => $ch2->public_id, 'state' => 'hacked'])->assertJson(['status' => 'ignored']);
        expect(inTenant($t2, fn () => Channel::sole()->status))->toBe('connected');
    });

    it('returns 404 for unknown channels and 422 for malformed events', function () {
        waPost(['type' => 'channel.status', 'channelId' => 'tidak-ada', 'state' => 'connected'])->assertNotFound();
        waPost(['type' => 'channel.status', 'state' => 'connected'])->assertStatus(422);
    });
});

// ---- Model Channel ----
describe('Channel model', function () {
    it('generates a server-side ULID public id that is valid as a connector channelId', function () {
        [, , $ch] = waChannel();

        expect($ch->public_id)->toHaveLength(26)->toMatch('/^[A-Za-z0-9_-]{1,64}$/');
        expect(Channel::findByPublicId($ch->public_id)->id)->toBe($ch->id)->and(Channel::findByPublicId('nope'))->toBeNull();
    });

    it('cannot be created with a bot of another tenant', function () {
        [$a] = waChannel();
        [, $botB] = waChannel();

        expect(fn () => inTenant($a, fn () => Channel::create(['bot_id' => $botB->id, 'name' => 'x'])))
            ->toThrow(InvalidArgumentException::class);
    });

    it('isolates channels, conversations and messages between tenants', function () {
        [$a, , $chA] = waChannel();
        [$b, , $chB] = waChannel();
        waPost(waInbound($chA, ['externalMessageId' => 'A1']));
        waPost(waInbound($chB, ['externalMessageId' => 'B1']));

        foreach ([[$a, $chA], [$b, $chB]] as [$tenant, $own]) {
            inTenant($tenant, function () use ($own) {
                expect(Channel::pluck('id')->all())->toBe([$own->id])
                    ->and(Conversation::count())->toBe(1)->and(Message::count())->toBe(1);
            });
        }

        expect(Message::count())->toBe(0); // tanpa konteks: gagal tertutup
    });
});

// ---- Adapter ----
describe('BaileysConnectorAdapter', function () {
    function waAdapter(): BaileysConnectorAdapter
    {
        return new BaileysConnectorAdapter('http://connector.test', 'conn-token-123', 5);
    }

    it('sends through the channel of the conversation with a bearer token', function () {
        [, , $ch] = waChannel();
        Http::fake(['connector.test/*' => Http::response(['channelId' => $ch->public_id, 'messageId' => 'WAOUT1', 'to' => '6281@s.whatsapp.net'])]);

        $r = waAdapter()->send($ch, '6281@s.whatsapp.net', 'Halo!');

        expect($r->externalMessageId)->toBe('WAOUT1');
        Http::assertSent(fn ($req) => $req->url() === "http://connector.test/channels/{$ch->public_id}/send"
            && $req->method() === 'POST' && $req->hasHeader('Authorization', 'Bearer conn-token-123')
            && $req->data() === ['to' => '6281@s.whatsapp.net', 'text' => 'Halo!']);
    });

    it('classifies failures: only certain-not-sent errors are retryable', function (int $status, string $type, bool $retryable) {
        [, , $ch] = waChannel();
        Http::fake(['connector.test/*' => Http::response(['error' => ['code' => 'X', 'message' => 'conn-token-123 bocor?']], $status)]);

        try {
            waAdapter()->send($ch, '6281@s.whatsapp.net', 'rahasia pelanggan');
            $this->fail('Expected exception');
        } catch (SendEx $e) {
            expect($e->type)->toBe($type)->and($e->retryable)->toBe($retryable)->and($e->httpStatus)->toBe($status)
                ->and($e->getMessage())->not->toContain('conn-token')->not->toContain('rahasia');
        }
    })->with([
        [401, 'connector_unauthorized', false],
        [404, 'channel_not_found', true],
        [409, 'channel_not_connected', true],
        [422, 'invalid_message', false],
        [500, 'connector_error', false],  // ambigu: bisa saja sudah terkirim
        [502, 'connector_error', false],
    ]);

    it('retries only when the connector was unreachable, not on ambiguous timeouts', function () {
        [, , $ch] = waChannel();

        Http::fake(fn () => throw new ConnectionException('cURL error 7: Failed to connect to 127.0.0.1 port 3100'));
        expect(fn () => waAdapter()->send($ch, 'x@s.whatsapp.net', 't'))->toThrow(fn (SendEx $e) => $e->type === 'connector_unreachable' && $e->retryable);

        Http::swap(new \Illuminate\Http\Client\Factory());
        Http::fake(fn () => throw new ConnectionException('cURL error 28: Operation timed out'));
        expect(fn () => waAdapter()->send($ch, 'x@s.whatsapp.net', 't'))->toThrow(fn (SendEx $e) => $e->type === 'send_timeout_unknown' && ! $e->retryable);
    });

    it('manages the session through the internal API', function () {
        [, , $ch] = waChannel();
        Http::fake([
            'connector.test/channels/*/start' => Http::response(['channelId' => $ch->public_id, 'state' => 'starting']),
            'connector.test/channels/*/qr' => Http::response(['channelId' => $ch->public_id, 'state' => 'qr', 'qr' => 'QRDATA']),
            'connector.test/channels/*' => Http::response(['channelId' => $ch->public_id, 'state' => 'connected']),
        ]);

        $a = waAdapter();
        expect($a->start($ch)['state'])->toBe('starting')->and($a->qr($ch))->toBe('QRDATA')
            ->and($a->status($ch)['state'])->toBe('connected')->and($a->stop($ch, logout: true)['state'])->toBe('connected');

        Http::assertSent(fn ($r) => $r->method() === 'DELETE' && str_ends_with($r->url(), "/channels/{$ch->public_id}?logout=1"));
    });

    it('is bound in the container to the Baileys adapter', function () {
        expect(app(\App\Services\Whatsapp\WhatsAppChannelAdapter::class))->toBeInstanceOf(BaileysConnectorAdapter::class);
    });
});
