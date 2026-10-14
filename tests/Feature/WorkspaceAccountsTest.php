<?php

use App\Filament\Resources\Accounts\Pages\CreateAccount;
use App\Filament\Resources\Accounts\Pages\EditAccount;
use App\Filament\Resources\Accounts\Pages\ListAccounts;
use App\Filament\Resources\Tenants\Pages\ViewTenant;
use App\Filament\Resources\Tenants\RelationManagers\MembersRelationManager;
use App\Models\AuditLog;
use App\Models\Tenant;
use App\Models\TenantMember;
use App\Models\User;
use App\Notifications\AccountInvitation;
use App\Services\Admin\WorkspaceAccountException;
use App\Services\Admin\WorkspaceAccountService;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Livewire\Livewire;

beforeEach(function () {
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Notification::fake();
});

function waAdmin(): User
{
    return User::factory()->platformAdmin()->create(['email' => 'admin@velora.test']);
}

/** Workspace dengan satu owner terverifikasi. */
function waTenant(string $name = 'Toko Maju'): array
{
    $tenant = Tenant::factory()->create(['name' => $name]);
    $owner = User::factory()->create(['email' => 'owner@' . strtolower(str_replace(' ', '', $name)) . '.test']);
    TenantMember::create(['tenant_id' => $tenant->id, 'user_id' => $owner->id, 'role' => TenantMember::ROLE_OWNER]);

    return [$tenant, $owner];
}

function waMember(Tenant $tenant, string $role, array $attrs = []): User
{
    $user = User::factory()->create($attrs);
    TenantMember::create(['tenant_id' => $tenant->id, 'user_id' => $user->id, 'role' => $role]);

    return $user;
}

function waService(): WorkspaceAccountService
{
    return app(WorkspaceAccountService::class);
}

/** Token undangan yang terkirim ke $user (diambil dari notifikasi palsu). */
function waInviteToken(User $user): string
{
    $token = null;
    Notification::assertSentTo($user, AccountInvitation::class, function (AccountInvitation $n) use (&$token) {
        $token = $n->token;

        return true;
    });

    return $token;
}

