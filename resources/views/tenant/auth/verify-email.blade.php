@extends('tenant.layouts.guest')
@section('title', 'Verifikasi Email')
@section('content')
    <h1>Verifikasi email Anda</h1>

    @if (session('mail_error'))
        <div class="lock">
            Email verifikasi belum dapat dikirim saat ini. Klik <strong>Kirim ulang email verifikasi</strong> beberapa saat lagi.
            Jika terus gagal, hubungi tim Velora Digital.
        </div>
        <p class="muted">Akun Anda sudah dibuat untuk <strong>{{ auth()->user()->email }}</strong>.</p>
    @else
        <p class="muted">Kami telah mengirim tautan verifikasi ke <strong>{{ auth()->user()->email }}</strong>. Klik tautan tersebut untuk melanjutkan.</p>
        <p class="muted">Belum menerima emailnya? Periksa folder <strong>Spam</strong> atau <strong>Promosi</strong>, atau kirim ulang di bawah.</p>
    @endif

    <form method="POST" action="{{ route('verification.send') }}">
        @csrf
        <button class="btn block" type="submit">Kirim ulang email verifikasi</button>
    </form>

    <form method="POST" action="{{ route('logout') }}" style="margin-top:12px">
        @csrf
        <button class="btn ghost block" type="submit">Keluar</button>
    </form>
@endsection
