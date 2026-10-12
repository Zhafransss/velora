<?php

use App\Models\AiTestMessage;
use App\Models\AuditLog;
use App\Models\Bot;
use App\Models\Channel;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Services\Usage\QuotaManager;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/** Membuat percakapan dengan pesan-pesan berumur tertentu (hari lalu, boleh pecahan). */
function rtConversation(Tenant $t, string $name, array $messageAges, ?float $lastMessageAge = null): Conversation
{
    $bot = Bot::withoutTenancy()->where('tenant_id', $t->id)->first() ?? Bot::factory()->create(['tenant_id' => $t->id]);
    $ch = Channel::withoutTenancy()->where('tenant_id', $t->id)->first() ?? Channel::factory()->create(['tenant_id' => $t->id, 'bot_id' => $bot->id]);

    return app(CurrentTenant::class)->runAs($t, function () use ($bot, $ch, $name, $messageAges, $lastMessageAge) {
        $c = Conversation::create(['bot_id' => $bot->id, 'channel_id' => $ch->id, 'customer_external_id' => "{$name}@s.whatsapp.net", 'customer_name' => $name,
            'last_message_at' => now()->subMinutes((int) (($lastMessageAge ?? min($messageAges ?: [0])) * 1440))]);

        foreach ($messageAges as $i => $age) {
            $m = Message::create(['conversation_id' => $c->id, 'direction' => 'inbound', 'external_message_id' => "{$name}-{$i}", 'body' => "isi {$name} {$i}", 'status' => 'answered']);
            DB::table('messages')->where('id', $m->id)->update(['created_at' => now()->subMinutes((int) ($age * 1440))]);
        }

        return $c;
    });
}

function rtCounts(): array
{
    return ['messages' => DB::table('messages')->count(), 'conversations' => DB::table('conversations')->count(), 'tests' => DB::table('ai_test_messages')->count()];
}

it('uses 30 days by default', function () {
    expect(config('retention.conversation_days'))->toBe(30);
});

