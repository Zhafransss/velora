<?php

use App\Livewire\Tenant\BusinessProfileForm;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\TenantMember;
use App\Models\User;
use App\Support\Tenancy\CurrentTenant;
use Livewire\Livewire;

function tenantUser(string $role = 'owner', ?Tenant $tenant = null): array
{
    $tenant ??= Tenant::factory()->create();
    $user = User::factory()->create();
    TenantMember::create(['tenant_id' => $tenant->id, 'user_id' => $user->id, 'role' => $role]);

    return [$user, $tenant];
}

// Belum berlangganan => fitur terkunci, tanpa campur tangan admin per akun.
it('shows the dashboard but locks features for an unsubscribed workspace', function () {
    [$user] = tenantUser();

    $this->actingAs($user)->get('/app')->assertOk()->assertSee('belum berlangganan', false);
    $this->actingAs($user)->get('/app/business-profile')->assertRedirect(route('app.subscription'));
    $this->actingAs($user)->get('/app/subscription')->assertOk()->assertSee('Belum berlangganan');
});

it('unlocks features automatically once the workspace has an active subscription', function () {
    [$user, $tenant] = tenantUser();
    Subscription::factory()->create(['tenant_id' => $tenant->id]);

    $this->actingAs($user)->get('/app/business-profile')->assertOk();
});

it('allows a trialing subscription', function () {
    [$user, $tenant] = tenantUser();
    Subscription::factory()->status('trialing')->create(['tenant_id' => $tenant->id]);

    $this->actingAs($user)->get('/app/business-profile')->assertOk();
});

it('locks features for every non-entitled subscription state', function (string $status) {
    [$user, $tenant] = tenantUser();
    Subscription::factory()->status($status)->create(['tenant_id' => $tenant->id]);

    $this->actingAs($user)->get('/app/business-profile')->assertRedirect(route('app.subscription'));
})->with(['past_due', 'suspended', 'canceled', 'expired']);

it('locks features when an active subscription has passed its period end', function () {
    [$user, $tenant] = tenantUser();
    Subscription::factory()->expiredPeriod()->create(['tenant_id' => $tenant->id]);

    $this->actingAs($user)->get('/app/business-profile')->assertRedirect(route('app.subscription'));
});

it('locks features when the tenant itself is suspended', function () {
    [$user, $tenant] = tenantUser();
    Subscription::factory()->create(['tenant_id' => $tenant->id]);
    $tenant->update(['status' => Tenant::STATUS_SUSPENDED]);

    $this->actingAs($user)->get('/app/business-profile')->assertForbidden();
});

it('does not let another tenant subscription unlock this workspace', function () {
    [$user] = tenantUser();
    Subscription::factory()->create(); // milik tenant lain

    $this->actingAs($user)->get('/app/business-profile')->assertRedirect(route('app.subscription'));
});

it('gives users without a workspace a 403 on /app', function () {
    $this->actingAs(User::factory()->create())->get('/app')->assertForbidden();
});

describe('business profile (Livewire)', function () {
    it('saves the profile for the current tenant only', function () {
        [$user, $tenant] = tenantUser();
        $other = Tenant::factory()->create();
        app(CurrentTenant::class)->set($tenant);

        Livewire::actingAs($user)->test(BusinessProfileForm::class)
            ->set('business_type', 'kuliner')
            ->set('phone', '0812')
            ->set('opening_hours', 'Senin-Sabtu 09.00-17.00')
            ->set('timezone', 'Asia/Makassar')
            ->call('save')
            ->assertHasNoErrors();

        expect($tenant->fresh()->businessProfile->business_type)->toBe('kuliner')
            ->and($tenant->fresh()->businessProfile->opening_hours)->toBe(['text' => 'Senin-Sabtu 09.00-17.00'])
            ->and($tenant->fresh()->timezone)->toBe('Asia/Makassar')
            ->and($other->fresh()->businessProfile)->toBeNull();
    });

    it('validates input', function () {
        [$user, $tenant] = tenantUser();
        app(CurrentTenant::class)->set($tenant);

        Livewire::actingAs($user)->test(BusinessProfileForm::class)
            ->set('business_type', '')
            ->set('timezone', 'Mars/Olympus')
            ->call('save')
            ->assertHasErrors(['business_type', 'timezone']);
    });

    it('forbids viewers from saving', function () {
        [$user, $tenant] = tenantUser(TenantMember::ROLE_VIEWER);
        app(CurrentTenant::class)->set($tenant);

        Livewire::actingAs($user)->test(BusinessProfileForm::class)
            ->set('business_type', 'kuliner')
            ->call('save')
            ->assertForbidden();

        expect($tenant->fresh()->businessProfile)->toBeNull();
    });
});

it('activates a subscription through the artisan command', function () {
    $tenant = Tenant::factory()->create(['slug' => 'toko-maju']);

    $this->artisan('subscription:activate', ['tenant' => 'toko-maju', 'plan' => 'medium', '--days' => 30])->assertSuccessful();

    expect($tenant->fresh()->hasActiveSubscription())->toBeTrue()
        ->and($tenant->currentSubscription->plan_code)->toBe('medium');

    $this->artisan('subscription:activate', ['tenant' => 'tidak-ada'])->assertFailed();
    $this->artisan('subscription:activate', ['tenant' => 'toko-maju', 'plan' => 'gratis'])->assertFailed();
});

it('keeps tenant context and the subscription gate on Livewire AJAX requests', function () {
    $persistent = Livewire::getPersistentMiddleware();

    expect($persistent)->toContain(\App\Http\Middleware\SetCurrentTenant::class)
        ->and($persistent)->toContain(\App\Http\Middleware\EnsureSubscribed::class);
});
