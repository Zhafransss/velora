<?php

use App\Filament\Resources\AuditLogs\Pages\ListAuditLogs;
use App\Filament\Resources\AuditLogs\Pages\ViewAuditLog;
use App\Filament\Resources\Subscriptions\Pages\ListSubscriptions;
use App\Filament\Resources\Tenants\Pages\ListTenants;
use App\Filament\Resources\Tenants\Pages\ViewTenant;
use App\Filament\Widgets\PlatformOverview;
use App\Models\AuditLog;
use App\Models\Bot;
use App\Models\Channel;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\TenantMember;
use App\Models\User;
use App\Services\Admin\SubscriptionService;
use App\Services\Admin\TenantStatusService;
use App\Services\Usage\QuotaManager;
use App\Support\Tenancy\CurrentTenant;
use Filament\Facades\Filament;
use Livewire\Livewire;

beforeEach(function () {
    Filament::setCurrentPanel(Filament::getPanel('admin'));
});

function paAdmin(): User
{
    return User::factory()->platformAdmin()->create(['email' => 'admin@velora.test']);
}

/** Workspace dengan pemilik; tanpa langganan kecuali diminta. */
function paTenant(string $name = 'Toko Maju', ?string $plan = null, string $ownerEmail = null): array
{
    $tenant = Tenant::factory()->create(['name' => $name]);
    $owner = User::factory()->create(['email' => $ownerEmail ?? strtolower(str_replace(' ', '', $name)) . '@example.com']);
    TenantMember::create(['tenant_id' => $tenant->id, 'user_id' => $owner->id, 'role' => 'owner']);
    $plan && Subscription::factory()->create(['tenant_id' => $tenant->id, 'plan_code' => $plan]);

    return [$tenant, $owner];
}

function svc(): SubscriptionService
{
    return app(SubscriptionService::class);
}

// ================= Layanan langganan =================
describe('SubscriptionService', function () {
    it('activates a plan for a period and writes an audit entry', function () {
        [$t] = paTenant();

        $s = svc()->activate($t, 'medium', 30, 'Transfer BCA INV-001');

        expect($s->status)->toBe('active')->and($s->plan_code)->toBe('medium')
            ->and($s->current_period_end->isSameDay(now()->addDays(30)))->toBeTrue()
            ->and($t->hasActiveSubscription())->toBeTrue()
            ->and(app(QuotaManager::class)->limitFor($t))->toBe(1000);

        $log = AuditLog::sole();
        expect($log->action)->toBe('subscription.activated')->and($log->actor_label)->toBe('cli')->and($log->tenant_id)->toBe($t->id)
            ->and($log->tenant_label)->toBe('Toko Maju')->and($log->note)->toBe('Transfer BCA INV-001')
            ->and($log->after['plan_code'])->toBe('medium')->and($log->subject_type)->toBe('Subscription');
    });

    it('replaces a running subscription but keeps its history', function () {
        [$t] = paTenant('X', 'small');

        svc()->activate($t, 'medium', 30);

        $all = $t->subscriptions()->orderBy('id')->get();
        expect($all->pluck('status')->all())->toBe(['canceled', 'active'])->and($all->pluck('plan_code')->all())->toBe(['small', 'medium'])
            ->and(app(QuotaManager::class)->limitFor($t))->toBe(1000)
            ->and(AuditLog::orderBy('id')->pluck('action')->all())->toBe(['subscription.status_changed', 'subscription.activated']);
    });

    it('rejects unknown plans and absurd durations', function () {
        [$t] = paTenant();

        expect(fn () => svc()->activate($t, 'platinum', 30))->toThrow(InvalidArgumentException::class, 'Unknown plan')
            ->and(fn () => svc()->activate($t, 'small', 0))->toThrow(InvalidArgumentException::class)
            ->and(fn () => svc()->activate($t, 'small', 4000))->toThrow(InvalidArgumentException::class);

        expect($t->subscriptions()->count())->toBe(0)->and(AuditLog::count())->toBe(0);
    });

    it('extends from the end date while valid, and from now when expired', function () {
        [$t] = paTenant();
        $s = svc()->activate($t, 'small', 10);

        $extended = svc()->extend($s, 20, 'Perpanjang');
        expect($extended->current_period_end->isSameDay(now()->addDays(30)))->toBeTrue();

        $expired = Subscription::factory()->expiredPeriod()->create(['tenant_id' => $t->id]);
        $renewed = svc()->extend($expired, 15);
        expect($renewed->status)->toBe('active')->and($renewed->current_period_end->isSameDay(now()->addDays(15)))->toBeTrue()
            ->and($renewed->isEntitled())->toBeTrue();

        $log = AuditLog::where('action', 'subscription.extended')->latest('id')->first();
        expect($log->before['period_end'])->not->toBe($log->after['period_end']);
    });

    it('re-activates a suspended subscription when extended', function () {
        [$t] = paTenant();
        $s = svc()->setStatus(svc()->activate($t, 'small', 30), 'suspended', 'Telat bayar');
        expect($t->hasActiveSubscription())->toBeFalse();

        svc()->extend($s, 30, 'Sudah bayar');

        expect($s->fresh()->status)->toBe('active')->and($t->fresh()->hasActiveSubscription())->toBeTrue();
    });

    it('locks features immediately on suspend/cancel and rejects unknown statuses', function () {
        [$t] = paTenant();
        $s = svc()->activate($t, 'small', 30);

        svc()->setStatus($s, 'suspended', 'Telat bayar');
        expect($t->hasActiveSubscription())->toBeFalse()->and(app(QuotaManager::class)->limitFor($t))->toBe(0);

        expect(fn () => svc()->setStatus($s, 'gratis-selamanya'))->toThrow(InvalidArgumentException::class);
    });

    it('appends dated notes instead of overwriting', function () {
        [$t] = paTenant();
        $s = svc()->activate($t, 'small', 30, 'Pertama');

        svc()->extend($s, 10, 'Kedua');

        expect($s->fresh()->notes)->toContain('Pertama')->toContain('Kedua')->toContain('[' . now()->format('d M Y') . ']');
    });

    it('records the logged-in admin as the actor', function () {
        [$t] = paTenant();
        $admin = paAdmin();
        $this->actingAs($admin);

        svc()->activate($t, 'small', 30);

        $log = AuditLog::sole();
        expect($log->actor_id)->toBe($admin->id)->and($log->actor_label)->toBe('admin@velora.test');
    });

    it('is used by the CLI command (supersedes + audit)', function () {
        [$t] = paTenant('Cli Co', 'small');

        $this->artisan('subscription:activate', ['tenant' => $t->slug, 'plan' => 'enterprise', '--days' => 5, '--notes' => 'via CLI'])->assertSuccessful();

        expect($t->subscriptions()->where('status', 'active')->sole()->plan_code)->toBe('enterprise')
            ->and(AuditLog::where('action', 'subscription.activated')->sole()->note)->toBe('via CLI');

        $this->artisan('subscription:activate', ['tenant' => $t->slug, 'plan' => 'gratis'])->assertFailed();
    });
});

