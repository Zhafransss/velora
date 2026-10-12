<?php

use App\Livewire\Tenant\ConversationHistory;
use App\Livewire\Tenant\WhatsAppConnection;
use App\Models\Bot;
use App\Models\Channel;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\TenantMember;
use App\Models\User;
use App\Services\Whatsapp\QrRenderer;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

const QR_PAYLOAD = '2@RAHASIA-QR-PAYLOAD-123,abc/def+ghi=,xyz';

beforeEach(function () {
    config(['whatsapp.connector_url' => 'http://connector.test', 'whatsapp.connector_token' => 'conn-token-xyz']);
});

/** Workspace berlangganan + pengguna dengan peran; konteks tenant diset (seperti middleware pada request). */
function wdTenant(string $role = 'owner', bool $withBot = true, string $botStatus = 'active'): array
{
    $tenant = Tenant::factory()->create();
    Subscription::factory()->create(['tenant_id' => $tenant->id]);
    $user = User::factory()->create();
    TenantMember::create(['tenant_id' => $tenant->id, 'user_id' => $user->id, 'role' => $role]);
    app(CurrentTenant::class)->set($tenant);
    $bot = $withBot ? Bot::factory()->create(['tenant_id' => $tenant->id, 'status' => $botStatus]) : null;

    return [$user, $tenant, $bot];
}

function wdChannel(Tenant $t, Bot $bot, string $status = 'created'): Channel
{
    return Channel::factory()->create(['tenant_id' => $t->id, 'bot_id' => $bot->id, 'status' => $status]);
}

function wdFake(array $routes): void
{
    Http::swap(new HttpFactory());
    Http::fake($routes);
}

function wdConversation(Tenant $t, Channel $ch, string $name = 'Budi', string $jid = '6281234567890@s.whatsapp.net'): Conversation
{
    return app(CurrentTenant::class)->runAs($t, fn () => Conversation::create([
        'bot_id' => $ch->bot_id, 'channel_id' => $ch->id, 'customer_external_id' => $jid, 'customer_name' => $name, 'last_message_at' => now(),
    ]));
}

// ---- QrRenderer ----
it('renders a QR payload to an SVG data URI on the server', function () {
    $a = (new QrRenderer())->dataUri(QR_PAYLOAD);
    $b = (new QrRenderer())->dataUri(QR_PAYLOAD . 'x');

    expect($a)->toStartWith('data:image/svg+xml;base64,')->and($a)->not->toBe($b)
        ->and(base64_decode(substr($a, strlen('data:image/svg+xml;base64,'))))->toContain('<svg');
});

// ---- Halaman koneksi: gerbang & pembuatan kanal ----
describe('WhatsApp page access', function () {
    it('is locked for workspaces without an active subscription', function () {
        $tenant = Tenant::factory()->create();
        $user = User::factory()->create();
        TenantMember::create(['tenant_id' => $tenant->id, 'user_id' => $user->id, 'role' => 'owner']);

        $this->actingAs($user)->get('/app/whatsapp')->assertRedirect(route('app.subscription'));
        $this->actingAs($user)->get('/app/conversations')->assertRedirect(route('app.subscription'));
    });

    it('opens for subscribed workspaces', function () {
        [$user] = wdTenant();

        $this->actingAs($user)->get('/app/whatsapp')->assertOk()->assertSee('Buat kanal WhatsApp');
        $this->actingAs($user)->get('/app/conversations')->assertOk();
    });

    it('asks to configure the bot first when there is none', function () {
        [$user] = wdTenant(withBot: false);

        $this->actingAs($user)->get('/app/whatsapp')->assertSee('belum menyimpan pengaturan bot');

        Livewire::actingAs($user)->test(WhatsAppConnection::class)->call('createChannel')
            ->assertSet('error', 'Simpan Pengaturan Bot terlebih dahulu sebelum membuat kanal.');
        expect(Channel::count())->toBe(0);
    });
});

