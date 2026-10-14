<?php

use App\Jobs\ProcessInboundMessage;
use App\Jobs\SendOutboundMessage;
use App\Livewire\Tenant\ConversationHistory;
use App\Models\Bot;
use App\Models\Channel;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\TenantMember;
use App\Models\User;
use App\Services\Plans\Entitlements;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;

/** Jumlah query yang dijalankan $fn. */
function perfQueries(callable $fn, ?string $contains = null): int
{
    DB::flushQueryLog();
    DB::enableQueryLog();
    $fn();
    $log = DB::getQueryLog();
    DB::disableQueryLog();

    return collect($log)->filter(fn ($q) => $contains === null || str_contains($q['query'], $contains))->count();
}

// ---- Database ----

it('indexes messages by status and age for the stuck-message sweeper and admin widget', function () {
    expect(Schema::hasIndex('messages', ['status', 'updated_at']))->toBeTrue();
});

// ---- Antrean ----

it('keeps the queue retry_after above the longest job timeout (no double processing)', function () {
    $retryAfter = (int) config('queue.connections.database.retry_after');

    foreach ([ProcessInboundMessage::class, SendOutboundMessage::class] as $job) {
        expect((new ReflectionClass($job))->getDefaultProperties()['timeout'])->toBeLessThan($retryAfter);
    }
});

it('puts AI work and WhatsApp sending on separate queues', function () {
    Queue::fake();

    ProcessInboundMessage::dispatch(1, 1);
    SendOutboundMessage::dispatch(1, 2);

    Queue::assertPushedOn('ai', ProcessInboundMessage::class);
    Queue::assertPushedOn('whatsapp', SendOutboundMessage::class);
});

it('keeps the delayed re-dispatch of a rate-limited send on the whatsapp queue', function () {
    Queue::fake();

    SendOutboundMessage::dispatch(1, 2)->delay(30);

    Queue::assertPushedOn('whatsapp', SendOutboundMessage::class);
});

it('has workers for every queue in the Supervisor config and the local dev script', function () {
    $conf = file_get_contents(base_path('deploy/supervisor/velora-queue.conf'));
    $composer = file_get_contents(base_path('composer.json'));

    expect($conf)->toContain('--queue=ai ')->toContain('--queue=whatsapp,default')
        ->and($composer)->toContain('--queue=ai,whatsapp,default');
});

// ---- N+1: website publik ----

it('renders /services without lazy loading (no N+1 on package features)', function () {
    $now = now();
    for ($c = 1; $c <= 4; $c++) {
        $cat = DB::table('categories')->insertGetId(['name' => "Kategori {$c}", 'slug' => "kat-{$c}", 'created_at' => $now, 'updated_at' => $now]);
        $svc = DB::table('services')->insertGetId(['category_id' => $cat, 'is_active' => true, 'sort_order' => $c, 'created_at' => $now, 'updated_at' => $now]);
        $pkg = DB::table('service_packages')->insertGetId(['service_id' => $svc, 'name' => 'Paket', 'price' => 1000000, 'created_at' => $now, 'updated_at' => $now]);
        DB::table('service_package_features')->insert(['package_id' => $pkg, 'text' => "Fitur {$c}", 'created_at' => $now, 'updated_at' => $now]);
    }

    Model::preventLazyLoading();

    try {
        $this->get('/services')->assertOk()->assertSee('Fitur 4');
    } finally {
        Model::preventLazyLoading(false);
    }
});

// ---- N+1: tabel Workspace admin ----

function perfWorkspace(string $plan, int $usedToday, ?string $channelStatus): Tenant
{
    $t = Tenant::factory()->create();
    Subscription::factory()->create(['tenant_id' => $t->id, 'plan_code' => $plan]);
    TenantMember::create(['tenant_id' => $t->id, 'user_id' => User::factory()->create()->id, 'role' => 'owner']);

    if ($usedToday > 0) {
        DB::table('daily_usage')->insert(['tenant_id' => $t->id, 'usage_date' => now('Asia/Jakarta')->toDateString(), 'used' => $usedToday, 'created_at' => now(), 'updated_at' => now()]);
    }

    if ($channelStatus) {
        app(CurrentTenant::class)->runAs($t, function () use ($t, $channelStatus) {
            $bot = Bot::factory()->create(['tenant_id' => $t->id]);
            Channel::factory()->create(['tenant_id' => $t->id, 'bot_id' => $bot->id, 'status' => $channelStatus]);
        });
    }

    return $t;
}

it('loads the admin workspace table with a constant number of queries', function () {
    $admin = User::factory()->platformAdmin()->create();

    perfWorkspace('small', 3, 'connected');
    $few = perfQueries(fn () => $this->actingAs($admin)->get('/admin/tenants')->assertOk());

    for ($i = 0; $i < 7; $i++) {
        perfWorkspace($i % 2 ? 'small' : 'medium', $i, $i % 3 ? 'connected' : null);
    }
    $many = perfQueries(fn () => $this->actingAs($admin)->get('/admin/tenants')->assertOk());

    // 1 baris vs 8 baris: jumlah query sama (sebelumnya +3 query per baris).
    expect($many)->toBe($few);
});

it('still shows the right usage, limit and WhatsApp status per workspace', function () {
    $admin = User::factory()->platformAdmin()->create();
    perfWorkspace('medium', 7, 'logged_out');
    perfWorkspace('small', 0, null);

    $this->actingAs($admin)->get('/admin/tenants')->assertOk()
        ->assertSee('7 / 1000')->assertSee('logged_out')
        ->assertSee('0 / 100')->assertSee('belum ada');
});

it('derives the same entitlements from eager-loaded and lazily queried subscriptions', function () {
    $t = Tenant::factory()->create();
    Subscription::factory()->create(['tenant_id' => $t->id, 'plan_code' => 'medium', 'current_period_end' => now()->subDay()]); // habis
    Subscription::factory()->create(['tenant_id' => $t->id, 'plan_code' => 'small']);

    $lazy = Entitlements::forTenant(Tenant::find($t->id));
    $eager = Entitlements::forTenant(Tenant::with('entitledSubscriptions')->find($t->id));

    expect($eager->planCode)->toBe('small')->and($eager)->toEqual($lazy);
});

// ---- Polling halaman Percakapan ----

it('reads the subscription once per conversation-page render (the page polls every 5s)', function () {
    $t = Tenant::factory()->create();
    Subscription::factory()->create(['tenant_id' => $t->id, 'plan_code' => 'small']);
    $user = User::factory()->create();
    TenantMember::create(['tenant_id' => $t->id, 'user_id' => $user->id, 'role' => 'owner']);
    app(CurrentTenant::class)->set($t);

    $page = Livewire::actingAs($user)->test(ConversationHistory::class);

    expect(perfQueries(fn () => $page->call('$refresh'), 'from "subscriptions"'))->toBe(1);
});
