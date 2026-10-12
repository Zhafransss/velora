@extends('tenant.layouts.app')
@section('title', 'Langganan')
@section('content')
    @php
        $tenant = app(\App\Support\Tenancy\CurrentTenant::class)->get();
        $subscription = $tenant->currentSubscription;
        $isSubscribed = $tenant->hasActiveSubscription();
    @endphp

    <h1>Langganan</h1>
    <p class="muted">Workspace: <strong>{{ $tenant->name }}</strong></p>

    <div class="card">
        @if ($isSubscribed)
            <h2>Status: <span class="badge ok">Aktif</span></h2>
            <p>Paket <strong>{{ strtoupper($subscription->plan_code) }}</strong>
                @if ($subscription->current_period_end)
                    aktif sampai <strong>{{ $subscription->current_period_end->translatedFormat('d F Y') }}</strong>.
                @endif
            </p>
        @else
            <h2>Status: <span class="badge">Belum berlangganan</span></h2>
            @if ($subscription)
                <p class="muted">Langganan terakhir Anda berstatus <strong>{{ $subscription->status }}</strong>.</p>
            @endif
            <div class="lock">
                Fitur Velora AI belum dapat dipakai. Aktifkan langganan untuk membuka semua fitur.
            </div>
            <h2>Cara berlangganan</h2>
            <ol>
                <li>Hubungi tim Velora Digital untuk memilih paket (Small, Medium, atau Enterprise).</li>
                <li>Lakukan pembayaran sesuai invoice yang kami kirimkan.</li>
                <li>Setelah pembayaran kami catat, langganan aktif dan fitur terbuka otomatis. Tidak perlu mendaftar ulang.</li>
            </ol>
        @endif
    </div>
@endsection
