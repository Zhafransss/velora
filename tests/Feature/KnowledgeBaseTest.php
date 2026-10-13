<?php

use App\Livewire\Tenant\Catalog\FaqsManager;
use App\Livewire\Tenant\Catalog\ProductsManager;
use App\Livewire\Tenant\Catalog\ServicesManager;
use App\Models\CatalogService;
use App\Models\Faq;
use App\Models\Product;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\TenantMember;
use App\Models\User;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Livewire\Livewire;

/** Data milik tenant lain, dibuat dalam konteks tenant tersebut (menulis lintas tenant sengaja ditolak). */
function foreign(string $model, array $attrs = []): Model
{
    $other = Tenant::factory()->create();

    return app(CurrentTenant::class)->runAs($other, fn () => $model::factory()->create($attrs + ["tenant_id" => $other->id]));
}

/** Workspace berlangganan + user dengan peran tertentu; konteks tenant sudah di-set. */
function kbTenant(string $role = 'owner'): array
{
    $tenant = Tenant::factory()->create();
    Subscription::factory()->create(['tenant_id' => $tenant->id]);
    $user = User::factory()->create();
    TenantMember::create(['tenant_id' => $tenant->id, 'user_id' => $user->id, 'role' => $role]);
    app(CurrentTenant::class)->set($tenant);

    return [$user, $tenant];
}

// ---- Skema (FR-005) ----
it('treats NULL stock as unknown, not zero', function () {
    $tenant = Tenant::factory()->create();
    $product = Product::factory()->create(['tenant_id' => $tenant->id, 'stock_quantity' => null])->fresh();

    expect($product->stock_quantity)->toBeNull()
        ->and($product->stock_status)->toBe('unknown');
});

it('enforces SKU uniqueness per tenant but allows the same SKU in another tenant and many empty SKUs', function () {
    [$a, $b] = [Tenant::factory()->create(), Tenant::factory()->create()];

    Product::factory()->create(['tenant_id' => $a->id, 'sku' => 'X1']);
    Product::factory()->create(['tenant_id' => $b->id, 'sku' => 'X1']);
    Product::factory()->count(2)->create(['tenant_id' => $a->id, 'sku' => null]);

    expect(Product::withoutTenancy()->count())->toBe(4);

    Product::factory()->create(['tenant_id' => $a->id, 'sku' => 'X1']);
})->throws(QueryException::class);

it('soft deletes knowledge base rows', function () {
    [, $tenant] = kbTenant();
    $faq = Faq::factory()->create(['tenant_id' => $tenant->id]);

    $faq->delete();

    expect(Faq::count())->toBe(0)->and(Faq::withTrashed()->count())->toBe(1);
});

// ---- TC-KB-01: isolasi lintas tenant untuk ketiga model ----
it('isolates knowledge base data between tenants', function (string $model) {
    [, $tenantA] = kbTenant();
    $mine = $model::factory()->create(['tenant_id' => $tenantA->id]);
    $theirs = foreign($model);

    expect($model::pluck('id')->all())->toBe([$mine->id])
        ->and($model::find($theirs->id))->toBeNull()
        ->and($model::whereKey($theirs->id)->update(['is_active' => false]))->toBe(0)
        ->and($model::whereKey($theirs->id)->delete())->toBe(0);

    app(CurrentTenant::class)->clear();
    expect($model::count())->toBe(0); // fail closed
})->with([Product::class, CatalogService::class, Faq::class]);

it('fills tenant_id from the current tenant when creating', function () {
    [, $tenant] = kbTenant();

    $faq = Faq::create(['question' => 'Buka jam berapa?', 'answer' => '09.00', 'tenant_id' => 999]);

    expect($faq->tenant_id)->toBe($tenant->id);
});

// ---- Gerbang langganan (DEC-11) ----
it('locks knowledge base pages for unsubscribed workspaces', function (string $path) {
    $tenant = Tenant::factory()->create();
    $user = User::factory()->create();
    TenantMember::create(['tenant_id' => $tenant->id, 'user_id' => $user->id, 'role' => 'owner']);

    $this->actingAs($user)->get($path)->assertRedirect(route('app.subscription'));
})->with(['/app/products', '/app/services', '/app/faqs']);

it('opens knowledge base pages for subscribed workspaces', function (string $path) {
    [$user] = kbTenant();

    $this->actingAs($user)->get($path)->assertOk();
})->with(['/app/products', '/app/services', '/app/faqs']);

