<?php

use App\Models\User;
use Illuminate\Support\Facades\Schema;

// Regresi website publik: fitur SaaS tidak boleh merusak halaman lama.
it('serves public website pages', function (string $path) {
    $this->get($path)->assertOk();
})->with(['/', '/about-us', '/contact', '/services', '/portfolio']);

// /admin hanya untuk admin platform (FR-003, TC-ISO-02).
it('redirects guests from /admin to the admin login', function () {
    $this->get('/admin')->assertRedirect();
});

it('forbids tenant users from /admin and its resources', function (string $path) {
    $this->actingAs(User::factory()->create())->get($path)->assertForbidden();
})->with(['/admin', '/admin/invoices', '/admin/articles', '/admin/contact-messages']);

it('lets a platform admin open the invoice list', function () {
    $this->actingAs(User::factory()->platformAdmin()->create())->get('/admin/invoices')->assertOk();
});

it('protects the invoice PDF route from guests and tenant users', function () {
    $this->get('/admin/invoices/1/pdf')->assertRedirect();
    $this->actingAs(User::factory()->create())->get('/admin/invoices/1/pdf')->assertForbidden();
});

// NFR-010 / TC-MIGR-01: migrasi SaaS tidak mengubah tabel website.
it('does not add tenancy columns to website tables', function (string $table) {
    expect(Schema::hasTable($table))->toBeTrue()
        ->and(Schema::hasColumn($table, 'tenant_id'))->toBeFalse();
})->with(['categories', 'portfolios', 'portfolio_images', 'services', 'service_packages', 'service_features', 'articles', 'contact_messages', 'invoices']);

it('only extends the users table with is_platform_admin and admin MFA columns', function () {
    expect(Schema::getColumnListing('users'))->toEqualCanonicalizing([
        'id', 'name', 'email', 'email_verified_at', 'password', 'is_platform_admin',
        'app_authentication_secret', 'app_authentication_recovery_codes',
        'remember_token', 'created_at', 'updated_at',
    ]);
});
