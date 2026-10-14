<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <title>@yield('title', 'Masuk') | Velora AI</title>
    <link rel="stylesheet" href="{{ asset('css/tenant.css') }}?v={{ @filemtime(public_path('css/tenant.css')) ?: 1 }}">
</head>
<body>
    <main class="auth">
        <div class="brand"><img src="{{ asset('images/veloradigital.webp') }}" alt="Velora Digital"></div>
        <div class="card">
            @if (session('status'))
                <div class="flash">{{ session('status') }}</div>
            @endif
            @yield('content')
        </div>
    </main>
</body>
</html>