// ================= Membuat akun + undangan =================
describe('create and invite', function () {
    it('creates an account in a workspace, emails an invitation and audits it under the admin', function () {
        [$tenant] = waTenant();
        $admin = waAdmin();

        Livewire::actingAs($admin)->test(CreateAccount::class)
            ->fillForm(['name' => 'Sari CS', 'email' => 'Sari@Example.com', 'tenant_id' => $tenant->id, 'role' => 'admin'])
            ->call('create')
            ->assertHasNoFormErrors();

        $user = User::where('email', 'sari@example.com')->sole();
        expect($user->is_platform_admin)->toBeFalse()
            ->and($user->email_verified_at)->toBeNull()
            ->and($user->isDisabled())->toBeFalse()
            ->and(TenantMember::where('user_id', $user->id)->sole()->only('tenant_id', 'role'))->toBe(['tenant_id' => $tenant->id, 'role' => 'admin']);

        Notification::assertSentTo($user, AccountInvitation::class, fn (AccountInvitation $n) => $n->tenant->is($tenant) && $n->role === 'admin');
        expect(AuditLog::where('actor_id', $admin->id)->pluck('action')->all())->toBe(['account.created', 'account.invitation_sent']);
    });

    it('renders the invitation email in Indonesian with a link to the invitation page', function () {
        [$tenant] = waTenant();
        $user = waService()->create($tenant, 'Sari', 'sari@example.com', 'viewer');

        $mail = (new AccountInvitation('tok123', $tenant, 'viewer'))->toMail($user);

        expect($mail->subject)->toBe('Akun Velora AI Anda untuk Toko Maju')
            ->and($mail->actionUrl)->toContain('/invitation/tok123')->toContain('email=sari%40example.com')
            ->and(implode(' ', $mail->introLines))->toContain('Viewer (hanya lihat)')
            ->and(implode(' ', $mail->outroLines))->toContain('72 jam');
    });

    it('prefills the workspace from the workspace page link', function () {
        [$tenant] = waTenant();

        $this->actingAs(waAdmin())->get(route('filament.admin.resources.accounts.create', ['tenant' => $tenant->id]))->assertOk();
        Livewire::withQueryParams(['tenant' => $tenant->id])->actingAs(User::where('is_platform_admin', true)->first())
            ->test(CreateAccount::class)->assertSchemaStateSet(['tenant_id' => $tenant->id]);
    });

    it('offers only active workspaces, labelled with the owner email to tell same-named ones apart', function () {
        [$active, $activeOwner] = waTenant('psp');
        $suspended = Tenant::factory()->create(['name' => 'psp', 'status' => Tenant::STATUS_SUSPENDED]);
        waMember($suspended, 'owner', ['email' => 'lama@psp.test']);

        Livewire::actingAs(waAdmin())->test(CreateAccount::class)
            ->assertFormFieldExists('tenant_id', fn (Select $field) => $field->getOptions() === [$active->id => 'psp — ' . $activeOwner->email]);
    });

    it('refuses to create an account in a suspended workspace, even when its id is submitted directly', function () {
        $suspended = Tenant::factory()->create(['name' => 'Beku', 'status' => Tenant::STATUS_SUSPENDED]);

        // Lewat form: nilai di luar pilihan ditolak validasi.
        Livewire::actingAs(waAdmin())->withQueryParams(['tenant' => $suspended->id])->test(CreateAccount::class)
            ->fillForm(['name' => 'X', 'email' => 'x@example.com', 'tenant_id' => $suspended->id, 'role' => 'admin'])
            ->call('create')
            ->assertHasFormErrors(['tenant_id']);

        // Lewat service (mis. kode lain): ditolak dengan pesan yang jelas.
        expect(fn () => waService()->create($suspended, 'X', 'x@example.com', 'admin'))
            ->toThrow(WorkspaceAccountException::class, 'Workspace Beku sedang ditangguhkan');
        expect(User::where('email', 'x@example.com')->exists())->toBeFalse();
        Notification::assertNothingSent();
    });

    it('disables "Tambah akun" on a suspended workspace page', function () {
        [$tenant] = waTenant();
        $suspended = Tenant::factory()->create(['status' => Tenant::STATUS_SUSPENDED]);
        $admin = waAdmin();
        $rm = fn (Tenant $t) => Livewire::actingAs($admin)->test(MembersRelationManager::class, ['ownerRecord' => $t, 'pageClass' => ViewTenant::class]);

        $rm($tenant)->assertTableActionEnabled('addAccount');
        $rm($suspended)->assertTableActionDisabled('addAccount');
    });

    it('rejects a duplicate email and an unknown role', function () {
        [$tenant, $owner] = waTenant();

        Livewire::actingAs(waAdmin())->test(CreateAccount::class)
            ->fillForm(['name' => 'X', 'email' => $owner->email, 'tenant_id' => $tenant->id, 'role' => 'admin'])
            ->call('create')
            ->assertHasFormErrors(['email' => 'unique']);

        expect(fn () => waService()->create($tenant, 'X', 'x@example.com', 'superadmin'))->toThrow(WorkspaceAccountException::class);
        expect(User::count())->toBe(2); // owner + admin
    });

    it('gives up on an unreachable SMTP server after 10 seconds instead of PHP\'s 60', function () {
        expect(config('mail.mailers.smtp.timeout'))->toBe(10);
    });

    it('keeps the account and reports when the invitation email cannot be sent, then allows an immediate resend', function () {
        [$tenant] = waTenant();
        $user = waService()->create($tenant, 'Sari', 'sari@example.com', 'admin');
        Notification::shouldReceive('send')->once()->andThrow(new RuntimeException('SMTP down'));

        expect(fn () => waService()->sendInvitation($user))->toThrow(RuntimeException::class);
        expect(DB::table('account_invitation_tokens')->count())->toBe(0); // tidak tertahan batas kirim ulang
    });
});