// ---- CRUD produk ----
describe('products CRUD', function () {
    it('creates a product with empty price and stock stored as NULL', function () {
        [$user, $tenant] = kbTenant();

        Livewire::actingAs($user)->test(ProductsManager::class)
            ->call('create')
            ->set('form.name', 'Kopi Susu')
            ->set('form.sku', 'KS-1')
            ->call('save')
            ->assertHasNoErrors();

        $p = Product::first();
        expect($p->name)->toBe('Kopi Susu')
            ->and($p->tenant_id)->toBe($tenant->id)
            ->and($p->price)->toBeNull()
            ->and($p->stock_quantity)->toBeNull()
            ->and($p->stock_status)->toBe('unknown');
    });

    it('stores price and stock, and edits an existing product', function () {
        [$user, $tenant] = kbTenant();
        $p = Product::factory()->create(['tenant_id' => $tenant->id, 'name' => 'Lama', 'price' => 10000]);

        Livewire::actingAs($user)->test(ProductsManager::class)
            ->call('edit', $p->id)
            ->assertSet('form.name', 'Lama')
            ->set('form.name', 'Baru')
            ->set('form.price', '25000.50')
            ->set('form.stock_quantity', '12')
            ->set('form.stock_status', 'in_stock')
            ->call('save')
            ->assertHasNoErrors();

        $p->refresh();
        expect($p->name)->toBe('Baru')->and($p->price)->toBe('25000.50')
            ->and((float) $p->stock_quantity)->toBe(12.0)->and($p->stock_status)->toBe('in_stock');
    });

    it('validates input', function () {
        [$user] = kbTenant();

        Livewire::actingAs($user)->test(ProductsManager::class)
            ->call('create')
            ->set('form.name', '')
            ->set('form.price', '-5')
            ->set('form.stock_quantity', 'banyak')
            ->set('form.stock_status', 'entah')
            ->call('save')
            ->assertHasErrors(['form.name', 'form.price', 'form.stock_quantity', 'form.stock_status']);

        expect(Product::count())->toBe(0);
    });

    it('rejects a duplicate SKU within the tenant but not when editing the same product', function () {
        [$user, $tenant] = kbTenant();
        $p = Product::factory()->create(['tenant_id' => $tenant->id, 'sku' => 'DUP']);

        Livewire::actingAs($user)->test(ProductsManager::class)
            ->call('create')->set('form.name', 'Lain')->set('form.sku', 'DUP')->call('save')
            ->assertHasErrors('form.sku');

        Livewire::actingAs($user)->test(ProductsManager::class)
            ->call('edit', $p->id)->set('form.name', 'Ganti')->call('save')
            ->assertHasNoErrors();
    });

    it('allows the same SKU as another tenant', function () {
        [$user] = kbTenant();
        foreign(Product::class, ["sku" => "SAMA"]);

        Livewire::actingAs($user)->test(ProductsManager::class)
            ->call('create')->set('form.name', 'Punya saya')->set('form.sku', 'SAMA')->call('save')
            ->assertHasNoErrors();
    });

    it('soft deletes and toggles active', function () {
        [$user, $tenant] = kbTenant();
        $p = Product::factory()->create(['tenant_id' => $tenant->id]);

        $c = Livewire::actingAs($user)->test(ProductsManager::class);
        $c->call('toggleActive', $p->id);
        expect($p->fresh()->is_active)->toBeFalse();

        $c->call('delete', $p->id);
        expect(Product::count())->toBe(0)->and(Product::withTrashed()->count())->toBe(1);
    });

    it('lists only own records and supports search', function () {
        [$user, $tenant] = kbTenant();
        Product::factory()->create(['tenant_id' => $tenant->id, 'name' => 'Nasi Goreng']);
        Product::factory()->create(['tenant_id' => $tenant->id, 'name' => 'Es Teh']);
        foreign(Product::class, ["name" => "Nasi Rahasia"]);

        Livewire::actingAs($user)->test(ProductsManager::class)
            ->assertSee('Nasi Goreng')->assertSee('Es Teh')->assertDontSee('Nasi Rahasia')
            ->set('search', 'nasi')
            ->assertSee('Nasi Goreng')->assertDontSee('Es Teh')->assertDontSee('Nasi Rahasia');
    });

    // TC-KB-02: id milik tenant lain tidak dapat diubah/dihapus/dibuka lewat komponen.
    it('cannot edit, toggle or delete a product of another tenant', function () {
        [$user] = kbTenant();
        $other = foreign(Product::class, ["name" => "Milik B"]);

        $c = Livewire::actingAs($user)->test(ProductsManager::class);

        foreach (['edit', 'toggleActive', 'delete'] as $action) {
            $c->call($action, $other->id)->assertNotFound();
        }

        expect($other->fresh()->name)->toBe('Milik B')->and($other->fresh()->is_active)->toBeTrue();
    });

    it('cannot overwrite another tenant product by tampering editingId', function () {
        [$user] = kbTenant();
        $other = foreign(Product::class, ["name" => "Milik B"]);

        Livewire::actingAs($user)->test(ProductsManager::class)
            ->set("editingId", $other->id)->set("form.name", "Diretas")->call("save")
            ->assertNotFound();

        expect($other->fresh()->name)->toBe('Milik B');
    });

    // TC-KB-03: viewer hanya membaca.
    it('forbids viewers from every write action', function () {
        [$user, $tenant] = kbTenant(TenantMember::ROLE_VIEWER);
        $p = Product::factory()->create(['tenant_id' => $tenant->id]);

        Livewire::actingAs($user)->test(ProductsManager::class)->assertSee($p->name);

        // Komponen baru per aksi: setelah respons 403, harness Livewire tidak bisa dipakai ulang.
        $fresh = fn () => Livewire::actingAs($user)->test(ProductsManager::class);

        $fresh()->call('create')->assertForbidden();
        $fresh()->call('edit', $p->id)->assertForbidden();
        $fresh()->call('toggleActive', $p->id)->assertForbidden();
        $fresh()->call('delete', $p->id)->assertForbidden();
        $fresh()->set('form.name', 'x')->call('save')->assertForbidden();

        expect(Product::count())->toBe(1)->and($p->fresh()->is_active)->toBeTrue();
    });

    it('lets an admin member write', function () {
        [$user] = kbTenant(TenantMember::ROLE_ADMIN);

        Livewire::actingAs($user)->test(ProductsManager::class)
            ->call('create')->set('form.name', 'Dari admin')->call('save')->assertHasNoErrors();

        expect(Product::count())->toBe(1);
    });
});