describe('TenantStatusService', function () {
    it('suspends and reactivates a workspace with an audit trail', function () {
        [$t, $owner] = paTenant('Tutup Dulu', 'small');
        $svc = app(TenantStatusService::class);

        $svc->suspend($t, 'Pelanggaran ketentuan');
        expect($t->fresh()->status)->toBe('suspended')->and($t->fresh()->hasActiveSubscription())->toBeFalse();
        $this->actingAs($owner)->get('/app')->assertForbidden(); // tidak ada workspace aktif

        $svc->reactivate($t, 'Sudah dibereskan');
        expect($t->fresh()->status)->toBe('active')->and($t->fresh()->hasActiveSubscription())->toBeTrue();

        expect(AuditLog::orderBy('id')->pluck('action')->all())->toBe(['tenant.suspended', 'tenant.reactivated'])
            ->and(AuditLog::orderBy('id')->first()->before)->toBe(['status' => 'active'])->and(AuditLog::orderBy('id')->first()->after)->toBe(['status' => 'suspended']);
    });
});

describe('AuditLog', function () {
    it('is append-only', function () {
        [$t] = paTenant();
        svc()->activate($t, 'small', 1);
        $log = AuditLog::orderBy('id')->first();

        expect(fn () => $log->update(['note' => 'diubah']))->toThrow(LogicException::class)
            ->and(fn () => $log->delete())->toThrow(LogicException::class);
    });

    it('survives deletion of the tenant (history is kept)', function () {
        [$t] = paTenant('Akan Dihapus');
        svc()->activate($t, 'small', 1);

        $t->delete();

        $log = AuditLog::sole();
        expect($log->tenant_id)->toBeNull()->and($log->tenant_label)->toBe('Akan Dihapus');
    });
});