// ================= Membuat password lewat undangan =================
describe('accepting an invitation', function () {
    it('lets the invitee set a password, verifies the email, and log in to the workspace', function () {
        [$tenant] = waTenant();
        $user = waService()->create($tenant, 'Sari', 'sari@example.com', 'admin');
        waService()->sendInvitation($user);
        $token = waInviteToken($user);

        $this->get(route('invitation.show', ['token' => $token, 'email' => $user->email]))->assertOk()->assertSee('Buat password');

        $this->post(route('invitation.store'), ['token' => $token, 'email' => 'sari@example.com', 'password' => 'rahasia-baru-1', 'password_confirmation' => 'rahasia-baru-1'])
            ->assertRedirect(route('login'));

        expect($user->fresh()->email_verified_at)->not->toBeNull();

        $this->post('/login', ['email' => 'sari@example.com', 'password' => 'rahasia-baru-1'])->assertRedirect(route('app.dashboard'));
        $this->get(route('app.dashboard'))->assertOk();
    });

    it('checks "Ulangi password" live on every password form and explains mismatches in Indonesian', function () {
        [$tenant] = waTenant();
        $user = waService()->create($tenant, 'Sari', 'sari@example.com', 'admin');
        waService()->sendInvitation($user);
        $token = waInviteToken($user);

        // Petunjuk langsung (JS) di ketiga form yang punya kolom ulangi password.
        foreach ([route('invitation.show', ['token' => $token]), route('password.reset', ['token' => 'x']), route('register')] as $url) {
            $this->get($url)->assertOk()
                ->assertSee('id="password_confirmation_hint"', false)
                ->assertSee("setCustomValidity(same ? '' : 'Password tidak sama.')", false);
        }

        // Pengaman server bila JS mati.
        $post = fn (string $password, string $confirmation) => $this->post(route('invitation.store'),
            ['token' => $token, 'email' => $user->email, 'password' => $password, 'password_confirmation' => $confirmation]);

        $post('rahasia-baru-1', 'rahasia-baru-2')->assertSessionHasErrors(['password' => 'Ulangi password tidak sama dengan password.']);
        $post('pendek', 'pendek')->assertSessionHasErrors(['password' => 'Password minimal 8 karakter.']);
        expect(DB::table('account_invitation_tokens')->count())->toBe(1); // token tidak terpakai oleh percobaan gagal

        $this->post(route('password.update'), ['token' => 'x', 'email' => 'sari@example.com', 'password' => 'rahasia-baru-1', 'password_confirmation' => 'beda-sekali-1'])
            ->assertSessionHasErrors(['password' => 'Ulangi password tidak sama dengan password.']);
        $this->post(route('register'), ['name' => 'Budi', 'workspace_name' => 'Toko', 'email' => 'budi@example.com', 'password' => 'rahasia-baru-1', 'password_confirmation' => 'beda-sekali-1'])
            ->assertSessionHasErrors(['password' => 'Ulangi password tidak sama dengan password.']);
    });

    it('rejects expired, reused, or password-reset tokens', function () {
        [$tenant] = waTenant();
        $user = waService()->create($tenant, 'Sari', 'sari@example.com', 'admin');
        waService()->sendInvitation($user);
        $token = waInviteToken($user);
        $form = fn (string $t) => ['token' => $t, 'email' => $user->email, 'password' => 'rahasia-baru-1', 'password_confirmation' => 'rahasia-baru-1'];

        // Kedaluwarsa setelah 72 jam.
        $this->travel(73)->hours();
        $this->post(route('invitation.store'), $form($token))->assertSessionHasErrors('email');
        $this->travelBack();

        // Token reset password biasa tidak berlaku di halaman undangan (tabel terpisah).
        $resetToken = Password::broker('users')->createToken($user);
        $this->post(route('invitation.store'), $form($resetToken))->assertSessionHasErrors('email');

        // Token undangan tidak berlaku di halaman reset password biasa.
        DB::table('account_invitation_tokens')->delete();
        waService()->sendInvitation($user->fresh());
        $fresh = null;
        Notification::assertSentTo($user, AccountInvitation::class, function ($n) use (&$fresh) { $fresh = $n->token; return true; });
        $this->post(route('password.update'), $form($fresh))->assertSessionHasErrors('email');

        // Sekali pakai.
        $this->post(route('invitation.store'), $form($fresh))->assertRedirect(route('login'));
        $this->post(route('invitation.store'), $form($fresh))->assertSessionHasErrors('email');
    });

    it('does not invite or accept invitations for disabled accounts', function () {
        [$tenant] = waTenant();
        $user = waService()->create($tenant, 'Sari', 'sari@example.com', 'admin');
        waService()->sendInvitation($user);
        $token = waInviteToken($user);
        waService()->disable($user);

        expect(fn () => waService()->sendInvitation($user->fresh()))->toThrow(WorkspaceAccountException::class);
        $this->post(route('invitation.store'), ['token' => $token, 'email' => $user->email, 'password' => 'rahasia-baru-1', 'password_confirmation' => 'rahasia-baru-1'])
            ->assertSessionHasErrors('email');
    });
});

