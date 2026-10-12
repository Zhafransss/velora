<?php

use App\Models\BusinessProfile;
use App\Models\Tenant;
use App\Models\TenantMember;
use App\Models\User;
use Illuminate\Database\QueryException;

it('creates a tenant with default status and timezone', function () {
    $tenant = Tenant::create(['name' => 'Toko Maju', 'slug' => 'toko-maju'])->fresh();

    expect($tenant->status)->toBe('active')
        ->and($tenant->timezone)->toBe('Asia/Jakarta');
});

it('rejects a duplicate tenant slug', function () {
    Tenant::factory()->create(['slug' => 'sama']);

    Tenant::factory()->create(['slug' => 'sama']);
})->throws(QueryException::class);

it('links users to tenants with a role', function () {
    $tenant = Tenant::factory()->create();
    $user = User::factory()->create();
    TenantMember::create(['tenant_id' => $tenant->id, 'user_id' => $user->id, 'role' => TenantMember::ROLE_OWNER]);

    expect($user->tenants->first()->is($tenant))->toBeTrue()
        ->and($user->tenants->first()->pivot->role)->toBe('owner')
        ->and($tenant->users)->toHaveCount(1);
});

it('rejects the same user joining a tenant twice', function () {
    $tenant = Tenant::factory()->create();
    $user = User::factory()->create();
    TenantMember::create(['tenant_id' => $tenant->id, 'user_id' => $user->id, 'role' => 'owner']);

    TenantMember::create(['tenant_id' => $tenant->id, 'user_id' => $user->id, 'role' => 'viewer']);
})->throws(QueryException::class);

it('allows only one business profile per tenant', function () {
    $tenant = Tenant::factory()->create();
    BusinessProfile::factory()->create(['tenant_id' => $tenant->id]);

    BusinessProfile::factory()->create(['tenant_id' => $tenant->id]);
})->throws(QueryException::class);

it('casts business profile json columns to arrays', function () {
    $profile = BusinessProfile::factory()->create(['opening_hours' => ['sat' => '10:00-14:00']])->fresh();

    expect($profile->opening_hours)->toBe(['sat' => '10:00-14:00']);
});

it('does not allow tenant_id mass assignment on business profile', function () {
    $other = Tenant::factory()->create();
    $tenant = Tenant::factory()->create();

    $profile = $tenant->businessProfile()->create(['business_type' => 'jasa', 'tenant_id' => $other->id]);

    expect($profile->tenant_id)->toBe($tenant->id);
});

it('cascades deletion from tenant to members and profile', function () {
    $tenant = Tenant::factory()->create();
    BusinessProfile::factory()->create(['tenant_id' => $tenant->id]);
    TenantMember::create(['tenant_id' => $tenant->id, 'user_id' => User::factory()->create()->id, 'role' => 'owner']);

    $tenant->delete();

    expect(TenantMember::count())->toBe(0)->and(BusinessProfile::count())->toBe(0);
});
