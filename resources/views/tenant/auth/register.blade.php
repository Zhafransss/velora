@extends('tenant.layouts.guest')
@section('title', 'Daftar')
@section('content')
    <h1>Buat akun</h1>
    <p class="muted">Pendaftaran gratis. Fitur dapat dipakai setelah workspace Anda berlangganan.</p>

    <form method="POST" action="{{ route('register') }}">
        @csrf
        <label for="name">Nama Anda</label>
        <input id="name" name="name" value="{{ old('name') }}" required autofocus autocomplete="name">
        @error('name')<div class="err">{{ $message }}</div>@enderror

        <label for="workspace_name">Nama bisnis / workspace</label>
        <input id="workspace_name" name="workspace_name" value="{{ old('workspace_name') }}" required>
        @error('workspace_name')<div class="err">{{ $message }}</div>@enderror

        <label for="email">Email</label>
        <input id="email" name="email" type="email" value="{{ old('email') }}" required autocomplete="username">
        @error('email')<div class="err">{{ $message }}</div>@enderror

        <label for="password">Password (min. 8 karakter)</label>
        <input id="password" name="password" type="password" required autocomplete="new-password">
        @error('password')<div class="err">{{ $message }}</div>@enderror

        @include('tenant.auth.partials.password-confirmation')

        @if ($recaptchaSiteKey)
            <div class="g-recaptcha" data-sitekey="{{ $recaptchaSiteKey }}" style="margin-top:12px"></div>
        @endif
        @error('g-recaptcha-response')<div class="err">{{ $message }}</div>@enderror

        <button class="btn block" type="submit">Daftar</button>
    </form>
    @if ($recaptchaSiteKey)
        <script src="https://www.google.com/recaptcha/api.js" async defer></script>
    @endif

    <p class="muted" style="margin-top:16px">Sudah punya akun? <a href="{{ route('login') }}">Masuk</a></p>
@endsection
