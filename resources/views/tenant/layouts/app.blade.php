<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <title>{{ $title ?? trim($__env->yieldContent('title', 'Dashboard')) }} | Velora AI</title>
    <link rel="stylesheet" href="{{ asset('css/tenant.css') }}?v={{ @filemtime(public_path('css/tenant.css')) ?: 1 }}">
    @livewireStyles
</head>
<body>
    @php
        $failedCount = \App\Livewire\Tenant\FailedMessages::recentFailedQuery()->count();
        $unreadNotifications = \App\Models\TenantNotification::whereNull('read_at')->count();
        $badge = fn (int $n) => $n > 99 ? '99+' : (string) $n;
        $user = auth()->user();
        $workspace = app(\App\Support\Tenancy\CurrentTenant::class)->get();

        // Menu berkelompok agar muat satu baris di desktop. Dropdown memakai Alpine yang sudah dimuat Livewire.
        $groups = [
            ['label' => 'Knowledge Base', 'icon' => 'book', 'items' => [
                ['route' => 'app.business-profile', 'label' => 'Profil Bisnis', 'hint' => 'Jenis usaha, jam buka, kontak'],
                ['route' => 'app.products', 'label' => 'Produk', 'hint' => 'Barang yang Anda jual'],
                ['route' => 'app.services', 'label' => 'Layanan', 'hint' => 'Jasa yang Anda tawarkan'],
                ['route' => 'app.faqs', 'label' => 'FAQ', 'hint' => 'Pertanyaan yang sering ditanyakan'],
            ]],
            ['label' => 'Chatbot', 'icon' => 'sparkles', 'items' => [
                ['route' => 'app.bot', 'label' => 'Pengaturan Bot', 'hint' => 'Persona, gaya bahasa, pesan pembuka'],
                ['route' => 'app.chat-test', 'label' => 'Tes Chatbot', 'hint' => 'Coba jawaban bot sebelum dipakai'],
            ]],
            ['label' => 'WhatsApp', 'icon' => 'chat', 'count' => $failedCount, 'items' => [
                ['route' => 'app.whatsapp', 'label' => 'Koneksi', 'hint' => 'Scan QR dan status nomor'],
                ['route' => 'app.conversations', 'label' => 'Percakapan', 'hint' => 'Riwayat chat dengan pelanggan'],
                ['route' => 'app.failed-messages', 'label' => 'Pesan Gagal', 'hint' => 'Kirim ulang pesan yang gagal', 'count' => $failedCount],
            ]],
        ];
    @endphp

    <nav class="top" x-data="{ menu: false }" @keydown.escape.window="menu = false">
        <div class="in">
            <a class="logo" href="{{ route('app.dashboard') }}">
                <img src="{{ asset('images/veloradigital.webp') }}" alt="Velora Digital">
                <span>Velora <b>AI</b></span>
            </a>

            <button class="burger" type="button" @click="menu = !menu" :aria-expanded="menu" aria-label="Buka menu">
                <span x-show="!menu">@include('tenant.partials.icon', ['name' => 'menu'])</span>
                <span x-show="menu" x-cloak>@include('tenant.partials.icon', ['name' => 'close'])</span>
            </button>

            <div class="menu" :class="{ 'open': menu }">
                <a class="nav-l {{ request()->routeIs('app.dashboard') ? 'on' : '' }}" href="{{ route('app.dashboard') }}">
                    @include('tenant.partials.icon', ['name' => 'home']) Dashboard
                </a>

                @foreach ($groups as $group)
                    @php $active = collect($group['items'])->contains(fn ($i) => request()->routeIs($i['route'])); @endphp
                    <div class="dd" x-data="{ open: false }" @click.outside="open = false" @keydown.escape.window="open = false">
                        <button type="button" class="nav-l {{ $active ? 'on' : '' }}" @click="open = !open" :aria-expanded="open">
                            @include('tenant.partials.icon', ['name' => $group['icon']]) {{ $group['label'] }}
                            @if (($group['count'] ?? 0) > 0)<span class="count">{{ $badge($group['count']) }}</span>@endif
                            @include('tenant.partials.icon', ['name' => 'chevron-down', 'class' => 'caret'])
                        </button>
                        <div class="dd-panel" x-show="open" x-transition.origin.top.left x-cloak>
                            @foreach ($group['items'] as $item)
                                <a href="{{ route($item['route']) }}" class="{{ request()->routeIs($item['route']) ? 'on' : '' }}">
                                    <span class="t">{{ $item['label'] }}
                                        @if (($item['count'] ?? 0) > 0)<span class="count">{{ $badge($item['count']) }}</span>@endif
                                    </span>
                                    <span class="h">{{ $item['hint'] }}</span>
                                </a>
                            @endforeach
                        </div>
                    </div>
                @endforeach

                <span class="sp"></span>

                <a class="nav-l icon-only {{ request()->routeIs('app.notifications') ? 'on' : '' }}" href="{{ route('app.notifications') }}" title="Notifikasi">
                    @include('tenant.partials.icon', ['name' => 'bell'])
                    <span class="sr">Notifikasi</span>
                    @if ($unreadNotifications > 0)<span class="count">{{ $badge($unreadNotifications) }}</span>@endif
                </a>

                <div class="dd right" x-data="{ open: false }" @click.outside="open = false" @keydown.escape.window="open = false">
                    <button type="button" class="nav-l account" @click="open = !open" :aria-expanded="open">
                        <span class="avatar">{{ \Illuminate\Support\Str::upper(\Illuminate\Support\Str::substr($user->name, 0, 1)) }}</span>
                        <span class="who">{{ $user->name }}</span>
                        @include('tenant.partials.icon', ['name' => 'chevron-down', 'class' => 'caret'])
                    </button>
                    <div class="dd-panel" x-show="open" x-transition.origin.top.right x-cloak>
                        <div class="me">
                            <strong>{{ $user->name }}</strong>
                            <span>{{ $user->email }}</span>
                            @if ($workspace)<span class="ws">Workspace: {{ $workspace->name }}</span>@endif
                        </div>
                        <a href="{{ route('app.subscription') }}" class="{{ request()->routeIs('app.subscription') ? 'on' : '' }}">
                            <span class="t">@include('tenant.partials.icon', ['name' => 'card']) Langganan</span>
                        </a>
                        <form method="POST" action="{{ route('logout') }}">
                            @csrf
                            <button type="submit"><span class="t">@include('tenant.partials.icon', ['name' => 'logout']) Keluar</span></button>
                        </form>
                    </div>
                </div>
            </div>
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
