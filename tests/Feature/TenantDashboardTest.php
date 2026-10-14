<?php

use App\Models\AiTestMessage;
use App\Models\Bot;
use App\Models\Channel;
use App\Models\Conversation;
use App\Models\Faq;
use App\Models\Message;
use App\Models\Product;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\TenantMember;
use App\Models\User;
use App\Services\Usage\QuotaManager;
use App\Support\Tenancy\CurrentTenant;

/** Workspace berlangganan (Medium, 1000 pesan/hari) dengan satu owner. */
function tdTenant(bool $subscribed = true): array
{
    $tenant = Tenant::factory()->create(['name' => 'Toko Maju']);
    $subscribed && Subscription::factory()->create(['tenant_id' => $tenant->id, 'plan_code' => 'medium', 'current_period_end' => now()->addDays(20)]);
    $user = User::factory()->create(['name' => 'Budi Santoso']);
    TenantMember::create(['tenant_id' => $tenant->id, 'user_id' => $user->id, 'role' => 'owner']);

    return [$tenant, $user];
}

function tdIn(Tenant $t, callable $fn): mixed
{
    return app(CurrentTenant::class)->runAs($t, $fn);
}

it('shows today\'s summary: AI quota, conversations, WhatsApp status and subscription', function () {
    [$t, $u] = tdTenant();
    app(QuotaManager::class)->reserve($t, 'k1');
    app(QuotaManager::class)->reserve($t, 'k2');
    tdIn($t, function () use ($t) {
        $bot = Bot::factory()->create(['tenant_id' => $t->id, 'status' => 'active']);
        $ch = Channel::factory()->create(['tenant_id' => $t->id, 'bot_id' => $bot->id, 'status' => 'connected', 'name' => 'Nomor Toko']);
        Conversation::create(['bot_id' => $bot->id, 'channel_id' => $ch->id, 'customer_external_id' => '6281@s.whatsapp.net', 'last_message_at' => now()]);
        Conversation::create(['bot_id' => $bot->id, 'channel_id' => $ch->id, 'customer_external_id' => '6282@s.whatsapp.net', 'last_message_at' => now()->subDays(3)]);
    });

    $this->actingAs($u)->get('/app')->assertOk()
        ->assertSee('Budi Santoso')->assertSee('Paket MEDIUM')
        ->assertSeeInOrder(['Pesan AI hari ini', '2', '/ 1.000', '0% kuota harian terpakai'])
        ->assertSeeInOrder(['Percakapan hari ini', '1'])
        ->assertSeeInOrder(['WhatsApp', 'Terhubung', 'Nomor Toko'])
        ->assertSee('Berakhir dalam 20 hari');
});

it('guides setup step by step and marks finished steps', function () {
    [$t, $u] = tdTenant();

    $this->actingAs($u)->get('/app')->assertOk()
        ->assertSee('Siapkan chatbot Anda')->assertSee('0 dari 6 langkah selesai');

    tdIn($t, function () use ($t) {
        $t->businessProfile()->create(['business_type' => 'Toko bangunan', 'description' => 'Menjual bahan bangunan']);
        Product::factory()->create(['tenant_id' => $t->id]);
        Faq::factory()->create(['tenant_id' => $t->id]);
        $bot = Bot::factory()->create(['tenant_id' => $t->id, 'status' => 'active']);
        Channel::factory()->create(['tenant_id' => $t->id, 'bot_id' => $bot->id, 'status' => 'connected']);
    });

    $this->actingAs($u)->get('/app')->assertSee('5 dari 6 langkah selesai');

    tdIn($t, fn () => AiTestMessage::create(['user_id' => $u->id, 'question' => 'halo', 'answer' => 'hai', 'status' => 'success']));

    $this->actingAs($u)->get('/app')->assertSee('Chatbot Anda siap')->assertSee('6 dari 6 langkah selesai');
});