describe('creating the channel', function () {
    it('creates one channel bound to the workspace bot', function () {
        [$user, $tenant, $bot] = wdTenant();

        Livewire::actingAs($user)->test(WhatsAppConnection::class)->set('name', 'WA Toko')->call('createChannel')->assertHasNoErrors();

        $ch = Channel::sole();
        expect($ch->name)->toBe('WA Toko')->and($ch->bot_id)->toBe($bot->id)->and($ch->tenant_id)->toBe($tenant->id)
            ->and($ch->status)->toBe('created')->and($ch->public_id)->toHaveLength(26);
    });

    it('allows only one channel per workspace and validates the name', function () {
        [$user, $tenant, $bot] = wdTenant();
        $c = Livewire::actingAs($user)->test(WhatsAppConnection::class);

        $c->set('name', '')->call('createChannel')->assertHasErrors('name');
        $c->set('name', 'Pertama')->call('createChannel');
        $c->set('name', 'Kedua')->call('createChannel')->assertSet('error', 'Workspace ini sudah memiliki kanal WhatsApp.');

        expect(Channel::count())->toBe(1);
    });

    it('forbids viewers', function () {
        [$user] = wdTenant('viewer');

        $this->actingAs($user)->get('/app/whatsapp')->assertOk()->assertSee('hanya memiliki akses lihat', false)->assertDontSee('Buat kanal WhatsApp');
        Livewire::actingAs($user)->test(WhatsAppConnection::class)->set('name', 'x')->call('createChannel')->assertForbidden();
        expect(Channel::count())->toBe(0);
    });
});

