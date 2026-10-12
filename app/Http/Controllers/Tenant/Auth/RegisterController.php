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

        event(new Registered($user));

        Auth::login($user);
        $request->session()->regenerate();

        return redirect()->route('verification.notice');
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
