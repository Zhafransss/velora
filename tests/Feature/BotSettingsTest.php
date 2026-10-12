<?php

use App\Livewire\Tenant\BotSettingsForm;
use App\Models\Bot;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\TenantMember;
use App\Models\User;
use App\Support\Tenancy\CurrentTenant;
use Livewire\Livewire;

function botTenant(string $role = 'owner'): array
{
    $tenant = Tenant::factory()->create();
    Subscription::factory()->create(['tenant_id' => $tenant->id]);
    $user = User::factory()->create();
    TenantMember::create(['tenant_id' => $tenant->id, 'user_id' => $user->id, 'role' => $role]);
    app(CurrentTenant::class)->set($tenant);

    return [$user, $tenant];
}

// ---- Skema ----
it('generates a unique ULID public_id and never trusts a supplied one', function () {
    [, $tenant] = botTenant();

    $a = Bot::create(Bot::defaults() + ['public_id' => 'x']);
    $b = Bot::create(Bot::defaults());

    expect($a->public_id)->toHaveLength(26)->not->toBe('x')
        ->and($a->public_id)->not->toBe($b->public_id)
        ->and($a->tenant_id)->toBe($tenant->id);
});

it('isolates bots between tenants', function () {
    [, $tenantA] = botTenant();
    $mine = Bot::factory()->create(['tenant_id' => $tenantA->id]);

    $other = Tenant::factory()->create();
    $theirs = app(CurrentTenant::class)->runAs($other, fn () => Bot::factory()->create(['tenant_id' => $other->id]));

    expect(Bot::pluck('id')->all())->toBe([$mine->id])
        ->and(Bot::find($theirs->id))->toBeNull();
});

// ---- Gerbang ----
it('locks the bot page for unsubscribed workspaces', function () {
    $tenant = Tenant::factory()->create();
    $user = User::factory()->create();
    TenantMember::create(['tenant_id' => $tenant->id, 'user_id' => $user->id, 'role' => 'owner']);

    $this->actingAs($user)->get('/app/bot')->assertRedirect(route('app.subscription'));
});

it('opens the bot page for subscribed workspaces', function () {
    [$user] = botTenant();

    $this->actingAs($user)->get('/app/bot')->assertOk()->assertSee('Pengaturan Bot');
});

// ---- Form ----
it('shows defaults when the workspace has no bot yet', function () {
    [$user] = botTenant();

    Livewire::actingAs($user)->test(BotSettingsForm::class)
        ->assertSet('name', 'Asisten Virtual')
        ->assertSet('status', 'draft')
        ->assertSet('language', 'id')
        ->assertSet('welcome_message', Bot::defaults()['welcome_message']);

    expect(Bot::count())->toBe(0); // membuka halaman tidak menulis data
});

it('creates the bot on first save and updates the same row afterwards', function () {
    [$user, $tenant] = botTenant();

    $c = Livewire::actingAs($user)->test(BotSettingsForm::class)
        ->set('name', 'Mbak Sari')
        ->set('persona', 'Admin toko roti')
        ->set('tone', 'santai')
        ->set('status', 'active')
        ->call('save')->assertHasNoErrors();

    expect(Bot::count())->toBe(1);
    $bot = Bot::first();
    expect($bot->name)->toBe('Mbak Sari')->and($bot->tenant_id)->toBe($tenant->id)
        ->and($bot->tone)->toBe('santai')->and($bot->status)->toBe('active')
        ->and($bot->system_instructions)->toBeNull();

    $publicId = $bot->public_id;
    $c->set('name', 'Mas Budi')->call('save');

    expect(Bot::count())->toBe(1)->and(Bot::first()->name)->toBe('Mas Budi')
        ->and(Bot::first()->public_id)->toBe($publicId);
});

it('loads an existing bot into the form', function () {
    [$user, $tenant] = botTenant();
    Bot::factory()->create(['tenant_id' => $tenant->id, 'name' => 'Bot Lama', 'tone' => 'formal']);

    Livewire::actingAs($user)->test(BotSettingsForm::class)
        ->assertSet('name', 'Bot Lama')->assertSet('tone', 'formal');
});

it('never loads the bot of another tenant', function () {
    [$user] = botTenant();
    $other = Tenant::factory()->create();
    app(CurrentTenant::class)->runAs($other, fn () => Bot::factory()->create(['tenant_id' => $other->id, 'name' => 'Rahasia B']));

    Livewire::actingAs($user)->test(BotSettingsForm::class)->assertSet('name', 'Asisten Virtual');
});

it('validates input', function () {
    [$user] = botTenant();

    Livewire::actingAs($user)->test(BotSettingsForm::class)
        ->set('name', '')
        ->set('tone', 'kasar')
        ->set('language', 'xx')
        ->set('status', 'meledak')
        ->set('welcome_message', '')
        ->set('fallback_message', '')
        ->set('uncertainty_rule', '')
        ->set('system_instructions', str_repeat('a', 4001))
        ->call('save')
        ->assertHasErrors(['name', 'tone', 'language', 'status', 'welcome_message', 'fallback_message', 'uncertainty_rule', 'system_instructions']);

    expect(Bot::count())->toBe(0);
});

it('forbids viewers from saving', function () {
    [$user] = botTenant(TenantMember::ROLE_VIEWER);

    Livewire::actingAs($user)->test(BotSettingsForm::class)
        ->set('name', 'Diubah viewer')->call('save')->assertForbidden();

    expect(Bot::count())->toBe(0);
});
