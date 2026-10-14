<?php

namespace App\Services\Admin;

use App\Models\Tenant;
use App\Models\TenantMember;
use App\Models\User;
use App\Notifications\AccountInvitation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Throwable;

/**
 * Pengelolaan akun workspace oleh admin platform (FR-014, DEC-20): buat + undang lewat email, ubah, nonaktifkan,
 * aktifkan kembali, hapus permanen. Semua tindakan dicatat di audit log.
 *
 * Aturan:
 *  - Akun admin platform tidak pernah dikelola di sini (mencegah kenaikan hak akses lewat form).
 *  - Workspace harus selalu punya owner aktif: owner aktif terakhir tidak bisa diturunkan perannya atau dihapus.
 *  - Admin tidak pernah mengetahui password pelanggan; password dibuat sendiri lewat tautan undangan.
 *  - MVP: satu workspace per akun (FR-002), jadi workspace ditentukan saat dibuat dan tidak dipindah.
 */
class WorkspaceAccountService
{
    public const ROLES = [TenantMember::ROLE_OWNER, TenantMember::ROLE_ADMIN, TenantMember::ROLE_VIEWER];

    public function __construct(private AuditLogger $audit)
    {
    }

    /** Membuat akun tanpa password yang bisa dipakai; panggil sendInvitation() agar pemiliknya membuat password. */
    public function create(Tenant $tenant, string $name, string $email, string $role, ?string $note = null): User
    {
        $this->assertRole($role);
        $email = $this->normalizeEmail($email);

        if ($tenant->status !== Tenant::STATUS_ACTIVE) {
            throw new WorkspaceAccountException("Workspace {$tenant->name} sedang ditangguhkan. Aktifkan kembali workspace terlebih dahulu.");
        }

        if (User::where('email', $email)->exists()) {
            throw new WorkspaceAccountException('Email sudah terdaftar.');
        }

        $user = DB::transaction(function () use ($tenant, $name, $email, $role) {
            $user = new User();
            // Password acak yang tidak diketahui siapa pun sampai pemilik akun membuat password lewat undangan.
            $user->forceFill(['name' => trim($name), 'email' => $email, 'password' => Str::random(64)])->save();

            TenantMember::create(['tenant_id' => $tenant->id, 'user_id' => $user->id, 'role' => $role]);

            return $user;
        });

        $this->audit->record('account.created', $user, $tenant, [], $this->snapshot($user), $note);

        return $user;
    }

    /** Mengirim (ulang) email undangan. Gagal kirim dilempar sebagai exception agar admin bisa mencoba lagi. */
    public function sendInvitation(User $user): void
    {
        $this->assertManageable($user);

        if ($user->isDisabled()) {
            throw new WorkspaceAccountException('Akun nonaktif tidak bisa diundang. Aktifkan kembali terlebih dahulu.');
        }

        $membership = $this->membership($user)
            ?? throw new WorkspaceAccountException('Akun ini belum terhubung ke workspace mana pun.');

        try {
            $status = Password::broker('invites')->sendResetLink(
                ['email' => $user->email],
                fn (User $recipient, string $token) => $recipient->notify(new AccountInvitation($token, $membership->tenant, $membership->role)),
            );
        } catch (Throwable $e) {
            // Email gagal (mis. SMTP): buang token agar "Kirim ulang" tidak tertahan batas 1 menit.
            DB::table(config('auth.passwords.invites.table'))->where('email', $user->email)->delete();

            throw $e;
        }

        if ($status === Password::RESET_THROTTLED) {
            throw new WorkspaceAccountException('Undangan baru saja dikirim. Tunggu 1 menit sebelum mengirim ulang.');
        }

        if ($status !== Password::RESET_LINK_SENT) {
            throw new WorkspaceAccountException('Undangan gagal dikirim.');
        }

        $this->audit->record('account.invitation_sent', $user, $membership->tenant, [], ['email' => $user->email]);
    }