// ================= Mengubah akun =================
describe('editing', function () {
    it('updates name, email and role; a new email must be verified again', function () {
        [$tenant] = waTenant();
        $user = waMember($tenant, 'viewer', ['email' => 'lama@example.com']);

        Livewire::actingAs(waAdmin())->test(EditAccount::class, ['record' => $user->getKey()])
            ->assertSchemaStateSet(['tenant_id' => $tenant->id, 'role' => 'viewer'])
            ->fillForm(['name' => 'Nama Baru', 'email' => 'baru@example.com', 'role' => 'admin'])
            ->call('save')
            ->assertHasNoFormErrors();

        $user->refresh();
        expect([$user->name, $user->email, $user->email_verified_at])->toBe(['Nama Baru', 'baru@example.com', null])
            ->and(TenantMember::where('user_id', $user->id)->value('role'))->toBe('admin');

        $log = AuditLog::where('action', 'account.updated')->sole();
        expect($log->before['role'])->toBe('viewer')->and($log->after['role'])->toBe('admin')->and($log->after['email'])->toBe('baru@example.com');
    });

    it('keeps the verification when the email is unchanged', function () {
        [$tenant, $owner] = waTenant();

        waService()->update($owner, 'Owner Baru', $owner->email, 'owner');

        expect($owner->fresh()->email_verified_at)->not->toBeNull();
    });

    it('refuses to demote the last active owner, but allows it when another active owner exists', function () {
        [$tenant, $owner] = waTenant();

        Livewire::actingAs(waAdmin())->test(EditAccount::class, ['record' => $owner->getKey()])
            ->fillForm(['role' => 'admin'])->call('save')->assertNotified('Perubahan tidak disimpan');
        expect(TenantMember::where('user_id', $owner->id)->value('role'))->toBe('owner');

        $disabledOwner = waMember($tenant, 'owner');
        waService()->disable($disabledOwner);
        expect(fn () => waService()->update($owner, $owner->name, $owner->email, 'admin'))->toThrow(WorkspaceAccountException::class);

        waMember($tenant, 'owner');
        waService()->update($owner, $owner->name, $owner->email, 'admin');
        expect(TenantMember::where('user_id', $owner->id)->value('role'))->toBe('admin');
    });

    it('does not let the workspace be changed after creation', function () {
        [$tenant] = waTenant();
        [$other] = waTenant('Lain');
        $user = waMember($tenant, 'viewer');

        Livewire::actingAs(waAdmin())->test(EditAccount::class, ['record' => $user->getKey()])
            ->fillForm(['tenant_id' => $other->id, 'role' => 'viewer'])->call('save');

        expect(TenantMember::where('user_id', $user->id)->value('tenant_id'))->toBe($tenant->id);
    });
});