it('lists the latest conversations with the last message, showing numbers without the WhatsApp suffix', function () {
    [$t, $u] = tdTenant();
    tdIn($t, function () use ($t) {
        $bot = Bot::factory()->create(['tenant_id' => $t->id]);
        $ch = Channel::factory()->create(['tenant_id' => $t->id, 'bot_id' => $bot->id]);
        $named = Conversation::create(['bot_id' => $bot->id, 'channel_id' => $ch->id, 'customer_external_id' => '6281234567890@s.whatsapp.net', 'customer_name' => 'Sari', 'last_message_at' => now()->subMinutes(5)]);
        Message::create(['conversation_id' => $named->id, 'direction' => 'inbound', 'body' => 'Pesan lama', 'status' => 'received']);
        Message::create(['conversation_id' => $named->id, 'direction' => 'outbound', 'body' => 'Stok semen masih ada kak', 'status' => 'sent']);
        Conversation::create(['bot_id' => $bot->id, 'channel_id' => $ch->id, 'customer_external_id' => '6289999@s.whatsapp.net', 'last_message_at' => now()]);
        // Nama profil WhatsApp yang hanya simbol tidak dipakai; percakapan kemarin ditandai "Kemarin".
        Conversation::create(['bot_id' => $bot->id, 'channel_id' => $ch->id, 'customer_external_id' => '6285555@s.whatsapp.net', 'customer_name' => '~', 'last_message_at' => now('Asia/Jakarta')->subDay()]);
    });

    $html = $this->actingAs($u)->get('/app')->assertOk()
        ->assertSeeInOrder(['Percakapan terbaru', '6289999', 'Sari', '6281234567890', 'Stok semen masih ada kak', '6285555', 'Kemarin'])
        ->assertDontSee('Pesan lama')
        ->assertDontSee('@s.whatsapp.net')
        ->getContent();

    expect($html)->not->toContain('<strong>~')
        ->and($html)->toMatch('#>\d{2}:\d{2}</time>#'); // hari ini: jam, bukan "x menit yang lalu"
});

it('shows an empty state and a subscription call to action for a workspace without a subscription', function () {
    [, $u] = tdTenant(subscribed: false);

    $this->actingAs($u)->get('/app')->assertOk()
        ->assertSee('Belum ada percakapan')
        ->assertSee('Workspace ini belum berlangganan')
        ->assertSee('Lihat cara berlangganan')
        ->assertSee('Belum terhubung');
});

it('never shows another workspace\'s data', function () {
    [$t, $u] = tdTenant();
    [$other] = tdTenant();
    tdIn($other, function () use ($other) {
        $bot = Bot::factory()->create(['tenant_id' => $other->id]);
        $ch = Channel::factory()->create(['tenant_id' => $other->id, 'bot_id' => $bot->id, 'status' => 'connected']);
        Conversation::create(['bot_id' => $bot->id, 'channel_id' => $ch->id, 'customer_external_id' => '62777@s.whatsapp.net', 'customer_name' => 'Rahasia', 'last_message_at' => now()]);
    });

    $this->actingAs($u)->get('/app')->assertOk()->assertDontSee('Rahasia')->assertSee('Belum terhubung')->assertSee('Belum ada percakapan');
});

it('groups the navigation into dropdowns and marks the current section', function () {
    [, $u] = tdTenant();

    $html = $this->actingAs($u)->get('/app/products')->assertOk()->getContent();

    expect($html)->toContain('Knowledge Base')->toContain('Chatbot')->toContain('Pesan Gagal')->toContain('Langganan')->toContain('Keluar')
        ->and(preg_match('#class="nav-l on"[^>]*>\s*<svg[^>]*>.*?</svg>\s*Knowledge Base#s', $html))->toBe(1)
        ->and(preg_match('#class="nav-l on"[^>]*>\s*<svg[^>]*>.*?</svg>\s*Dashboard#s', $html))->toBe(0);
});
