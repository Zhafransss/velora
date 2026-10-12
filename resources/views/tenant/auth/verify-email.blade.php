@extends('tenant.layouts.guest')
@section('title', 'Verifikasi Email')
@section('content')
    <h1>Verifikasi email Anda</h1>
    <p class="muted">Kami telah mengirim tautan verifikasi ke <strong>{{ auth()->user()->email }}</strong>. Klik tautan tersebut untuk melanjutkan.</p>

    <form method="POST" action="{{ route('verification.send') }}">
        @csrf
        <button class="btn block" type="submit">Kirim ulang email verifikasi</button>
    </form>

    <form method="POST" action="{{ route('logout') }}" style="margin-top:12px">
        @csrf
        <button class="btn ghost block" type="submit">Keluar</button>
    </form>
@endsection