    public function update(User $user, string $name, string $email, string $role, ?string $note = null): User
    {
        $this->assertManageable($user);
        $this->assertRole($role);
        $email = $this->normalizeEmail($email);
        $membership = $this->membership($user);

        if ($email !== $user->email && User::where('email', $email)->whereKeyNot($user->getKey())->exists()) {
            throw new WorkspaceAccountException('Email sudah dipakai akun lain.');
        }

        if ($membership && $membership->role === TenantMember::ROLE_OWNER && $role !== TenantMember::ROLE_OWNER
            && ! $this->hasOtherActiveOwner($membership)) {
            throw new WorkspaceAccountException('Akun ini satu-satunya owner aktif workspace ' . $membership->tenant->name
                . '. Jadikan akun lain owner terlebih dahulu.');
        }

        $before = $this->snapshot($user);
        $oldEmail = $user->email;

        DB::transaction(function () use ($user, $name, $email, $role, $membership, $oldEmail) {
            $user->forceFill(['name' => trim($name), 'email' => $email]);

            if ($email !== $oldEmail) {
                // Email baru harus dibuktikan lagi oleh pemiliknya (rute /app mewajibkan email terverifikasi).
                $user->email_verified_at = null;
                $this->forgetTokens($oldEmail);
            }

            $user->save();
            $membership?->update(['role' => $role]);
        });

        $this->audit->record('account.updated', $user, $membership?->tenant, $before, $this->snapshot($user->refresh()), $note);

        return $user;
    }

    public function disable(User $user, ?string $note = null): void
    {
        $this->assertManageable($user);

        if ($user->isDisabled()) {
            return;
        }

        $before = $this->snapshot($user);

        // remember_token baru membatalkan cookie "ingat saya"; sesi di database dihapus agar langsung keluar.
        $user->forceFill(['disabled_at' => now(), 'remember_token' => Str::random(60)])->save();
        $this->endSessions($user);
        $this->forgetTokens($user->email);

        $this->audit->record('account.disabled', $user, $this->membership($user)?->tenant, $before, $this->snapshot($user), $note);
    }

    public function enable(User $user, ?string $note = null): void
    {
        $this->assertManageable($user);

        if (! $user->isDisabled()) {
            return;
        }

        $before = $this->snapshot($user);
        $user->forceFill(['disabled_at' => null])->save();

        $this->audit->record('account.enabled', $user, $this->membership($user)?->tenant, $before, $this->snapshot($user), $note);
    }

    /** Hapus permanen. Keanggotaan ikut terhapus (cascade); riwayat audit tetap ada dengan aktor dikosongkan. */
    public function delete(User $user, ?string $note = null): void
    {
        $this->assertManageable($user);
        $membership = $this->membership($user);

        if ($membership && $membership->role === TenantMember::ROLE_OWNER && ! $this->hasOtherActiveOwner($membership)) {
            throw new WorkspaceAccountException('Akun ini satu-satunya owner aktif workspace ' . $membership->tenant->name
                . '. Jadikan akun lain owner terlebih dahulu, atau nonaktifkan akun ini saja.');
        }

        $before = $this->snapshot($user);

        DB::transaction(function () use ($user) {
            $this->endSessions($user);
            $this->forgetTokens($user->email);
            $user->delete();
        });

        $this->audit->record('account.deleted', $user, $membership?->tenant, $before, [], $note);
    }

    public function membership(User $user): ?TenantMember
    {
        return TenantMember::with('tenant')->where('user_id', $user->getKey())->orderBy('id')->first();
    }

    private function hasOtherActiveOwner(TenantMember $membership): bool
    {
        return TenantMember::where('tenant_id', $membership->tenant_id)
            ->where('role', TenantMember::ROLE_OWNER)
            ->where('user_id', '!=', $membership->user_id)
            ->whereHas('user', fn ($q) => $q->whereNull('disabled_at'))
            ->exists();
    }

    private function assertManageable(User $user): void
    {
        if ($user->is_platform_admin) {
            throw new WorkspaceAccountException('Akun admin platform tidak dikelola di sini.');
        }
    }

    private function assertRole(string $role): void
    {
        if (! in_array($role, self::ROLES, true)) {
            throw new WorkspaceAccountException("Peran tidak dikenal: {$role}.");
        }
    }

    private function normalizeEmail(string $email): string
    {
        return Str::lower(trim($email));
    }

    private function endSessions(User $user): void
    {
        if (config('session.driver') === 'database') {
            DB::table(config('session.table', 'sessions'))->where('user_id', $user->getKey())->delete();
        }
    }

    private function forgetTokens(string $email): void
    {
        DB::table(config('auth.passwords.invites.table'))->where('email', $email)->delete();
        DB::table(config('auth.passwords.users.table'))->where('email', $email)->delete();
    }

    /** @return array<string, mixed> */
    private function snapshot(User $user): array
    {
        $membership = $this->membership($user);

        return [
            'name' => $user->name,
            'email' => $user->email,
            'workspace' => $membership?->tenant?->name,
            'role' => $membership?->role,
            'status' => $user->isDisabled() ? 'nonaktif' : 'aktif',
            'email_verified' => $user->email_verified_at !== null,
        ];
    }
}
