<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Akun yang dinonaktifkan admin platform (DEC-20) langsung keluar, termasuk sesi yang sudah login sebelumnya atau
 * masuk lewat cookie "ingat saya". Letakkan SETELAH auth; juga terdaftar sebagai middleware persisten Livewire agar
 * aksi komponen ikut tertolak.
 */
class EnsureAccountActive
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user === null || ! $user->isDisabled()) {
            return $next($request);
        }

        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        abort_if($request->expectsJson() || $request->hasHeader('X-Livewire'), 403, 'Akun dinonaktifkan.');

        return redirect()->route('login')->withErrors(['email' => 'Akun ini dinonaktifkan. Hubungi admin Velora.']);
    }
}
