@extends('tenant.layouts.app')
@section('title', 'Langganan')
@section('content')
    @php
        $tenant = app(\App\Support\Tenancy\CurrentTenant::class)->get();
        $subscription = $tenant->currentSubscription;
        $isSubscribed = $tenant->hasActiveSubscription();
        $entitlements = \App\Services\Plans\Entitlements::forTenant($tenant);
        $plans = \App\Services\Plans\Entitlements::allPlans();
        $retention = (int) config('retention.conversation_days', 30);
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
                <li>Pilih paket di bawah, lalu hubungi tim Velora Digital.</li>
                <li>Lakukan pembayaran sesuai invoice yang kami kirimkan.</li>
                <li>Setelah pembayaran kami catat, langganan aktif dan fitur terbuka otomatis. Tidak perlu mendaftar ulang.</li>
            </ol>
        @endif
    </div>

    <h2>Pilihan paket</h2>
    <div class="row">
        @foreach ($plans as $code => $plan)
            <div class="card" style="{{ $isSubscribed && $entitlements->planCode === $code ? 'border-color:var(--brand); border-width:2px' : '' }}">
                <h2>{{ $plan->label }}
                    @if ($isSubscribed && $entitlements->planCode === $code)<span class="badge ok">Paket Anda</span>@endif
                </h2>
                <p style="font-size:1.4rem; margin:4px 0"><strong>{{ $plan->priceLabel() }}</strong>
                    @if ($plan->priceIdr !== null)<span class="muted" style="font-size:.9rem">/ {{ $plan->periodDays }} hari</span>@endif
                </p>
                <ul style="padding-left:18px; margin:8px 0 0">
                    <li>{{ $plan->dailyAiMessages === null ? 'Pesan AI tanpa batas keras (fair use)' : number_format($plan->dailyAiMessages, 0, ',', '.') . ' pesan AI per hari' }}</li>
                    <li>WhatsApp, produk, layanan, FAQ, dan tes chatbot</li>
                    <li>Riwayat percakapan: {{ $plan->historyDays === null ? "seluruh {$retention} hari" : $plan->historyDays . ' hari terakhir' }}</li>
                </ul>
            </div>
        @endforeach
    </div>
    <p class="muted">Percakapan dihapus otomatis dari sistem setelah {{ $retention }} hari. Widget website dan Developer API belum tersedia.</p>
@endsection
