@props(['categories', 'portfolios']){{-- resources/views/components/sections/portfolio/portfolio-index.blade.php --}}

<section class="relative overflow-hidden bg-white py-12 sm:py-14 lg:py-18">
    <!-- soft glow -->
    <div class="pointer-events-none absolute inset-0">
        <div
            class="absolute -top-48 left-1/2 h-[420px] w-[420px] -translate-x-1/2 rounded-full blur-3xl
                   bg-gradient-to-tr from-[#20B7FF]/10 via-[#696FC7]/8 to-[#7C3AED]/8
                   sm:h-[520px] sm:w-[520px]">
        </div>
    </div>

    <div class="relative mx-auto max-w-7xl px-4 sm:px-6 lg:px-8" x-data="{
        active: 'all',
        q: '',
        match(item) {
            const byCat = (this.active === 'all') || (item.category === this.active);
            const text = (item.title + ' ' + (item.tag || '')).toLowerCase();
            const byQ = this.q.trim() === '' || text.includes(this.q.toLowerCase());
            return byCat && byQ;
        }
    }">

        <!-- HERO -->
        <div class="relative flex flex-col gap-8 text-center lg:flex-row lg:items-end lg:justify-between lg:text-left">

            <!-- LEFT -->
            <div class=" max-w-2xl lg:mx-0">
                <p class="text-sm font-semibold tracking-wide text-slate-500">
                    Portfolio
                </p>

                <h1 class="mt-2 text-3xl font-semibold tracking-tight leading-tight sm:text-4xl lg:text-4xl">
                    <span
                        class="bg-gradient-to-r from-[#20B7FF] via-[#696FC7] to-[#7C3AED] bg-clip-text text-transparent">
                        Project pilihan
                    </span>
                    <span class="text-slate-900">
                        Velora Digital
                    </span>
                </h1>

                <!-- accent line -->
                <div
                    class="mx-auto mt-3 h-[3px] w-20 rounded-full bg-gradient-to-r from-[#20B7FF] via-[#696FC7] to-[#7C3AED] lg:mx-0">
                </div>

                <p class="mt-4 text-sm leading-7 text-slate-600 sm:text-base">
                    Jelajahi berbagai project website yang telah kami kerjakan untuk membantu
                    bisnis berkembang secara digital.
                </p>

                <!-- CTA -->
                <div class="mt-6 flex flex-col gap-3 sm:flex-row sm:justify-center lg:justify-start">
                    <a href="" target="_blank"
                        class="inline-flex w-full items-center justify-center gap-2 rounded-xl px-6 py-3 text-sm font-semibold text-white
                               bg-gradient-to-r from-[#20B7FF] via-[#696FC7] to-[#7C3AED]
                               shadow-[0_18px_45px_rgba(105,111,199,0.25)]
                               hover:brightness-105 transition sm:w-auto">
                        <i class="fa-brands fa-whatsapp text-base"></i>
                        Konsultasi WhatsApp
                    </a>

                    <a href="{{ url('/contact') }}"
                        class="inline-flex w-full items-center justify-center rounded-2xl border border-slate-200
                               px-6 py-3 text-sm font-semibold text-slate-700
                               hover:bg-slate-50 transition sm:w-auto">
                        Hubungi Kami
                        <i class="fa-solid mx-2 fa-arrow-right"></i>
                    </a>
                </div>
            </div>

            <!-- RIGHT SEARCH -->
            <div class="mx-auto w-full max-w-md lg:mx-0 lg:w-[380px]">
                <label class="sr-only" for="portfolio-search">Cari portfolio</label>

                <div class="relative">
                    <div
                        class="absolute -inset-1 rounded-xl bg-gradient-to-r from-[#20B7FF]/20 via-[#696FC7]/20 to-[#7C3AED]/20 blur">
                    </div>

                    <div class="relative">
                        <span class="pointer-events-none absolute inset-y-0 left-3 flex items-center text-slate-400">
                            <i class="fa-solid fa-magnifying-glass"></i>
                        </span>

                        <input id="portfolio-search" type="text" placeholder="Cari project…"
                            x-model.debounce.200ms="q"
                            class="w-full rounded-xl border border-slate-200 bg-white px-10 py-3 text-sm text-slate-700
                                   placeholder:text-slate-400
                                   focus:outline-none focus:ring-2 focus:ring-[#696FC7]/20">
                    </div>
                </div>
            </div>

        </div>

        <!-- FILTERS -->
        <div class="mt-8 flex flex-wrap items-center justify-center gap-2 sm:gap-3 lg:justify-start">
            <button type="button" @click="active = 'all'"
                :class="active === 'all'
                    ?
                    'bg-[#7C3AED] text-white shadow-sm' :
                    'bg-white text-slate-600 border border-slate-200 hover:bg-slate-50 hover:text-slate-900'"
                class="rounded-full px-4 py-2 text-sm font-medium transition duration-200 sm:px-5 sm:py-2.5">
                Semua
            </button>

            @foreach ($categories as $cat)
                <button type="button" @click="active = '{{ $cat['key'] }}'"
                    :class="active === '{{ $cat['key'] }}'
                        ?
                        'bg-[#7C3AED] text-white shadow-sm' :
                        'bg-white text-slate-600 border border-slate-200 hover:bg-slate-50 hover:text-slate-900'"
                    class="rounded-full px-4 py-2 text-sm font-medium transition duration-200 sm:px-5 sm:py-2.5">
                    {{ $cat['name'] }}
                </button>
            @endforeach
        </div>

        <!-- GRID / EMPTY STATE -->
        @if (count($portfolios) > 0)
            <div class="mt-8 grid gap-5 sm:grid-cols-2 sm:gap-6 lg:grid-cols-3">
                @foreach ($portfolios as $item)
                    <a href="{{ $item['href'] }}" x-data="{
                        title: @js($item['title']),
                        category: @js($item['category'])
                    }" x-show="match($data)"
                        x-transition.opacity.duration.150ms class="group relative block overflow-hidden !rounded-none " style="border-radius:0 !important;">

                        <div class="relative aspect-[16/10] overflow-hidden !rounded-none ">
                            <img src="{{ $item['cover_image'] }}" alt="{{ $item['title'] }}"
                                class="h-full w-full object-cover transition duration-500 group-hover:scale-[1.03]" style="border-radius:0 !important;">

                            <div class="absolute inset-0 bg-gradient-to-t from-black/70 via-black/15 to-transparent">
                            </div>

                            <div class="absolute bottom-0 left-0 right-0 p-4 sm:p-5 lg:p-6">
                                <div class="text-xs font-medium text-white/80">
                                    {{ $item['category_name'] }}
                                </div>
                                <h3 class="mt-1 text-base font-semibold leading-snug text-white sm:text-lg">
                                    {{ $item['title'] }}
                                </h3>
                            </div>
                        </div>
                    </a>
                @endforeach
            </div>

            <!-- Empty state saat hasil filter / search tidak ada -->
            <div class="mt-8 text-center text-sm text-slate-500"
                x-show="(() => {
            const cards = Array.from($el.previousElementSibling.children);
            return cards.length && cards.every(el => el.style.display === 'none');
        })()"
                x-cloak>
                Tidak ada portfolio yang sesuai dengan filter / pencarian.
            </div>
        @else
            <div class="mt-10">
                <div class="rounded-3xl border border-dashed border-slate-200 bg-slate-50 px-6 py-14 text-center">
                    <div class="mx-auto flex h-14 w-14 items-center justify-center rounded-2xl bg-white shadow-sm">
                        <i class="fa-regular fa-folder-open text-lg text-slate-400"></i>
                    </div>

                    <h3 class="mt-4 text-lg font-semibold text-slate-900">
                        Belum ada portfolio untuk kategori ini
                    </h3>

                    <p class="mx-auto mt-2 max-w-md text-sm leading-6 text-slate-500">
                        Portfolio untuk kategori ini masih dalam proses penambahan.
                        Silakan cek kembali nanti atau hubungi kami untuk melihat contoh project yang relevan.
                    </p>

                    <div class="mt-6 flex flex-col justify-center gap-3 sm:flex-row">
                        <a href="{{ url('/portfolio') }}"
                            class="inline-flex items-center justify-center rounded-xl border border-slate-200 bg-white px-5 py-3 text-sm font-semibold text-slate-700 hover:bg-slate-50 transition">
                            Lihat Semua Portfolio
                        </a>

                        <a href="{{ url('/contact') }}"
                            class="inline-flex items-center justify-center rounded-xl px-5 py-3 text-sm font-semibold text-white
                           bg-gradient-to-r from-[#20B7FF] via-[#696FC7] to-[#7C3AED]
                           shadow-[0_18px_45px_rgba(105,111,199,0.25)]
                           hover:brightness-105 transition">
                            Hubungi Kami
                        </a>
                    </div>
                </div>
            </div>
        @endif

    </div>
</section>
