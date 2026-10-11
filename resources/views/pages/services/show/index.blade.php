@extends('layouts.app')

@section('title', $category->name)

@section('content')

    @php
        $faqs = [
            ['q' => 'Berapa lama pengerjaan?', 'a' => 'Umumnya 3–7 hari kerja tergantung paket dan kesiapan materi.'],
            [
                'q' => 'Apakah bisa pakai domain sendiri?',
                'a' => 'Bisa. Jika sudah punya domain/hosting, tinggal diarahkan.',
            ],
            [
                'q' => 'Revisi seperti apa?',
                'a' => 'Revisi mencakup layout minor dan penyesuaian konten sesuai scope paket.',
            ],
        ];
    @endphp

    @if ($service)
        <x-pages.services.show.hero :service="$service" />
        <x-pages.services.show.packages :service="$service" :packages="$service->packages" />
        <x-pages.services.show.highlight :service="$service" />
        <x-pages.services.show.portfolio :portfolios="$portfolios" />
        <x-pages.services.show.faq :faqs="$faqs" />
        <x-pages.services.show.cta />
    @else
        <section class="py-24">
            <div class="container mx-auto px-4 text-center">
                <h1 class="text-3xl text-[#0685c0] font-bold mb-4">{{ $category->name }}</h1>
                <p class="text-lg  text- mb-6">
                    Layanan untuk kategori ini belum dibuat.
                </p>
                <p class="text-sm text-gray-500">
                    Silakan kembali lagi nanti atau hubungi admin untuk informasi lebih lanjut.
                </p>
            </div>
        </section>
    @endif

@endsection