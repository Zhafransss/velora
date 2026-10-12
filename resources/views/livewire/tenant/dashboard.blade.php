<div>
    <h1>Halo, {{ auth()->user()->name }}</h1>
    <p class="muted">Workspace: <strong>{{ $tenant->name }}</strong></p>

    @foreach ($alerts as $alert)
        <div class="lock" wire:key="alert{{ $alert->id }}" style="{{ $alert->severity === 'critical' ? 'background:#fef2f2; border-color:#fecaca; color:var(--bad)' : '' }}">
            <strong>{{ $alert->title }}</strong>@if ($alert->occurrences > 1) ({{ $alert->occurrences }} kali)@endif
            @if ($alert->body)— {{ $alert->body }}@endif
            @if ($alert->action_route && \Illuminate\Support\Facades\Route::has($alert->action_route))
                <a href="{{ route($alert->action_route) }}">Buka</a>
            @endif
        </div>
    @endforeach
    @if ($alerts->count() > 0)
        <p class="muted"><a href="{{ route('app.notifications') }}">Lihat semua notifikasi</a></p>
    @endif

    @unless ($isSubscribed)
        <div class="lock">
            <strong>Workspace ini belum berlangganan.</strong>
            Fitur belum dapat digunakan. <a href="{{ route('app.subscription') }}">Lihat cara berlangganan</a>.
        </div>
    @endunless

    <div class="row">
        <div class="card">
            <h2>Langganan</h2>
            @if ($isSubscribed)
                <span class="badge ok">Aktif · {{ strtoupper($subscription->plan_code) }}</span>
            @else
                <span class="badge">Belum berlangganan</span>
            @endif
        </div>
        <div class="card">
            <h2>Profil bisnis</h2>
            @if ($isSubscribed)
                <a class="btn" href="{{ route('app.business-profile') }}">Lengkapi profil</a>
            @else
                <p class="muted">Terkunci sampai langganan aktif.</p>
            @endif
        </div>
    </div>
</div>
