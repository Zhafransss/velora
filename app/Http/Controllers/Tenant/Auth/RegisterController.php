<?php

namespace App\Http\Controllers\Tenant\Auth;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Models\TenantMember;
use App\Models\User;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;

class RegisterController extends Controller
{
    public function create()
    {
        return view('tenant.auth.register');
    }

    /**
     * Pendaftaran terbuka. Akun baru otomatis punya workspace (owner) TANPA langganan,
     * sehingga fitur terkunci sampai langganan aktif (lihat EnsureSubscribed).
     */
    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'workspace_name' => ['required', 'string', 'max:150'],
            'email' => ['required', 'string', 'email:rfc', 'max:255', 'unique:users,email'],
            'password' => ['required', 'confirmed', Password::defaults()],
        ]);

        $user = DB::transaction(function () use ($data) {
            $user = User::create([
                'name' => $data['name'],
                'email' => Str::lower($data['email']),
                'password' => $data['password'],
            ]);

            $tenant = Tenant::create([
                'name' => $data['workspace_name'],
                'slug' => $this->uniqueSlug($data['workspace_name']),
            ]);

            TenantMember::create([
                'tenant_id' => $tenant->id,
                'user_id' => $user->id,
                'role' => TenantMember::ROLE_OWNER,
            ]);

            return $user;
        });

        // Akun sudah dibuat; kegagalan SMTP tidak boleh membuat pendaftaran error. Pengguna diberi tahu dan dapat kirim ulang.
        $mailFailed = false;

        try {
            event(new Registered($user));
        } catch (\Throwable $e) {
            $mailFailed = true;
            Log::error('mail.verification.failed', ['error' => $e::class, 'message' => mb_substr($e->getMessage(), 0, 200)]);
        }

        Auth::login($user);
        $request->session()->regenerate();

        return redirect()->route('verification.notice')->with('mail_error', $mailFailed);
    }

    private function uniqueSlug(string $name): string
    {
        $base = Str::slug($name) ?: 'workspace';
        $slug = $base;

        while (Tenant::where('slug', $slug)->exists()) {
            $slug = $base . '-' . Str::lower(Str::random(5));
        }

        return $slug;
    }
}