// ================= Nonaktifkan / aktifkan =================
describe('disabling', function () {
    it('blocks login only after a correct password, ends active sessions, and can be re-enabled', function () {
        [$tenant] = waTenant();
        $user = waMember($tenant, 'admin', ['email' => 'cs@example.com', 'password' => 'rahasia-123']);
        $admin = waAdmin();

        // Sesi aktif sebelum dinonaktifkan.
        $this->post('/login', ['email' => 'cs@example.com', 'password' => 'rahasia-123'])->assertRedirect(route('app.dashboard'));
        $rememberBefore = $user->fresh()->remember_token;

        Livewire::actingAs($admin)->test(ListAccounts::class)
            ->callTableAction('disableAccount', $user, ['note' => 'Karyawan keluar'])
            ->assertHasNoTableActionErrors();

        $user->refresh();
        expect($user->isDisabled())->toBeTrue()->and($user->remember_token)->not->toBe($rememberBefore);
        expect(AuditLog::where('action', 'account.disabled')->sole()->note)->toBe('Karyawan keluar');

        // Sesi lama langsung keluar.
        $this->actingAs($user)->get(route('app.dashboard'))->assertRedirect(route('login'));
        $this->assertGuest();

        // Login: pesan khusus hanya bila password benar.
        $this->post('/login', ['email' => 'cs@example.com', 'password' => 'salah-sekali'])
            ->assertSessionHasErrors(['email' => 'Email atau password salah.']);
        $this->post('/login', ['email' => 'cs@example.com', 'password' => 'rahasia-123'])
            ->assertSessionHasErrors(['email' => 'Akun ini dinonaktifkan. Hubungi admin Velora.']);
        $this->assertGuest();

        Livewire::actingAs($admin)->test(ListAccounts::class)->callTableAction('enableAccount', $user->fresh(), ['note' => null]);
        expect($user->fresh()->isDisabled())->toBeFalse();
        $this->post('/login', ['email' => 'cs@example.com', 'password' => 'rahasia-123'])->assertRedirect(route('app.dashboard'));
    });

    it('registers the account check as persistent Livewire middleware (component actions are checked too)', function () {
        expect(Livewire::getPersistentMiddleware())->toContain(App\Http\Middleware\EnsureAccountActive::class);
    });

    it('deletes database sessions of the disabled account', function () {
        config(['session.driver' => 'database']);
        [$tenant] = waTenant();
        $user = waMember($tenant, 'admin');
        DB::table('sessions')->insert(['id' => 'abc', 'user_id' => $user->id, 'payload' => 'x', 'last_activity' => time()]);
        DB::table('sessions')->insert(['id' => 'other', 'user_id' => null, 'payload' => 'x', 'last_activity' => time()]);

        waService()->disable($user);

        expect(DB::table('sessions')->pluck('id')->all())->toBe(['other']);
    });
});

// ================= Hapus permanen =================
describe('deleting', function () {
    it('refuses to delete the last active owner', function () {
        [$tenant, $owner] = waTenant();

        Livewire::actingAs(waAdmin())->test(ListAccounts::class)
            ->callTableAction('deleteAccount', $owner, ['note' => 'tes'])
            ->assertNotified('Tidak bisa diproses');

        expect(User::find($owner->id))->not->toBeNull()->and(AuditLog::where('action', 'account.deleted')->exists())->toBeFalse();
    });

    it('deletes a member permanently with its membership, keeping the audit trail', function () {
        [$tenant] = waTenant();
        $user = waMember($tenant, 'viewer', ['email' => 'hapus@example.com']);
        $admin = waAdmin();

        Livewire::actingAs($admin)->test(EditAccount::class, ['record' => $user->getKey()])
            ->callAction('deleteAccount', ['note' => 'Permintaan pelanggan'])
            ->assertRedirect(route('filament.admin.resources.accounts.index'));

        expect(User::find($user->id))->toBeNull()->and(TenantMember::where('user_id', $user->id)->exists())->toBeFalse();
        $log = AuditLog::where('action', 'account.deleted')->sole();
        expect($log->before['email'])->toBe('hapus@example.com')->and($log->tenant_id)->toBe($tenant->id)->and($log->actor_id)->toBe($admin->id);
    });

    it('deletes an owner when another active owner remains', function () {
        [$tenant, $owner] = waTenant();
        waMember($tenant, 'owner');

        waService()->delete($owner, 'pindah tangan');

        expect(User::find($owner->id))->toBeNull()->and($tenant->members()->where('role', 'owner')->count())->toBe(1);
    });

    it('has no built-in or bulk delete', function () {
        [$tenant] = waTenant();
        $user = waMember($tenant, 'viewer');

        Livewire::actingAs(waAdmin())->test(ListAccounts::class)
            ->assertTableActionDoesNotExist('delete')->assertTableBulkActionDoesNotExist('delete');
        Livewire::actingAs(User::where('is_platform_admin', true)->first())->test(EditAccount::class, ['record' => $user->getKey()])
            ->assertActionDoesNotExist('delete');
    });
});