// ---- Menghubungkan & QR ----
describe('connecting and QR', function () {
    it('starts the session through the connector and shows the new status', function () {
        [$user, $tenant, $bot] = wdTenant();
        $ch = wdChannel($tenant, $bot);
        wdFake([
            'connector.test/channels/*/start' => Http::response(['channelId' => $ch->public_id, 'state' => 'starting']),
            'connector.test/channels/*' => Http::response(['channelId' => $ch->public_id, 'state' => 'starting']),
        ]);

        Livewire::actingAs($user)->test(WhatsAppConnection::class)->call('connect')->assertSet('error', null)->assertSee('Memulai sambungan');

        expect($ch->fresh()->status)->toBe('starting');
        Http::assertSent(fn ($r) => $r->url() === "http://connector.test/channels/{$ch->public_id}/start" && $r->hasHeader('Authorization', 'Bearer conn-token-xyz'));
    });

    it('shows the QR as an image, never as raw text, and does not persist it', function () {
        [$user, $tenant, $bot] = wdTenant();
        $ch = wdChannel($tenant, $bot, 'qr');
        wdFake([
            'connector.test/channels/*/qr' => Http::response(['channelId' => $ch->public_id, 'state' => 'qr', 'qr' => QR_PAYLOAD]),
            'connector.test/channels/*' => Http::response(['channelId' => $ch->public_id, 'state' => 'qr']),
        ]);

        $html = Livewire::actingAs($user)->test(WhatsAppConnection::class)->assertSee('Menunggu pemindaian QR')->assertSee('Perangkat tertaut')->html();

        expect($html)->toContain('data:image/svg+xml;base64,')->not->toContain('RAHASIA-QR-PAYLOAD');
        // Tidak tersimpan di database.
        expect(json_encode(\Illuminate\Support\Facades\DB::table('channels')->get()))->not->toContain('RAHASIA-QR-PAYLOAD');
    });

    it('does not fetch or show the QR for viewers', function () {
        [$user, $tenant, $bot] = wdTenant('viewer');
        $ch = wdChannel($tenant, $bot, 'qr');
        wdFake(['connector.test/channels/*' => Http::response(['channelId' => $ch->public_id, 'state' => 'qr'])]);

        $html = Livewire::actingAs($user)->test(WhatsAppConnection::class)->assertSee('Menunggu pemindaian QR')->html();

        expect($html)->not->toContain('data:image/svg+xml');
        Http::assertNotSent(fn ($r) => str_ends_with($r->url(), '/qr'));
    });

    it('syncs a connected state and account from the connector', function () {
        [$user, $tenant, $bot] = wdTenant();
        $ch = wdChannel($tenant, $bot, 'qr');
        wdFake(['connector.test/channels/*' => Http::response(['channelId' => $ch->public_id, 'state' => 'connected', 'me' => ['id' => '6281234@s.whatsapp.net']])]);

        Livewire::actingAs($user)->test(WhatsAppConnection::class)->assertSee('Terhubung')->assertSee('Akun tertaut: 6281234')->assertDontSeeHtml('wire:click="connect"'); // tombol Hubungkan hilang saat terhubung

        $ch = $ch->fresh();
        expect($ch->status)->toBe('connected')->and($ch->external_account_id)->toBe('6281234@s.whatsapp.net');
    });

    it('ignores unknown connector states and never revives a revoked channel', function () {
        [$user, $tenant, $bot] = wdTenant();
        $weird = wdChannel($tenant, $bot, 'qr');
        wdFake(['connector.test/channels/*' => Http::response(['state' => 'hacked'])]);

        Livewire::actingAs($user)->test(WhatsAppConnection::class);
        expect($weird->fresh()->status)->toBe('qr');
    });

    it('survives an unreachable connector with a generic message and no technical details', function () {
        [$user, $tenant, $bot] = wdTenant();
        wdChannel($tenant, $bot, 'connected');
        wdFake(['connector.test/*' => fn () => throw new ConnectionException('cURL error 7: Failed to connect to connector.test port 3100')]);

        $html = Livewire::actingAs($user)->test(WhatsAppConnection::class)
            ->assertSee('Layanan WhatsApp belum dapat dihubungi')->html();

        expect($html)->not->toContain('3100')->not->toContain('conn-token-xyz')->not->toContain('cURL');
    });

    it('shows a friendly error when starting fails', function () {
        [$user, $tenant, $bot] = wdTenant();
        wdChannel($tenant, $bot);
        wdFake(['connector.test/*' => fn () => throw new ConnectionException('cURL error 7: refused')]);

        Livewire::actingAs($user)->test(WhatsAppConnection::class)->call('connect')
            ->assertSet('error', 'Layanan WhatsApp belum dapat dihubungi. Coba lagi beberapa saat lagi.');
    });

    it('clears a stale "cannot reach" error by itself once the connector is back', function () {
        [$user, $tenant, $bot] = wdTenant();
        wdChannel($tenant, $bot); // status "created": halaman tidak menyinkronkan status
        wdFake(['connector.test/*' => fn () => throw new ConnectionException('cURL error 7: refused')]);

        $c = Livewire::actingAs($user)->test(WhatsAppConnection::class)->call('connect')
            ->assertSet('error', 'Layanan WhatsApp belum dapat dihubungi. Coba lagi beberapa saat lagi.');

        // Konektor hidup lagi (kanal belum dikenal di sana -> 404) pada polling berikutnya.
        wdFake(['connector.test/*' => Http::response(['error' => ['code' => 'CHANNEL_NOT_FOUND']], 404)]);
        $c->call('$refresh')->assertSet('error', null)->assertDontSee('belum dapat dihubungi');
    });

    it('keeps the error while the connector is still down', function () {
        [$user, $tenant, $bot] = wdTenant();
        wdChannel($tenant, $bot);
        wdFake(['connector.test/*' => fn () => throw new ConnectionException('cURL error 7: refused')]);

        Livewire::actingAs($user)->test(WhatsAppConnection::class)->call('connect')->call('$refresh')
            ->assertSee('Layanan WhatsApp belum dapat dihubungi');
    });

    it('warns when the bot is not active because it will not reply', function () {
        [$user, $tenant, $bot] = wdTenant(botStatus: 'draft');
        wdChannel($tenant, $bot, 'connected');
        wdFake(['connector.test/*' => Http::response(['state' => 'connected'])]);

        Livewire::actingAs($user)->test(WhatsAppConnection::class)->assertSee('belum membalas pelanggan');
    });

    it('polls faster while pairing than when connected', function () {
        [$user, $tenant, $bot] = wdTenant();
        $ch = wdChannel($tenant, $bot, 'qr');
        wdFake(['connector.test/*' => Http::response(['state' => 'qr', 'qr' => QR_PAYLOAD])]);
        expect(Livewire::actingAs($user)->test(WhatsAppConnection::class)->html())->toContain('wire:poll.3s');

        $ch->update(['status' => 'connected']);
        wdFake(['connector.test/*' => Http::response(['state' => 'connected'])]);
        expect(Livewire::actingAs($user)->test(WhatsAppConnection::class)->html())->toContain('wire:poll.10s');
    });

    it('forbids viewers from connecting', function () {
        [$user, $tenant, $bot] = wdTenant('viewer');
        wdChannel($tenant, $bot);
        wdFake(['connector.test/*' => Http::response(['state' => 'starting'])]);

        Livewire::actingAs($user)->test(WhatsAppConnection::class)->call('connect')->assertForbidden();

        Http::assertNothingSent();
    });
});

