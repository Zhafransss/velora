@extends('tenant.layouts.guest')
@section('title', 'Buat Password')
@section('content')
    <h1>Buat password</h1>
    <p class="muted">Anda diundang ke workspace Velora AI. Buat password untuk akun Anda, lalu login.</p>

    <form method="POST" action="{{ route('invitation.store') }}">
        @csrf
        <input type="hidden" name="token" value="{{ $token }}">

        <label for="email">Email</label>
        <input id="email" name="email" type="email" value="{{ old('email', $email) }}" required autocomplete="username">
        @error('email')<div class="err">{{ $message }}</div>@enderror

        <label for="password">Password</label>
        <input id="password" name="password" type="password" required autocomplete="new-password">
        @error('password')<div class="err">{{ $message }}</div>@enderror

        @include('tenant.auth.partials.password-confirmation')

        <button class="btn block" type="submit">Simpan password</button>
    </form>
@endsection
