@extends('tenant.layouts.guest')
@section('title', 'Reset Password')
@section('content')
    <h1>Atur password baru</h1>

    <form method="POST" action="{{ route('password.update') }}">
        @csrf
        <input type="hidden" name="token" value="{{ $token }}">

        <label for="email">Email</label>
        <input id="email" name="email" type="email" value="{{ old('email', $email) }}" required>
        @error('email')<div class="err">{{ $message }}</div>@enderror

        <label for="password">Password baru</label>
        <input id="password" name="password" type="password" required autocomplete="new-password">
        @error('password')<div class="err">{{ $message }}</div>@enderror

        @include('tenant.auth.partials.password-confirmation')

        <button class="btn block" type="submit">Simpan password</button>
    </form>
@endsection