// ================= Batas cakupan & akses =================
describe('scope and access', function () {
    it('never lists or edits platform admin accounts', function () {
        [$tenant, $owner] = waTenant();
        $admin = waAdmin();
        $otherAdmin = User::factory()->platformAdmin()->create();

        Livewire::actingAs($admin)->test(ListAccounts::class)
            ->assertCanSeeTableRecords([$owner])->assertCanNotSeeTableRecords([$admin, $otherAdmin]);

        $this->actingAs($admin)->get(route('filament.admin.resources.accounts.edit', ['record' => $otherAdmin]))->assertNotFound();

        foreach (['disable', 'enable', 'delete', 'sendInvitation'] as $method) {
            expect(fn () => waService()->{$method}($otherAdmin))->toThrow(WorkspaceAccountException::class);
        }
        expect(fn () => waService()->update($otherAdmin, 'x', 'x@example.com', 'owner'))->toThrow(WorkspaceAccountException::class);
    });

    it('cannot be opened by workspace users', function () {
        [, $owner] = waTenant();

        $this->actingAs($owner)->get(route('filament.admin.resources.accounts.index'))->assertForbidden();
    });

    it('filters accounts by workspace, role and status', function () {
        [$a, $ownerA] = waTenant('Alfa');
        [$b, $ownerB] = waTenant('Beta');
        $pending = waService()->create($a, 'Baru', 'baru@example.com', 'viewer');
        $disabled = waMember($b, 'admin');
        waService()->disable($disabled);

        Livewire::actingAs(waAdmin())->test(ListAccounts::class)
            ->filterTable('workspace', $a->id)->assertCanSeeTableRecords([$ownerA, $pending])->assertCanNotSeeTableRecords([$ownerB, $disabled])
            ->removeTableFilters()->filterTable('role', 'viewer')->assertCanSeeTableRecords([$pending])->assertCanNotSeeTableRecords([$ownerA, $disabled])
            ->removeTableFilters()->filterTable('status', 'pending')->assertCanSeeTableRecords([$pending])->assertCanNotSeeTableRecords([$ownerA, $disabled])
            ->removeTableFilters()->filterTable('status', 'disabled')->assertCanSeeTableRecords([$disabled])->assertCanNotSeeTableRecords([$pending, $ownerB]);
    });

    it('shows workspace members with links to manage them on the workspace page', function () {
        [$tenant, $owner] = waTenant();
        $cs = waMember($tenant, 'viewer', ['email' => 'cs@tokomaju.test']);

        Livewire::actingAs(waAdmin())
            ->test(MembersRelationManager::class, ['ownerRecord' => $tenant, 'pageClass' => ViewTenant::class])
            ->assertCanSeeTableRecords(TenantMember::where('tenant_id', $tenant->id)->get())
            ->assertSee('cs@tokomaju.test')->assertSee('Viewer (hanya lihat)')
            ->assertTableActionHasUrl('manage', route('filament.admin.resources.accounts.edit', ['record' => $cs->id]), TenantMember::where('user_id', $cs->id)->first())
            ->assertTableHeaderActionsExistInOrder(['addAccount']);
    });

    it('does not let a password reset bypass a disabled account login', function () {
        [$tenant] = waTenant();
        $user = waMember($tenant, 'admin', ['email' => 'cs@example.com']);
        waService()->disable($user);

        $token = Password::broker('users')->createToken($user->fresh());
        $this->post(route('password.update'), ['token' => $token, 'email' => 'cs@example.com', 'password' => 'rahasia-baru-1', 'password_confirmation' => 'rahasia-baru-1']);

        $this->post('/login', ['email' => 'cs@example.com', 'password' => 'rahasia-baru-1'])
            ->assertSessionHasErrors(['email' => 'Akun ini dinonaktifkan. Hubungi admin Velora.']);
        $this->assertGuest();
    });
});
