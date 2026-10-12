<?php

use App\Jobs\ProcessInboundMessage;
use App\Jobs\SendOutboundMessage;
use App\Models\Bot;
use App\Models\Channel;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\TenantNotification;
use App\Services\Usage\QuotaManager;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    config(['plans.small.daily_ai_messages' => 100, 'whatsapp.sweep.requeue_after_minutes' => 5, 'whatsapp.sweep.stuck_after_minutes' => 10, 'whatsapp.sweep.expire_after_minutes' => 60]);
    Queue::fake();
});

/** @return array{0: Tenant, 1: Channel, 2: Conversation} */
function swSetup(): array
{
    $t = Tenant::factory()->create();
    Subscription::factory()->create(['tenant_id' => $t->id, 'plan_code' => 'small']);

    return app(CurrentTenant::class)->runAs($t, function () use ($t) {
        $bot = Bot::factory()->create(['tenant_id' => $t->id, 'status' => 'active']);
        $ch = Channel::factory()->create(['tenant_id' => $t->id, 'bot_id' => $bot->id]);
        $c = Conversation::create(['bot_id' => $bot->id, 'channel_id' => $ch->id, 'customer_external_id' => '6281@s.whatsapp.net']);

        return [$t, $ch, $c];
    });
}

/** Buat pesan dengan umur (menit) sejak diperbarui terakhir dan sejak dibuat. */
function swMsg(Tenant $t, Conversation $c, string $dir, string $status, int $updatedMinutesAgo, ?int $createdMinutesAgo = null, ?string $ext = null): Message
{
    static $n = 0;

    return app(CurrentTenant::class)->runAs($t, function () use ($c, $dir, $status, $updatedMinutesAgo, $createdMinutesAgo, $ext, &$n) {
        $m = Message::create(['conversation_id' => $c->id, 'direction' => $dir, 'status' => $status, 'body' => 'isi', 'external_message_id' => $ext ?? ($dir === 'inbound' ? 'WAMID-' . (++$n) : null)]);
        DB::table('messages')->where('id', $m->id)->update([
            'created_at' => now()->subMinutes($createdMinutesAgo ?? $updatedMinutesAgo),
            'updated_at' => now()->subMinutes($updatedMinutesAgo),
        ]);

        return $m;
    });
}

function swFresh(Message $m): Message
{
    return Message::withoutTenancy()->findOrFail($m->id);
}

it('requeues an unclaimed inbound message and touches it so the next sweep does not repeat it', function () {
    [$t, , $c] = swSetup();
    $m = swMsg($t, $c, 'inbound', 'received', 8);

    $this->artisan('messages:sweep-stuck')->expectsOutputToContain('Diantre ulang: 1 masuk, 0 keluar')->assertSuccessful();
    Queue::assertPushed(ProcessInboundMessage::class, fn ($j) => $j->messageId === $m->id && $j->tenantId === $t->id);
    expect(swFresh($m)->status)->toBe('received')->and(swFresh($m)->updated_at->gt(now()->subMinute()))->toBeTrue();

    Queue::fake();
    $this->artisan('messages:sweep-stuck')->assertSuccessful();
    Queue::assertNothingPushed();
});

it('requeues an unsent outbound reply', function () {
    [$t, , $c] = swSetup();
    $m = swMsg($t, $c, 'outbound', 'pending', 7);

    $this->artisan('messages:sweep-stuck')->expectsOutputToContain('0 masuk, 1 keluar')->assertSuccessful();

    Queue::assertPushed(SendOutboundMessage::class, fn ($j) => $j->messageId === $m->id);
    expect(swFresh($m)->status)->toBe('pending');
});

it('leaves recent messages alone (still within the normal processing window)', function () {
    [$t, , $c] = swSetup();
    foreach ([['inbound', 'received'], ['outbound', 'pending'], ['inbound', 'processing'], ['outbound', 'sending']] as [$d, $s]) {
        swMsg($t, $c, $d, $s, 2);
    }

    $this->artisan('messages:sweep-stuck')->expectsOutputToContain('0 masuk, 0 keluar. Kedaluwarsa: 0')->assertSuccessful();

    Queue::assertNothingPushed();
    expect(Message::withoutTenancy()->where('status', 'failed')->count())->toBe(0);
});

it('expires messages that have waited too long instead of replying hours later', function () {
    [$t, , $c] = swSetup();
    $in = swMsg($t, $c, 'inbound', 'received', 30, 90);
    $out = swMsg($t, $c, 'outbound', 'pending', 30, 120);

    $this->artisan('messages:sweep-stuck')->expectsOutputToContain('Kedaluwarsa: 2')->assertSuccessful();

    Queue::assertNothingPushed();
    expect(swFresh($in)->status)->toBe('failed')->and(swFresh($in)->error_type)->toBe('stale_expired')
        ->and(swFresh($out)->error_type)->toBe('stale_expired');
});

