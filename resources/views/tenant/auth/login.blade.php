@extends('tenant.layouts.guest')
@section('title', 'Masuk')
@section('content')
    <h1>Masuk</h1>
    <p class="muted">Masuk ke dashboard Velora AI.</p>

    <form method="POST" action="{{ route('login') }}">
        @csrf
        <label for="email">Email</label>
        <input id="email" name="email" type="email" value="{{ old('email') }}" required autofocus autocomplete="username">
        @error('email')<div class="err">{{ $message }}</div>@enderror

        <label for="password">Password</label>
        <input id="password" name="password" type="password" required autocomplete="current-password">
        @error('password')<div class="err">{{ $message }}</div>@enderror

        <label style="font-weight:400"><input type="checkbox" name="remember" value="1" style="width:auto"> Ingat saya</label>

        <button class="btn block" type="submit">Masuk</button>
    </form>

    <p class="muted" style="margin-top:16px">
        <a href="{{ route('password.request') }}">Lupa password?</a> ·
        Belum punya akun? <a href="{{ route('register') }}">Daftar</a>
    </p>
@endsection