// ---- CRUD layanan ----
describe('services CRUD', function () {
    it('creates a service with a price range and duration', function () {
        [$user, $tenant] = kbTenant();

        Livewire::actingAs($user)->test(ServicesManager::class)
            ->call('create')
            ->set('form.name', 'Potong Rambut')
            ->set('form.price_from', '30000')->set('form.price_to', '60000')
            ->set('form.duration_minutes', '45')
            ->call('save')->assertHasNoErrors();

        $s = CatalogService::first();
        expect($s->tenant_id)->toBe($tenant->id)->and($s->price_from)->toBe('30000.00')
            ->and($s->price_to)->toBe('60000.00')->and($s->duration_minutes)->toBe(45);
    });

    it('rejects price_to lower than price_from', function () {
        [$user] = kbTenant();

        Livewire::actingAs($user)->test(ServicesManager::class)
            ->call('create')->set('form.name', 'X')->set('form.price_from', '100')->set('form.price_to', '50')
            ->call('save')->assertHasErrors('form.price_to');
    });

    it('cannot touch a service of another tenant', function () {
        [$user] = kbTenant();
        $other = foreign(CatalogService::class);

        Livewire::actingAs($user)->test(ServicesManager::class)->call('delete', $other->id)->assertNotFound();

        expect($other->fresh())->not->toBeNull();
    });
});

// ---- CRUD FAQ ----
describe('FAQ CRUD', function () {
    it('creates, edits and deletes a FAQ', function () {
        [$user, $tenant] = kbTenant();

        $c = Livewire::actingAs($user)->test(FaqsManager::class)
            ->call('create')
            ->set('form.question', 'Apakah buka hari Minggu?')
            ->set('form.answer', 'Ya, 10.00-16.00')
            ->call('save')->assertHasNoErrors();

        $faq = Faq::first();
        expect($faq->tenant_id)->toBe($tenant->id)->and($faq->category)->toBeNull();

        $c->call('edit', $faq->id)->set('form.category', 'jam buka')->call('save');
        expect($faq->fresh()->category)->toBe('jam buka');

        $c->call('delete', $faq->id);
        expect(Faq::count())->toBe(0);
    });

    it('requires question and answer', function () {
        [$user] = kbTenant();

        Livewire::actingAs($user)->test(FaqsManager::class)
            ->call('create')->call('save')->assertHasErrors(['form.question', 'form.answer']);
    });

    it('cannot edit a FAQ of another tenant', function () {
        [$user] = kbTenant();
        $other = foreign(Faq::class);

        Livewire::actingAs($user)->test(FaqsManager::class)->call('edit', $other->id)->assertNotFound();
    });
});
