<?php

namespace App\Http\Middleware;

use App\Support\Tenancy\CurrentTenant;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Menetapkan tenant aktif dari keanggotaan user yang login. Letakkan SETELAH middleware auth.
 * User tanpa workspace aktif mendapat 403 (bukan konteks kosong).
 */
class SetCurrentTenant
{
    public function __construct(private CurrentTenant $currentTenant)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        abort_if($user === null, 401);

        $tenant = $this->currentTenant->resolveFor($user);

        abort_if($tenant === null, 403, 'Tidak ada workspace aktif.');

        $this->currentTenant->set($tenant);

        return $next($request);
    }
}