// ================= Akses =================
describe('access control', function () {
    it('allows only platform admins on the new admin pages', function (string $path) {
        paTenant('Akses', 'small');
        $tenantUser = User::factory()->create();

        $this->get($path)->assertRedirect();
        $this->actingAs($tenantUser)->get($path)->assertForbidden();
        $this->actingAs(paAdmin())->get($path)->assertOk();
    })->with(['/admin/tenants', '/admin/subscriptions', '/admin/audit-logs']);

    it('forbids tenant owners on a workspace detail page, even their own', function () {
        [$t, $owner] = paTenant('Milik Saya', 'small');

        $this->actingAs($owner)->get("/admin/tenants/{$t->id}")->assertForbidden();
        $this->actingAs(paAdmin())->get("/admin/tenants/{$t->id}")->assertOk()->assertSee('Milik Saya');
    });

    it('offers no create/edit/delete on read-only resources', function () {
        foreach ([\App\Filament\Resources\Tenants\TenantResource::class, \App\Filament\Resources\Subscriptions\SubscriptionResource::class, \App\Filament\Resources\AuditLogs\AuditLogResource::class] as $r) {
            expect($r::canCreate())->toBeFalse()->and($r::canEdit(new stdClass()))->toBeFalse()->and($r::canDelete(new stdClass()))->toBeFalse();
        }
    });

    it('registers the platform overview on the admin dashboard', function () {
        $this->actingAs(paAdmin())->get('/admin')->assertOk();

        // Widget dimuat malas (lazy) lewat Livewire, jadi periksa pendaftarannya, bukan teks pada HTML awal.
        expect(Filament::getPanel('admin')->getWidgets())->toContain(PlatformOverview::class);
    });
});

// ================= Daftar workspace =================
describe('workspace list', function () {
    it('shows every workspace with owner, subscription state and WhatsApp status (not tenant-scoped)', function () {
        [$a] = paTenant('Alpha', 'medium', 'alpha-owner@example.com');
        [$b] = paTenant('Beta');
        app(CurrentTenant::class)->runAs($a, function () use ($a) {
            $bot = Bot::factory()->create(['tenant_id' => $a->id]);
            Channel::factory()->create(['tenant_id' => $a->id, 'bot_id' => $bot->id, 'status' => 'connected']);
        });

        Livewire::actingAs(paAdmin())->test(ListTenants::class)
            ->assertCanSeeTableRecords([$a, $b])
            ->assertSee('alpha-owner@example.com')->assertSee('MEDIUM · active')->assertSee('Belum pernah berlangganan')->assertSee('connected');
    });

    it('searches by workspace name and by owner email', function () {
        [$a] = paTenant('Alpha', null, 'cari-saya@example.com');
        [$b] = paTenant('Beta');

        Livewire::actingAs(paAdmin())->test(ListTenants::class)
            ->searchTable('Beta')->assertCanSeeTableRecords([$b])->assertCanNotSeeTableRecords([$a])
            ->searchTable('cari-saya')->assertCanSeeTableRecords([$a])->assertCanNotSeeTableRecords([$b]);
    });

    it('filters by subscription state and workspace status', function () {
        [$active] = paTenant('Aktif', 'small');
        [$none] = paTenant('Kosong');
        [$expired] = paTenant('Habis');
        Subscription::factory()->expiredPeriod()->create(['tenant_id' => $expired->id]);
        [$suspended] = paTenant('Tangguh', 'small');
        $suspended->update(['status' => 'suspended']);

        $page = Livewire::actingAs(paAdmin())->test(ListTenants::class);

        $page->filterTable('subscription_state', 'entitled')->assertCanSeeTableRecords([$active, $suspended])->assertCanNotSeeTableRecords([$none, $expired])
            ->filterTable('subscription_state', 'none')->assertCanSeeTableRecords([$none])->assertCanNotSeeTableRecords([$active, $expired])
            ->filterTable('subscription_state', 'inactive')->assertCanSeeTableRecords([$expired])->assertCanNotSeeTableRecords([$active, $none])
            ->removeTableFilters()->filterTable('status', 'suspended')->assertCanSeeTableRecords([$suspended])->assertCanNotSeeTableRecords([$active]);
    });
});

