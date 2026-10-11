@props(['portfolios'])
<section id="portfolio" class="relative overflow-hidden bg-white py-16 sm:py-20" data-aos="fade-up"
    data-aos-duration="1000">
    <!-- soft glow -->
    <div class="pointer-events-none absolute inset-0">
        <div
            class="absolute -top-40 left-1/2 h-[520px] w-[520px] -translate-x-1/2 rounded-full blur-3xl
                bg-gradient-to-tr from-[#20B7FF]/12 via-[#696FC7]/10 to-[#7C3AED]/10">
        </div>
    </div>

    <div class="relative mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
        <!-- Header -->
        <div class="flex flex-col gap-6 md:flex-row md:items-end md:justify-between">
            <div class="max-w-2xl" data-aos="fade-right" data-aos-delay="100">
                <div
                    class="inline-flex items-center gap-2 rounded-full border border-slate-200 bg-white/70 backdrop-blur px-4 py-2 text-sm font-semibold text-slate-700
                 shadow-[0_10px_30px_rgba(15,23,42,0.06)]"
                    data-aos="fade-up" data-aos-delay="150">
                    <span
                        class="inline-block h-2 w-2 rounded-full bg-gradient-to-r from-[#20B7FF] via-[#696FC7] to-[#7C3AED]"></span>
                    Portfolio
                </div>

                <h2 class="mt-5 text-3xl sm:text-4xl font-semibold tracking-tight text-slate-900 leading-tight"
                    data-aos="fade-up" data-aos-delay="250">
                    Beberapa website yang
                    <span
                        class="bg-gradient-to-r from-[#20B7FF] via-[#696FC7] to-[#7C3AED] bg-clip-text text-transparent">
                        sudah kami kerjakan
                    </span>
                </h2>

                <p class="mt-4 text-slate-600 leading-relaxed" data-aos="fade-up" data-aos-delay="350">
                    Desain rapi, responsif, dan performa cepat. Klik salah satu untuk lihat detail singkat.
                </p>
            </div>

            <!-- CTA (responsive) -->
            <div class="flex w-full md:w-auto" data-aos="fade-left" data-aos-delay="200">
                <a href="{{ url('/portfolio') }}"
                    class=" md:w-auto
                  inline-flex items-center justify-center whitespace-nowrap
                  rounded-xl px-5 py-3 text-sm font-semibold text-white
                  bg-gradient-to-r from-[#20B7FF] via-[#696FC7] to-[#7C3AED]
                  shadow-[0_18px_45px_rgba(105,111,199,0.22)]
                  hover:brightness-105 transition">
                    Lihat Portfolio Lengkap
                    <i class="fa-solid fa-arrow-right text-sm mx-2"></i>
                </a>
            </div>
        </div>

      <!-- GRID -->
<div class="mt-8 grid gap-5 sm:grid-cols-2 sm:gap-6 lg:grid-cols-3">
    @foreach ($portfolios as $index => $item)
        <a href="{{ route('portfolio.show', $item->slug) }}"
           x-data="{
                title: @js($item->title),
                category: @js(optional($item->category)->slug ?? '')
           }"
           class="group relative block overflow-hidden " style="border-radius:0 !important;"
           data-aos="fade-up"
           data-aos-delay="{{ 150 + ($index * 100) }}"
           data-aos-duration="900">

            <div class="relative aspect-[16/10] overflow-hidden bg-slate-100" style="border-radius:0 !important;">
                <img
                    src="{{ $item->cover_image ? asset('storage/' . ltrim($item->cover_image, '/')) : asset('images/placeholder.jpg') }}"
                    alt="{{ $item->title }}"
                    loading="lazy"
                    decoding="async"
                    width="800"
                    height="500"
                    class="h-full w-full object-cover transition duration-500 group-hover:scale-[1.03]"
                >

                <div class="absolute inset-0 bg-gradient-to-t from-black/70 via-black/15 to-transparent"></div>

                <div class="absolute bottom-0 left-0 right-0 p-4 sm:p-5 lg:p-6">
                    <div class="text-xs font-medium text-white/80">
                        {{ $item->category?->name }}
                    </div>

                    <h3 class="mt-1 text-base font-semibold leading-snug text-white sm:text-lg">
                        {{ $item->title }}
                    </h3>
                </div>
            </div>
        </a>
    @endforeach
</div>
    </div>
</section>