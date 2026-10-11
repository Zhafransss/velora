@props(['services' => collect()])

<nav x-data="{ open: false, scrolled: false, layananOpen: false }"
    x-init="const onScroll = () => scrolled = window.scrollY > 10;
    onScroll();
    window.addEventListener('scroll', onScroll, { passive: true });"
    class="sticky top-0 z-50">

    <!-- Shell -->
    <div class="transition-all duration-300"
        :class="scrolled
            ? 'bg-white  border-b border-slate-200/70 shadow-[0_10px_30px_rgba(15,23,42,0.06)]'
            : 'bg-white border-b border-slate-200'">

        <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
            <div class="flex h-20 items-center justify-between">

                <!-- LEFT: Brand -->
                <a href="{{ url('/') }}" class="flex items-center gap-2">
                    <img src="{{ asset('images/veloradigital.webp') }}" alt="Velora Digital Logo" class="h-20 w-auto">
                </a>

                <!-- CENTER: Menu (Desktop) -->
                <div class="hidden items-center gap-5 text-sm font-light text-slate-700 md:flex">

                    <a href="{{ route('home') }}"
                        class="relative transition hover:text-slate-900
                               after:absolute after:left-0 after:-bottom-2 after:h-[2px] after:w-0 after:rounded-full
                               after:bg-[#696FC7] after:transition-all after:duration-300 hover:after:w-full">
                        Beranda
                    </a>

                    <!-- Dropdown Layanan -->
                    <div class="relative"
                        @mouseenter="layananOpen = true"
                        @mouseleave="layananOpen = false">

                        <button type="button"
                            class="relative inline-flex items-center gap-2 transition hover:text-slate-900
                                   after:absolute after:left-0 after:-bottom-2 after:h-[2px] after:w-0 after:rounded-full
                                   after:bg-[#696FC7] after:transition-all after:duration-300 hover:after:w-full">
                            <span>Layanan</span>
                            <i class="fa-solid fa-chevron-down text-[10px] transition duration-200"
                                :class="layananOpen ? 'rotate-180' : ''"></i>
                        </button>

                        <div x-show="layananOpen"
                            x-transition:enter="transition ease-out duration-200"
                            x-transition:enter-start="opacity-0 translate-y-2"
                            x-transition:enter-end="opacity-100 translate-y-0"
                            x-transition:leave="transition ease-in duration-150"
                            x-transition:leave-start="opacity-100 translate-y-0"
                            x-transition:leave-end="opacity-0 translate-y-2"
                            x-cloak
                            class="absolute left-0 top-full z-50 mt-4 w-72 rounded-2xl border border-slate-200 bg-white p-2 shadow-[0_20px_50px_rgba(15,23,42,0.10)]">

                            <a href="{{ route('services.index') }}"
                                class="block rounded-xl px-4 py-3 text-sm font-medium text-slate-900 hover:bg-slate-50 transition">
                                Semua Layanan
                            </a>

                            <div class="my-2 h-px bg-slate-100"></div>

                            @forelse ($services as $service)
                                <a href="{{ route('services.show', $service->slug) }}"
                                    class="block rounded-xl px-4 py-3 text-sm text-slate-700 hover:bg-slate-50 hover:text-slate-900 transition">
                                    {{ $service->name }}
                                </a>
                            @empty
                                <div class="px-4 py-3 text-sm text-slate-500">
                                    Belum ada layanan.
                                </div>
                            @endforelse
                        </div>
                    </div>

                    <a href="{{ route('portfolio.index') }}"
                        class="relative transition hover:text-slate-900
                               after:absolute after:left-0 after:-bottom-2 after:h-[2px] after:w-0 after:rounded-full
                               after:bg-[#696FC7] after:transition-all after:duration-300 hover:after:w-full">
                        Portfolio
                    </a>

                    <a href="{{ route('about.index') }}"
                        class="relative transition hover:text-slate-900
                               after:absolute after:left-0 after:-bottom-2 after:h-[2px] after:w-0 after:rounded-full
                               after:bg-[#696FC7] after:transition-all after:duration-300 hover:after:w-full">
                        Tentang Kami
                    </a>

                    <a href="{{ route('contact.index') }}"
                        class="relative transition hover:text-slate-900
                               after:absolute after:left-0 after:-bottom-2 after:h-[2px] after:w-0 after:rounded-full
                               after:bg-[#696FC7] after:transition-all after:duration-300 hover:after:w-full">
                        Hubungi Kami
                    </a>

                       <a href="{{ route('articles.index') }}"
                        class="relative transition hover:text-slate-900
                               after:absolute after:left-0 after:-bottom-2 after:h-[2px] after:w-0 after:rounded-full
                               after:bg-[#696FC7] after:transition-all after:duration-300 hover:after:w-full">
                       Artikel
                    </a>
                </div>

                <!-- RIGHT: Actions (Desktop) -->
                <div class="hidden items-center gap-4 md:flex">
                    <a href="#" class="text-slate-500 transition hover:text-slate-900" aria-label="Instagram">
                        <i class="fa-brands fa-instagram text-lg"></i>
                    </a>

                      <a href="#" class="text-slate-500 transition hover:text-slate-900" aria-label="Instagram">
                        <i class="fa-brands fa-facebook text-lg"></i>
                    </a>


                    <a href="#" class="text-slate-500 transition hover:text-slate-900" aria-label="TikTok">
                        <i class="fa-brands fa-tiktok text-lg"></i>
                    </a>

                    <span class="h-6 w-px bg-slate-200"></span>

                    <a href="mailto:team@veloradigitalworks.com"
                        class="hidden items-center gap-2 text-sm font-light text-slate-700 transition hover:text-slate-900 lg:inline-flex">
                        <i class="fa-regular fa-envelope"></i>
                        <span class="max-w-[220px] truncate">team@veloradigitalworks.com</span>
                    </a>

                    <a href="https://wa.me/6281234567890" target="_blank" rel="noopener"
                        class="inline-flex items-center gap-2 rounded-xl bg-[#25D366] px-4 py-2 text-sm font-semibold text-white
                               shadow-[0_10px_24px_rgba(37,211,102,0.16)] transition hover:bg-[#1fbe5b]">
                        <i class="fa-brands fa-whatsapp"></i>
                        WhatsApp
                    </a>
                </div>

                <!-- Mobile Button -->
                <button @click="open = !open"
                    class="inline-flex h-10 w-10 items-center justify-center rounded-xl border border-slate-200 text-slate-700 transition hover:bg-slate-50 md:hidden"
                    aria-label="Toggle Menu">
                    <i class="fa-solid fa-bars" x-show="!open" x-cloak></i>
                    <i class="fa-solid fa-xmark" x-show="open" x-cloak></i>
                </button>

            </div>
        </div>
    </div>

    <!-- Overlay -->
    <div x-show="open" x-transition.opacity x-cloak class="fixed inset-0 z-40 bg-black/20 md:hidden"
        @click="open = false"></div>

    <!-- Mobile Menu -->
    <div x-show="open"
        x-transition:enter="transition ease-out duration-200"
        x-transition:enter-start="opacity-0 -translate-y-2"
        x-transition:enter-end="opacity-100 translate-y-0"
        x-transition:leave="transition ease-in duration-150"
        x-transition:leave-start="opacity-100 translate-y-0"
        x-transition:leave-end="opacity-0 -translate-y-2"
        x-cloak
        class="relative z-50 border-b border-slate-200 bg-white md:hidden">

        <div class="mx-auto max-w-7xl space-y-3 px-4 py-4 sm:px-6 lg:px-8">
            <div class="grid gap-2 text-sm font-light text-slate-700">
                <a href="{{ route('home') }}" class="rounded-xl px-4 py-3 transition hover:bg-slate-50">
                    Beranda
                </a>

                <!-- Mobile Dropdown Layanan -->
                <div x-data="{ mobileLayananOpen: false }" class="rounded-xl ">
                    <button type="button"
                        @click="mobileLayananOpen = !mobileLayananOpen"
                        class="flex w-full items-center justify-between rounded-xl px-4 py-3 text-left transition hover:bg-slate-50">
                        <span>Layanan</span>
                        <i class="fa-solid fa-chevron-down text-[10px] transition duration-200"
                            :class="mobileLayananOpen ? 'rotate-180' : ''"></i>
                    </button>

                    <div x-show="mobileLayananOpen" x-transition x-cloak class="px-2 pb-2">
                        <a href="{{ route('services.index') }}"
                            class="block rounded-lg px-3 py-2 text-slate-700 transition hover:bg-slate-50">
                            Semua Layanan
                        </a>

                        @forelse ($services as $service)
                            <a href="{{ route('services.show', $service->slug) }}"
                                class="block rounded-lg px-3 py-2 text-slate-700 transition hover:bg-slate-50">
                                {{ $service->name }}
                            </a>
                        @empty
                            <div class="px-3 py-2 text-slate-500">
                                Belum ada layanan.
                            </div>
                        @endforelse
                    </div>
                </div>

                <a href="{{ route('portfolio.index') }}" class="rounded-xl px-4 py-3 transition hover:bg-slate-50">
                    Portfolio
                </a>

                <a href="{{ route('about.index') }}" class="rounded-xl px-4 py-3 transition hover:bg-slate-50">
                    Tentang Kami
                </a>

                <a href="{{ route('contact.index') }}" class="rounded-xl px-4 py-3 transition hover:bg-slate-50">
                    Hubungi Kami
                </a>

                <a href="{{ route('articles.index') }}" class="rounded-xl px-4 py-3 transition hover:bg-slate-50">
                    Artikel
                </a>
            </div>

            <div class="flex items-center justify-between gap-3 pt-2">
                <div class="flex items-center gap-4 text-slate-600">
                    <a href="https://www.instagram.com/buildwithvelora/" class="transition hover:text-slate-900" aria-label="Instagram">
                        <i class="fa-brands fa-instagram text-lg"></i>
                    </a>

                    <a href="#" class="transition hover:text-slate-900" aria-label="facebook">
                        <i class="fa-brands fa-facebook text-lg"></i>
                    </a>
                    <a href="#" class="transition hover:text-slate-900" aria-label="TikTok">
                        <i class="fa-brands fa-tiktok text-lg"></i>
                    </a>
                    <a href="mailto:team@veloradigitalworks.com" class="transition hover:text-slate-900" aria-label="Email">
                        <i class="fa-regular fa-envelope text-lg"></i>
                    </a>
                </div>

                <a href="https://wa.me/6281234567890" target="_blank" rel="noopener"
                    class="inline-flex items-center gap-2 rounded-xl bg-[#25D366] px-4 py-2 text-sm font-semibold text-white transition hover:bg-[#1fbe5b]">
                    <i class="fa-brands fa-whatsapp"></i>
                    Chat
                </a>
            </div>
        </div>
    </div>
</nav>