describe('conversations:prune', function () {
    it('deletes messages older than the retention period and keeps newer ones', function () {
        $t = Tenant::factory()->create();
        rtConversation($t, 'Campur', [45, 31, 29, 1]);

        $this->artisan('conversations:prune')->assertSuccessful();

        $kept = DB::table('messages')->pluck('external_message_id')->sort()->values()->all();
        expect($kept)->toBe(['Campur-2', 'Campur-3'])->and(DB::table('conversations')->count())->toBe(1);
    });

    it('respects the boundary precisely', function () {
        $t = Tenant::factory()->create();
        rtConversation($t, 'Batas', [29.99, 30.01], 29.99);

        $this->artisan('conversations:prune')->assertSuccessful();

        expect(DB::table('messages')->pluck('external_message_id')->all())->toBe(['Batas-0']);
    });

    it('removes a conversation once all of its messages expired', function () {
        $t = Tenant::factory()->create();
        rtConversation($t, 'Usang', [40, 35]);
        rtConversation($t, 'Aktif', [40, 2]);

        $this->artisan('conversations:prune')->assertSuccessful();

        expect(DB::table('conversations')->pluck('customer_name')->all())->toBe(['Aktif'])
            ->and(DB::table('messages')->pluck('external_message_id')->all())->toBe(['Aktif-1']);
    });

    it('keeps a fresh conversation that has no message yet, and removes an old empty one', function () {
        $t = Tenant::factory()->create();
        $bot = Bot::factory()->create(['tenant_id' => $t->id]);
        $ch = Channel::factory()->create(['tenant_id' => $t->id, 'bot_id' => $bot->id]);
        app(CurrentTenant::class)->runAs($t, function () use ($bot, $ch) {
            Conversation::create(['bot_id' => $bot->id, 'channel_id' => $ch->id, 'customer_external_id' => 'baru@s.whatsapp.net', 'last_message_at' => null]);
            $old = Conversation::create(['bot_id' => $bot->id, 'channel_id' => $ch->id, 'customer_external_id' => 'lama@s.whatsapp.net', 'last_message_at' => now()->subDays(60)]);
            DB::table('conversations')->where('id', $old->id)->update(['created_at' => now()->subDays(60)]);
        });

        $this->artisan('conversations:prune')->assertSuccessful();

        expect(DB::table('conversations')->pluck('customer_external_id')->all())->toBe(['baru@s.whatsapp.net']);
    });

    it('prunes chatbot test history too', function () {
        $t = Tenant::factory()->create();
        app(CurrentTenant::class)->runAs($t, function () {
            $old = AiTestMessage::create(['question' => 'lama', 'status' => 'success']);
            $new = AiTestMessage::create(['question' => 'baru', 'status' => 'success']);
            DB::table('ai_test_messages')->where('id', $old->id)->update(['created_at' => now()->subDays(40)]);
        });

        $this->artisan('conversations:prune')->assertSuccessful();

        expect(DB::table('ai_test_messages')->pluck('question')->all())->toBe(['baru']);
    });

    it('applies to every tenant without touching recent data', function () {
        [$a, $b] = [Tenant::factory()->create(), Tenant::factory()->create()];
        rtConversation($a, 'A', [50]);
        rtConversation($b, 'B', [50, 1]);

        $this->artisan('conversations:prune')->assertSuccessful();

        expect(DB::table('messages')->pluck('external_message_id')->all())->toBe(['B-1'])
            ->and(DB::table('conversations')->pluck('customer_name')->all())->toBe(['B']);
    });

    it('deletes in chunks, handling more rows than one batch', function () {
        $t = Tenant::factory()->create();
        $c = rtConversation($t, 'Banyak', [1]);
        $rows = collect(range(1, 2500))->map(fn ($i) => ['tenant_id' => $t->id, 'conversation_id' => $c->id, 'direction' => 'inbound', 'external_message_id' => "x{$i}", 'body' => 'x',
            'status' => 'answered', 'created_at' => now()->subDays(90), 'updated_at' => now()->subDays(90)])->all();
        foreach (array_chunk($rows, 500) as $chunk) {
            DB::table('messages')->insert($chunk);
        }
        expect(DB::table('messages')->count())->toBe(2501);

        $this->artisan('conversations:prune')->expectsOutputToContain('2500 pesan, 0 percakapan, 0 riwayat tes dihapus')->assertSuccessful();

        expect(DB::table('messages')->count())->toBe(1);
    });

    it('does nothing on --dry-run but reports the same numbers as a real run', function () {
        $t = Tenant::factory()->create();
        rtConversation($t, 'Usang', [40]);
        rtConversation($t, 'Aktif', [40, 1]);
        $before = rtCounts();

        $this->artisan('conversations:prune', ['--dry-run' => true])->expectsOutputToContain('2 pesan, 1 percakapan, 0 riwayat tes akan dihapus')->assertSuccessful();
        expect(rtCounts())->toBe($before)->and(AuditLog::count())->toBe(0);

        $this->artisan('conversations:prune')->expectsOutputToContain('2 pesan, 1 percakapan, 0 riwayat tes dihapus')->assertSuccessful();
        expect(rtCounts())->toBe(['messages' => 1, 'conversations' => 1, 'tests' => 0]);
    });

    it('honours --days and refuses dangerous values', function (string $days) {
        $t = Tenant::factory()->create();
        rtConversation($t, 'Dua', [2, 0.5]);
        $before = rtCounts();

        $this->artisan('conversations:prune', ['--days' => $days])->assertFailed();

        expect(rtCounts())->toBe($before);
    })->with(['0', '-5', 'abc']);

    it('applies a shorter --days value', function () {
        $t = Tenant::factory()->create();
        rtConversation($t, 'Dua', [3, 0.5]);

        $this->artisan('conversations:prune', ['--days' => 1])->assertSuccessful();

        expect(DB::table('messages')->pluck('external_message_id')->all())->toBe(['Dua-1']);
    });

    it('does not delete billing ledger, audit history or technical AI logs', function () {
        $t = Tenant::factory()->create();
        Subscription::factory()->create(['tenant_id' => $t->id]);
        app(QuotaManager::class)->reserve($t, 'k1', now: now()->subDays(60));
        DB::table('ai_calls')->insert(['tenant_id' => $t->id, 'purpose' => 'chat', 'status' => 'success', 'latency_ms' => 5, 'attempts' => 1, 'created_at' => now()->subDays(60)]);
        rtConversation($t, 'Usang', [60]);

        $this->artisan('conversations:prune')->assertSuccessful();

        expect(DB::table('usage_events')->count())->toBe(1)->and(DB::table('daily_usage')->count())->toBe(1)->and(DB::table('ai_calls')->count())->toBe(1);
    });

    it('logs and audits only aggregate counts, never message content', function () {
        $t = Tenant::factory()->create();
        rtConversation($t, 'Rahasia', [60]);
        Log::spy();

        $this->artisan('conversations:prune')->assertSuccessful();

        $audit = AuditLog::sole();
        expect($audit->action)->toBe('system.retention_pruned')->and($audit->after)->toEqual(['messages' => 1, 'conversations' => 1, 'ai_test_messages' => 0, 'tenant_notifications' => 0])
            ->and(json_encode($audit->getAttributes()))->not->toContain('Rahasia')->not->toContain('isi ');
        Log::shouldHaveReceived('info')->withArgs(fn ($m, $ctx) => $m === 'retention.pruned' && ! str_contains(json_encode($ctx), 'Rahasia'))->once();
    });

    it('writes no audit entry when there is nothing to delete', function () {
        $t = Tenant::factory()->create();
        rtConversation($t, 'Segar', [1]);

        $this->artisan('conversations:prune')->assertSuccessful();

        expect(AuditLog::count())->toBe(0)->and(DB::table('messages')->count())->toBe(1);
    });

    it('is idempotent', function () {
        $t = Tenant::factory()->create();
        rtConversation($t, 'Usang', [60]);

        $this->artisan('conversations:prune')->assertSuccessful();
        $this->artisan('conversations:prune')->assertSuccessful();

        expect(rtCounts())->toBe(['messages' => 0, 'conversations' => 0, 'tests' => 0])->and(AuditLog::count())->toBe(1);
    });

    it('is scheduled daily', function () {
        $events = collect(app(Schedule::class)->events())->filter(fn ($e) => str_contains($e->command, 'conversations:prune'));

        expect($events)->toHaveCount(1)->and($events->first()->expression)->toBe('30 2 * * *')->and($events->first()->withoutOverlapping)->toBeTrue();
    });
});