describe('disconnecting', function () {
    it('logs the device out through the connector and marks the channel logged out', function () {
        [$user, $tenant, $bot] = wdTenant();
        $ch = wdChannel($tenant, $bot, 'connected');
        $ch->update(['external_account_id' => '6281@s.whatsapp.net']);
        wdFake(['connector.test/*' => Http::response(['channelId' => $ch->public_id, 'state' => 'stopped'])]);

        Livewire::actingAs($user)->test(WhatsAppConnection::class)->call('disconnect')->assertSet('error', null);

        Http::assertSent(fn ($r) => $r->method() === 'DELETE' && str_ends_with($r->url(), "/channels/{$ch->public_id}?logout=1"));
        $ch = $ch->fresh();
        expect($ch->status)->toBe('logged_out')->and($ch->external_account_id)->toBeNull();
    });

    it('treats a channel unknown to the connector as already disconnected', function () {
        [$user, $tenant, $bot] = wdTenant();
        $ch = wdChannel($tenant, $bot, 'connected');
        wdFake(['connector.test/*' => Http::response(['error' => ['code' => 'CHANNEL_NOT_FOUND']], 404)]);

        Livewire::actingAs($user)->test(WhatsAppConnection::class)->call('disconnect')->assertSet('error', null);

        expect($ch->fresh()->status)->toBe('logged_out');
    });

    it('keeps the channel connected when the connector is down', function () {
        [$user, $tenant, $bot] = wdTenant();
        $ch = wdChannel($tenant, $bot, 'connected');
        wdFake(['connector.test/*' => fn () => throw new ConnectionException('cURL error 7')]);

        Livewire::actingAs($user)->test(WhatsAppConnection::class)->call('disconnect')->assertSet('error', fn ($e) => $e !== null);

        expect($ch->fresh()->status)->toBe('connected');
    });

    it('forbids viewers', function () {
        [$user, $tenant, $bot] = wdTenant('viewer');
        $ch = wdChannel($tenant, $bot, 'connected');

        Livewire::actingAs($user)->test(WhatsAppConnection::class)->call('disconnect')->assertForbidden();

        expect($ch->fresh()->status)->toBe('connected');
    });
});

it('never shows or touches the channel of another workspace', function () {
    [$userA, $tenantA, $botA] = wdTenant();
    $other = Tenant::factory()->create();
    $chB = app(CurrentTenant::class)->runAs($other, function () use ($other) {
        $botB = Bot::factory()->create(['tenant_id' => $other->id]);

        return Channel::factory()->create(['tenant_id' => $other->id, 'bot_id' => $botB->id, 'status' => 'qr', 'name' => 'KANAL RAHASIA B']);
    });
    wdFake(['connector.test/*' => Http::response(['state' => 'qr', 'qr' => QR_PAYLOAD])]);

    Livewire::actingAs($userA)->test(WhatsAppConnection::class)->assertDontSee('KANAL RAHASIA B')->assertSee('Buat kanal WhatsApp');

    Http::assertNothingSent(); // tidak ada kanal milik tenant A, jadi tidak ada pemanggilan konektor sama sekali
    expect(Channel::withoutTenancy()->find($chB->id)->status)->toBe('qr');
});

