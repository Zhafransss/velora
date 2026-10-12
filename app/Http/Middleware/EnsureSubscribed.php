<?php

namespace App\Http\Middleware;

use App\Support\Tenancy\CurrentTenant;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gerbang fitur berdasarkan status langganan tenant (bukan persetujuan admin per akun).
 * Letakkan SETELAH middleware `tenant`. Tanpa langganan layak, user diarahkan ke halaman langganan.
 */
class EnsureSubscribed
{
    public function __construct(private CurrentTenant $currentTenant)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        $tenant = $this->currentTenant->get();

        abort_if($tenant === null, 403, 'Tidak ada workspace aktif.');

        if (! $tenant->hasActiveSubscription()) {
            return redirect()
                ->route('app.subscription')
                ->with('status', 'Fitur ini hanya tersedia untuk workspace dengan langganan aktif.');
        }

        return $next($request);
    }
}
