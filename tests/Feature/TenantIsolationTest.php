<?php

use App\Models\Tenant;
use App\Models\TenantMember;
use App\Models\User;
use App\Support\Tenancy\CurrentTenant;
use App\Support\Tenancy\TenancyException;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Tests\Fixtures\TenantNote;

beforeEach(function () {
    Schema::create('tenant_notes', function ($table) {
        $table->id();
        $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
        $table->string('body');
        $table->timestamps();
    });

    $this->tenantA = Tenant::factory()->create();
    $this->tenantB = Tenant::factory()->create();
    $this->ctx = app(CurrentTenant::class);

    $this->noteA = $this->ctx->runAs($this->tenantA, fn () => TenantNote::create(['body' => 'milik A']));
    $this->noteB = $this->ctx->runAs($this->tenantB, fn () => TenantNote::create(['body' => 'milik B']));
});

// TC-ISO-01: tenant A tidak dapat membaca data tenant B
it('only returns rows of the current tenant', function () {
    $this->ctx->set($this->tenantA);

    expect(TenantNote::pluck('body')->all())->toBe(['milik A'])
        ->and(TenantNote::find($this->noteB->id))->toBeNull()
        ->and(TenantNote::count())->toBe(1);
});

it('returns nothing when there is no tenant context (fail closed)', function () {
    $this->ctx->clear();

    expect(TenantNote::count())->toBe(0);
});

it('does not leak through relations or direct tenant_id filters', function () {
    $this->ctx->set($this->tenantA);

    expect(TenantNote::where('tenant_id', $this->tenantB->id)->count())->toBe(0);
});

// TC-ISO-02: tenant A tidak dapat mengubah/menghapus data tenant B
it('cannot update or delete rows of another tenant', function () {
    $this->ctx->set($this->tenantA);

    expect(TenantNote::whereKey($this->noteB->id)->update(['body' => 'diretas']))->toBe(0)
        ->and(TenantNote::whereKey($this->noteB->id)->delete())->toBe(0);

    $this->ctx->set($this->tenantB);
    expect(TenantNote::find($this->noteB->id)->body)->toBe('milik B');
});

it('fills tenant_id from context on create and ignores request-style input', function () {
    $this->ctx->set($this->tenantA);

    $note = TenantNote::create(['body' => 'baru', 'tenant_id' => $this->tenantB->id]);

    expect($note->tenant_id)->toBe($this->tenantA->id);
});

it('refuses an explicit cross-tenant tenant_id on create', function () {
    $this->ctx->set($this->tenantA);

    $note = new TenantNote(['body' => 'x']);
    $note->tenant_id = $this->tenantB->id;
    $note->save();
})->throws(TenancyException::class);

it('refuses to create without tenant context or explicit tenant_id', function () {
    $this->ctx->clear();

    TenantNote::create(['body' => 'yatim']);
})->throws(TenancyException::class);

it('refuses to change tenant_id after creation', function () {
    $this->ctx->set($this->tenantA);

    $note = TenantNote::first();
    $note->tenant_id = $this->tenantB->id;
    $note->save();
})->throws(TenancyException::class);

it('lets system code opt out explicitly with withoutTenancy()', function () {
    $this->ctx->clear();

    expect(TenantNote::withoutTenancy()->count())->toBe(2);
});

it('restores the previous context after runAs()', function () {
    $this->ctx->set($this->tenantA);

    $this->ctx->runAs($this->tenantB, fn () => expect(TenantNote::first()->body)->toBe('milik B'));

    expect($this->ctx->id())->toBe($this->tenantA->id);
});

// Konteks tenant berasal dari keanggotaan, bukan dari input request.
describe('SetCurrentTenant middleware', function () {
    beforeEach(function () {
        Route::middleware(['web', 'auth', 'tenant'])->get('/_t/notes', fn () => TenantNote::pluck('body'));
    });

    it('scopes a request to the logged-in user tenant and ignores a spoofed tenant_id', function () {
        $user = User::factory()->create();
        TenantMember::create(['tenant_id' => $this->tenantA->id, 'user_id' => $user->id, 'role' => 'owner']);

        $this->actingAs($user)->get('/_t/notes?tenant_id=' . $this->tenantB->id)
            ->assertOk()
            ->assertExactJson(['milik A']);
    });

    it('returns 403 for a user without an active workspace', function () {
        $this->actingAs(User::factory()->create())->get('/_t/notes')->assertForbidden();
    });

    it('ignores suspended tenants', function () {
        $this->tenantA->update(['status' => Tenant::STATUS_SUSPENDED]);
        $user = User::factory()->create();
        TenantMember::create(['tenant_id' => $this->tenantA->id, 'user_id' => $user->id, 'role' => 'owner']);

        $this->actingAs($user)->get('/_t/notes')->assertForbidden();
    });

    it('returns 401 when used without an authenticated user', function () {
        Route::middleware(['web', 'tenant'])->get('/_t/no-auth', fn () => 'bocor');

        $this->get('/_t/no-auth')->assertUnauthorized();
    });
});
