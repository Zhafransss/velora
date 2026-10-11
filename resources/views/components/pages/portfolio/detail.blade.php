@props(['portfolio', 'screenshots' => []])

@php
    $waNumber = '6281234567890';
    $waText = "Halo Velora Digital, saya tertarik membuat website seperti portfolio: {$portfolio->title}";
    $waLink = "https://wa.me/{$waNumber}?text=" . urlencode($waText);
@endphp

<section class="relative overflow-hidden bg-white py-12 sm:py-14 lg:py-18">
    <!-- glow minimal -->
    <div class="pointer-events-none absolute inset-0">
        <div
            class="absolute -top-48 left-1/2 h-[420px] w-[420px] -translate-x-1/2 rounded-full blur-3xl
                   bg-gradient-to-tr from-[#20B7FF]/10 via-[#696FC7]/8 to-[#7C3AED]/8
                   sm:h-[520px] sm:w-[520px]">
        </div>
    </div>

    <div class="relative mx-auto max-w-6xl px-4 sm:px-6 lg:px-8">

        <!-- top bar -->
        <div class="flex flex-col gap-5 text-center sm:flex-row sm:items-end sm:justify-between sm:text-left">
            <div class="mx-auto sm:mx-0">
                <div class="text-sm text-slate-500">
                    <a href="{{ route('portfolio.index') }}" class="hover:text-slate-700 transition">
                        Portfolio
                    </a>
                    <span class="mx-2">/</span>
                    <span class="font-semibold text-slate-700">{{ $portfolio->title }}</span>
                </div>

                <h1 class="mt-2 text-2xl font-semibold tracking-tight text-slate-900 sm:text-3xl">
                    {{ $portfolio->title }}
                </h1>

                <p class="mt-1 text-sm text-slate-500">
                    {{ $portfolio->category?->name }}
                    @if ($portfolio->year)
                        • {{ $portfolio->year }}
                    @endif
                </p>
            </div>

            <div class="flex justify-center sm:justify-start">
                <a href="{{ route('portfolio.index') }}"
                    class="inline-flex items-center justify-center rounded-xl px-5 py-3 text-sm  font-semibold
                           border border-slate-200 text-slate-700 hover:bg-slate-50 transition
                           w-full sm:w-auto">
                    Kembali
                </a>
            </div>
        </div>

        <!-- gallery heading -->
        <div class="mt-10 flex justify-center sm:justify-between">
            <h2 class="text-base font-semibold text-slate-900 text-center sm:text-left">
                Tampilan Website
            </h2>
        </div>

        @if (count($screenshots))
            <div class="mt-4 grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                @foreach ($screenshots as $img)
                    <div class="group block overflow-hidden border border-slate-200 bg-white">

                        <div class="relative aspect-[3/4] overflow-hidden ">
                            <img src="{{ $img }}" alt="Screenshot {{ $portfolio->title }}"
                                class="h-full w-full object-cover object-top transition duration-500 group-hover:scale-[1.02]">

                            <div
                                class="pointer-events-none absolute inset-0 opacity-0 transition group-hover:opacity-100
                                       bg-gradient-to-t from-black/25 via-transparent to-transparent">
                            </div>
                        </div>

                    </div>
                @endforeach
            </div>
        @else
            <div class="mt-6 rounded-xl border border-slate-200 bg-slate-50 px-5 py-4 text-sm text-slate-600 text-center sm:text-left">
                Belum ada screenshot untuk portfolio ini.
            </div>
        @endif

    </div>
</section>