// ================= Detail workspace & aksi =================
describe('workspace detail and actions', function () {
    it('shows members, plan, limit and usage', function () {
        [$t, $owner] = paTenant('Detail Co', 'medium', 'detail@example.com');
        app(QuotaManager::class)->reserve($t, 'k1');
        app(QuotaManager::class)->reserve($t, 'k2');

        $admin = paAdmin();
        Livewire::actingAs($admin)->test(ViewTenant::class, ['record' => $t->getKey()])
            ->assertSee('Detail Co')->assertSee('MEDIUM · active')->assertSee('2 / 1000');

        // Anggota tampil di tabel "Akun & anggota" (relation manager) pada halaman yang sama.
        Livewire::actingAs($admin)
            ->test(\App\Filament\Resources\Tenants\RelationManagers\MembersRelationManager::class, ['ownerRecord' => $t, 'pageClass' => ViewTenant::class])
            ->assertSee('detail@example.com')->assertSee('owner');
    });

    it('activates a subscription from the panel, audited under the admin', function () {
        [$t] = paTenant();
        $admin = paAdmin();

        Livewire::actingAs($admin)->test(ViewTenant::class, ['record' => $t->getKey()])
            ->callAction('activateSubscription', ['plan' => 'medium', 'days' => 45, 'notes' => 'INV-77'])
            ->assertHasNoActionErrors();

        $s = $t->subscriptions()->sole();
        expect($s->plan_code)->toBe('medium')->and($s->current_period_end->isSameDay(now()->addDays(45)))->toBeTrue()->and($t->fresh()->hasActiveSubscription())->toBeTrue();
        expect(AuditLog::sole())->toMatchArray(['actor_label' => 'admin@velora.test', 'actor_id' => $admin->id, 'action' => 'subscription.activated']);
    });

    it('validates the activation form', function (array $data, string $field) {
        [$t] = paTenant();

        Livewire::actingAs(paAdmin())->test(ViewTenant::class, ['record' => $t->getKey()])
            ->callAction('activateSubscription', $data)->assertHasActionErrors([$field]);

        expect($t->subscriptions()->count())->toBe(0);
    })->with([
        'no plan' => [['plan' => null, 'days' => 30], 'plan'],
        'unknown plan' => [['plan' => 'platinum', 'days' => 30], 'plan'],
        'zero days' => [['plan' => 'small', 'days' => 0], 'days'],
        'too many days' => [['plan' => 'small', 'days' => 99999], 'days'],
    ]);

    it('suspends a workspace only with a reason, and offers reactivation afterwards', function () {
        [$t] = paTenant('Target', 'small');
        $admin = paAdmin();
        $page = fn () => Livewire::actingAs($admin)->test(ViewTenant::class, ['record' => $t->getKey()]);

        $page()->assertActionVisible('suspendTenant')->assertActionHidden('reactivateTenant');

        // Instance baru per aksi: setelah galat validasi, modal sebelumnya masih terbuka pada instance yang sama.
        $page()->callAction('suspendTenant', ['note' => ''])->assertHasActionErrors(['note']);
        expect($t->fresh()->status)->toBe('active');

        $page()->callAction('suspendTenant', ['note' => 'Penyalahgunaan'])->assertHasNoActionErrors();
        expect($t->fresh()->status)->toBe('suspended')->and(AuditLog::where('action', 'tenant.suspended')->sole()->note)->toBe('Penyalahgunaan');

        $page()->assertActionHidden('suspendTenant')->assertActionVisible('reactivateTenant');
        $page()->callAction('reactivateTenant', ['note' => 'OK'])->assertHasNoActionErrors();
        expect($t->fresh()->status)->toBe('active');
    });
});

// ================= Daftar langganan =================
describe('subscription list', function () {
    it('lists subscriptions of all workspaces and extends one', function () {
        [$a] = paTenant('Alpha');
        [$b] = paTenant('Beta');
        $sa = svc()->activate($a, 'small', 10);
        $sb = svc()->activate($b, 'medium', 10);

        $page = Livewire::actingAs(paAdmin())->test(ListSubscriptions::class)
            ->assertCanSeeTableRecords([$sa, $sb])->assertSee('Alpha')->assertSee('Beta');

        $page->callTableAction('extend', $sa, ['days' => 20, 'notes' => 'bayar lagi'])->assertHasNoTableActionErrors();

        expect($sa->fresh()->current_period_end->isSameDay(now()->addDays(30)))->toBeTrue()
            ->and($sb->fresh()->current_period_end->isSameDay(now()->addDays(10)))->toBeTrue();
    });

    it('suspends and cancels with a mandatory reason, and hides actions that make no sense', function () {
        [$t] = paTenant();
        $s = svc()->activate($t, 'small', 30);
        $admin = paAdmin();
        $page = fn () => Livewire::actingAs($admin)->test(ListSubscriptions::class);

        $page()->callTableAction('suspend', $s, ['notes' => ''])->assertHasTableActionErrors(['notes']);
        expect($s->fresh()->status)->toBe('active');

        $page()->callTableAction('suspend', $s, ['notes' => 'Belum bayar']);
        expect($s->fresh()->status)->toBe('suspended')->and($t->fresh()->hasActiveSubscription())->toBeFalse();
        $page()->assertTableActionHidden('suspend', $s);

        $page()->callTableAction('cancel', $s, ['notes' => 'Berhenti berlangganan']);
        expect($s->fresh()->status)->toBe('canceled');
        $page()->assertTableActionHidden('cancel', $s);
    });

    it('audits every panel action', function () {
        [$t] = paTenant();
        $s = svc()->activate($t, 'small', 30);
        $admin = paAdmin();

        Livewire::actingAs($admin)->test(ListSubscriptions::class)
            ->callTableAction('extend', $s, ['days' => 5])->callTableAction('cancel', $s, ['notes' => 'tutup']);

        expect(AuditLog::orderBy('id')->pluck('action')->all())->toBe(['subscription.activated', 'subscription.extended', 'subscription.status_changed']);
        expect(AuditLog::where('action', '!=', 'subscription.activated')->pluck('actor_label')->unique()->all())->toBe(['admin@velora.test']);
    });
});

