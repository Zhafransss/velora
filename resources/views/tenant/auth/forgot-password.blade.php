@extends('tenant.layouts.guest')
@section('title', 'Lupa Password')
@section('content')
    <h1>Lupa password</h1>
    <p class="muted">Masukkan email akun Anda, kami kirim tautan untuk mengatur ulang password.</p>

    <form method="POST" action="{{ route('password.email') }}">
        @csrf
        <label for="email">Email</label>
        <input id="email" name="email" type="email" value="{{ old('email') }}" required autofocus>
        @error('email')<div class="err">{{ $message }}</div>@enderror

        <button class="btn block" type="submit">Kirim tautan reset</button>
    </form>

    <p class="muted" style="margin-top:16px"><a href="{{ route('login') }}">Kembali ke login</a></p>
@endsection
