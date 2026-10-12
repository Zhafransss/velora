<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <title>{{ $title ?? trim($__env->yieldContent('title', 'Dashboard')) }} | Velora AI</title>
    <link rel="stylesheet" href="{{ asset('css/tenant.css') }}">
    @livewireStyles
</head>
<body>
    <nav class="top">
        <div class="in">
            <img src="{{ asset('images/veloradigital.webp') }}" alt="Velora Digital">
            <a class="l" href="{{ route('app.dashboard') }}">Dashboard</a>
            <a class="l" href="{{ route('app.business-profile') }}">Profil Bisnis</a>
            <a class="l" href="{{ route('app.products') }}">Produk</a>
            <a class="l" href="{{ route('app.services') }}">Layanan</a>
            <a class="l" href="{{ route('app.faqs') }}">FAQ</a>
            <a class="l" href="{{ route('app.subscription') }}">Langganan</a>
            <span class="sp"></span>
            <span class="muted">{{ auth()->user()->name }}</span>
            <form method="POST" action="{{ route('logout') }}">
                @csrf
                <button class="btn ghost" type="submit">Keluar</button>
            </form>
        </div>
    </nav>

    <main class="wrap">
        @if (session('status'))
            <div class="flash">{{ session('status') }}</div>
        @endif

        {{ $slot ?? '' }}
        @yield('content')
    </main>
    @livewireScripts
</body>
</html>