// ================= Audit log =================
describe('audit log page', function () {
    it('lists newest first and shows before/after on the detail page', function () {
        [$t] = paTenant('Audit Co');
        $s = svc()->activate($t, 'small', 30);
        svc()->setStatus($s, 'suspended', 'Telat bayar');
        $latest = AuditLog::latest('id')->first();

        $admin = paAdmin();

        Livewire::actingAs($admin)->test(ListAuditLogs::class)
            ->assertCanSeeTableRecords(AuditLog::all())->assertSeeInOrder(['subscription.status_changed', 'subscription.activated']);

        Livewire::actingAs($admin)->test(ViewAuditLog::class, ['record' => $latest->getKey()])
            ->assertSee('Audit Co')->assertSee('Telat bayar')->assertSee('"status": "active"')->assertSee('"status": "suspended"');
    });

    it('filters by action', function () {
        [$t] = paTenant();
        $s = svc()->activate($t, 'small', 30);
        svc()->setStatus($s, 'suspended', 'x');
        $activated = AuditLog::where('action', 'subscription.activated')->first();
        $changed = AuditLog::where('action', 'subscription.status_changed')->first();

        Livewire::actingAs(paAdmin())->test(ListAuditLogs::class)
            ->filterTable('action', 'subscription.activated')->assertCanSeeTableRecords([$activated])->assertCanNotSeeTableRecords([$changed]);
    });
});

// ================= Widget =================
describe('platform overview widget', function () {
    it('counts workspaces, expiring subscriptions, AI usage, broken channels, queue and stuck messages', function () {
        [$a] = paTenant('A', 'small');
        [$b] = paTenant('B');
        Subscription::factory()->create(['tenant_id' => $b->id, 'current_period_end' => now()->addDays(3)]); // berakhir <= 7 hari
        app(QuotaManager::class)->reserve($a, 'u1');
        app(QuotaManager::class)->reserve($a, 'u2');

        $conv = null;
        app(CurrentTenant::class)->runAs($a, function () use ($a, &$conv) {
            $bot = Bot::factory()->create(['tenant_id' => $a->id]);
            $ch = Channel::factory()->create(['tenant_id' => $a->id, 'bot_id' => $bot->id, 'status' => 'logged_out']);
            $conv = \App\Models\Conversation::create(['bot_id' => $bot->id, 'channel_id' => $ch->id, 'customer_external_id' => '62811@s.whatsapp.net']);
            $m = \App\Models\Message::create(['conversation_id' => $conv->id, 'direction' => 'inbound', 'external_message_id' => 'S1', 'body' => 'x', 'status' => 'received']);
            \Illuminate\Support\Facades\DB::table('messages')->where('id', $m->id)->update(['updated_at' => now()->subMinutes(30)]);
        });
        \Illuminate\Support\Facades\DB::table('failed_jobs')->insert(['uuid' => (string) \Illuminate\Support\Str::uuid(), 'connection' => 'database', 'queue' => 'default', 'payload' => '{}', 'exception' => 'x', 'failed_at' => now()]);

        $html = Livewire::actingAs(paAdmin())->test(PlatformOverview::class)->html();
        $text = html_entity_decode(trim(preg_replace('/\s+/', ' ', strip_tags($html))));

        expect($text)->toContain('Workspace')->toContain('2')
            ->toContain('Langganan berakhir ≤ 7 hari')->toContain('Pesan AI hari ini')
            ->toContain('Kanal WhatsApp bermasalah')->toContain('1 job gagal')
            ->toContain('Pesan menggantung > 10 menit')->toContain('Periksa queue worker');
    });
});