it('marks a stuck "processing" inbound as failed and returns the reserved quota unit, without calling the AI again', function () {
    [$t, $ch, $c] = swSetup();
    $m = swMsg($t, $c, 'inbound', 'processing', 15, null, 'WAMID-STUCK');
    app(QuotaManager::class)->reserve($t, "msg:{$ch->public_id}:WAMID-STUCK", 'chat', $m->id);
    expect(app(QuotaManager::class)->used($t))->toBe(1);

    $this->artisan('messages:sweep-stuck')->assertSuccessful();

    Queue::assertNothingPushed(); // tidak diulang: AI mungkin sudah dipanggil
    expect(swFresh($m)->status)->toBe('failed')->and(swFresh($m)->error_type)->toBe('stuck_processing')
        ->and(app(QuotaManager::class)->used($t))->toBe(0);
});

it('marks a stuck "sending" outbound as failed and NEVER resends it (it may have been delivered)', function () {
    [$t, , $c] = swSetup();
    $m = swMsg($t, $c, 'outbound', 'sending', 20);

    $this->artisan('messages:sweep-stuck')->assertSuccessful();

    Queue::assertNothingPushed();
    expect(swFresh($m)->status)->toBe('failed')->and(swFresh($m)->error_type)->toBe('send_timeout_unknown');
});

it('does not overwrite a message that finished between reading and updating', function () {
    [$t, , $c] = swSetup();
    $m = swMsg($t, $c, 'outbound', 'sending', 20);
    $stale = Message::withoutTenancy()->find($m->id);
    DB::table('messages')->where('id', $m->id)->update(['status' => 'sent']);

    $method = new ReflectionMethod(\App\Console\Commands\SweepStuckMessages::class, 'markFailed');
    $changed = $method->invoke(app(\App\Console\Commands\SweepStuckMessages::class), $stale, 'send_timeout_unknown', 'sending');

    expect($changed)->toBeFalse()->and(swFresh($m)->status)->toBe('sent');
});

it('notifies the workspace once per tenant, and not other tenants', function () {
    [$t, , $c] = swSetup();
    [$other] = swSetup();
    swMsg($t, $c, 'outbound', 'sending', 20);
    swMsg($t, $c, 'outbound', 'sending', 30);

    $this->artisan('messages:sweep-stuck')->assertSuccessful();

    expect(TenantNotification::withoutTenancy()->where('tenant_id', $t->id)->where('type', 'send_failed')->count())->toBe(1)
        ->and(TenantNotification::withoutTenancy()->where('tenant_id', $other->id)->count())->toBe(0);
});

it('handles each tenant independently and only touches stuck rows', function () {
    [$a, , $ca] = swSetup();
    [$b, , $cb] = swSetup();
    $stuck = swMsg($a, $ca, 'outbound', 'pending', 8);
    $fine = swMsg($b, $cb, 'outbound', 'pending', 1);
    $done = swMsg($b, $cb, 'outbound', 'sent', 500);

    $this->artisan('messages:sweep-stuck')->assertSuccessful();

    Queue::assertPushed(SendOutboundMessage::class, 1);
    Queue::assertPushed(SendOutboundMessage::class, fn ($j) => $j->messageId === $stuck->id && $j->tenantId === $a->id);
    expect(swFresh($fine)->status)->toBe('pending')->and(swFresh($done)->status)->toBe('sent');
});

it('--dry-run counts but changes nothing', function () {
    [$t, , $c] = swSetup();
    $in = swMsg($t, $c, 'inbound', 'received', 8);
    $send = swMsg($t, $c, 'outbound', 'sending', 20);

    $this->artisan('messages:sweep-stuck', ['--dry-run' => true])->expectsOutputToContain('Diantre ulang: 1 masuk, 0 keluar. Kedaluwarsa: 0. Macet (ditandai gagal): 0 diproses, 1 dikirim — akan ditangani')->assertSuccessful();

    Queue::assertNothingPushed();
    expect(swFresh($in)->status)->toBe('received')->and(swFresh($send)->status)->toBe('sending')
        ->and(TenantNotification::withoutTenancy()->count())->toBe(0);
});

it('processes at most one batch per run, oldest first', function () {
    config(['whatsapp.sweep.batch' => 2]);
    [$t, , $c] = swSetup();
    $ids = collect(range(1, 3))->map(fn () => swMsg($t, $c, 'inbound', 'received', 8)->id);

    $this->artisan('messages:sweep-stuck')->assertSuccessful();

    Queue::assertPushed(ProcessInboundMessage::class, 2);
    Queue::assertPushed(ProcessInboundMessage::class, fn ($j) => $j->messageId === $ids[0]);
    Queue::assertNotPushed(ProcessInboundMessage::class, fn ($j) => $j->messageId === $ids[2]);
});

it('a requeued job is harmless when another worker already claimed the message (atomic claim)', function () {
    [$t, , $c] = swSetup();
    $m = swMsg($t, $c, 'inbound', 'received', 8);

    $this->artisan('messages:sweep-stuck')->assertSuccessful();

    DB::table('messages')->where('id', $m->id)->update(['status' => 'processing']);
    (new ProcessInboundMessage($t->id, $m->id))->handle(app(QuotaManager::class), app(\App\Services\Ai\PromptBuilder::class), app(\App\Services\Ai\AiGateway::class), app(CurrentTenant::class));

    expect(swFresh($m)->status)->toBe('processing');
});

it('is scheduled every five minutes', function () {
    $e = collect(app(Schedule::class)->events())->filter(fn ($e) => str_contains($e->command, 'messages:sweep-stuck'));

    expect($e)->toHaveCount(1)->and($e->first()->expression)->toBe('*/5 * * * *');
});