// ---- Riwayat percakapan ----
describe('conversation history', function () {
    it('shows an empty state', function () {
        [$user] = wdTenant();

        Livewire::actingAs($user)->test(ConversationHistory::class)->assertSee('Belum ada percakapan');
    });

    it('lists own conversations and shows the thread with understandable statuses', function () {
        [$user, $tenant, $bot] = wdTenant();
        $ch = wdChannel($tenant, $bot, 'connected');
        $conv = wdConversation($tenant, $ch);
        app(CurrentTenant::class)->runAs($tenant, function () use ($conv) {
            $in = Message::create(['conversation_id' => $conv->id, 'direction' => 'inbound', 'external_message_id' => 'A', 'body' => 'Berapa harga kopi?', 'status' => 'answered']);
            Message::create(['conversation_id' => $conv->id, 'direction' => 'outbound', 'body' => 'Rp25.000 kak', 'status' => 'sent', 'source' => 'ai', 'in_reply_to_id' => $in->id]);
            Message::create(['conversation_id' => $conv->id, 'direction' => 'inbound', 'external_message_id' => 'B', 'body' => 'Ada promo?', 'status' => 'quota_exceeded']);
            Message::create(['conversation_id' => $conv->id, 'direction' => 'inbound', 'external_message_id' => 'C', 'body' => 'Halo?', 'status' => 'skipped', 'error_type' => 'bot_not_active']);
        });

        Livewire::actingAs($user)->test(ConversationHistory::class)
            ->assertSee('Budi (6281234567890)')->assertDontSee('Berapa harga kopi?')
            ->call('select', $conv->id)
            ->assertSee('Berapa harga kopi?')->assertSee('Rp25.000 kak')->assertSee('Sudah dijawab')->assertSee('Terkirim')
            ->assertSee('kuota AI harian habis')->assertSee('bot belum aktif');
    });

    it('labels fallback replies and failed deliveries', function () {
        $m = fn (array $a) => new Message($a);

        expect(ConversationHistory::statusLabel($m(['direction' => 'outbound', 'status' => 'failed', 'error_type' => 'channel_not_connected'])))->toBe('Gagal dikirim (channel_not_connected)')
            ->and(ConversationHistory::statusLabel($m(['direction' => 'outbound', 'status' => 'pending'])))->toBe('Menunggu dikirim')
            ->and(ConversationHistory::statusLabel($m(['direction' => 'inbound', 'status' => 'failed'])))->toContain('AI gagal menjawab')
            ->and(ConversationHistory::statusLabel($m(['direction' => 'inbound', 'status' => 'skipped', 'error_type' => 'no_subscription'])))->toContain('langganan tidak aktif');
    });

    it('escapes message bodies (no HTML injection from customers)', function () {
        [$user, $tenant, $bot] = wdTenant();
        $conv = wdConversation($tenant, wdChannel($tenant, $bot, 'connected'));
        app(CurrentTenant::class)->runAs($tenant, fn () => Message::create(['conversation_id' => $conv->id, 'direction' => 'inbound', 'external_message_id' => 'X', 'body' => '<script>alert("xss")</script><b>tebal</b>', 'status' => 'answered']));

        $html = Livewire::actingAs($user)->test(ConversationHistory::class)->call('select', $conv->id)->html();

        expect($html)->not->toContain('<script>alert')->not->toContain('<b>tebal</b>')->toContain('&lt;script&gt;');
    });

    it('is readable by viewers', function () {
        [$user, $tenant, $bot] = wdTenant('viewer');
        $conv = wdConversation($tenant, wdChannel($tenant, $bot, 'connected'), 'Siti');

        Livewire::actingAs($user)->test(ConversationHistory::class)->assertSee('Siti');
    });

    it('cannot open a conversation of another workspace by id or by query string', function () {
        [$user, $tenant, $bot] = wdTenant();
        $other = Tenant::factory()->create();
        $convB = app(CurrentTenant::class)->runAs($other, function () use ($other) {
            $botB = Bot::factory()->create(['tenant_id' => $other->id]);
            $chB = Channel::factory()->create(['tenant_id' => $other->id, 'bot_id' => $botB->id]);
            $c = Conversation::create(['bot_id' => $botB->id, 'channel_id' => $chB->id, 'customer_external_id' => '62899@s.whatsapp.net', 'customer_name' => 'PELANGGAN RAHASIA B']);
            Message::create(['conversation_id' => $c->id, 'direction' => 'inbound', 'external_message_id' => 'Z', 'body' => 'PESAN RAHASIA B', 'status' => 'answered']);

            return $c;
        });

        Livewire::actingAs($user)->test(ConversationHistory::class)->assertDontSee('PELANGGAN RAHASIA B');

        expect(fn () => Livewire::actingAs($user)->test(ConversationHistory::class)->call('select', $convB->id))->toThrow(ModelNotFoundException::class);
        expect(fn () => Livewire::withQueryParams(['c' => $convB->id])->actingAs($user)->test(ConversationHistory::class))->toThrow(ModelNotFoundException::class);
    });
});

// ---- user:verify ----
describe('user:verify command', function () {
    it('marks an unverified account as verified', function () {
        $user = User::factory()->unverified()->create(['email' => 'budi@example.com']);

        $this->artisan('user:verify', ['email' => 'BUDI@example.com'])->assertSuccessful();

        expect($user->fresh()->hasVerifiedEmail())->toBeTrue();
    });

    it('is idempotent and reports unknown accounts', function () {
        User::factory()->create(['email' => 'ok@example.com']);

        $this->artisan('user:verify', ['email' => 'ok@example.com'])->expectsOutputToContain('sudah terverifikasi')->assertSuccessful();
        $this->artisan('user:verify', ['email' => 'nobody@example.com'])->assertFailed();
    });
});
