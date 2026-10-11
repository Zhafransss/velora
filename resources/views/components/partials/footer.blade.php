<footer class="mt-16 bg-[#0f1026] text-white relative overflow-hidden">
    <!-- subtle top accent line -->
    <div class="absolute inset-x-0 top-0 h-px bg-gradient-to-r from-transparent via-white/15 to-transparent"></div>

    <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8 py-12">
        <!-- Top -->
        <div class="grid gap-10 md:grid-cols-12 md:items-start">

            <!-- Brand -->
            <div class="md:col-span-5">
                <div class="flex items-center gap-3">
                    <div class="h-16 w-16 rounded-2xl overflow-hidden bg-white/5 ring-1 ring-white/10">
                        <img src="{{ asset('images/veloradigital.webp') }}" alt="Velora Digital Logo"
                            class="h-full w-full object-cover">
                    </div>
                    <div class="leading-none">
                        <div class="text-sm font-extrabold tracking-wide">Velora Digital</div>
                        <div class="text-[11px] text-white/60 font-medium">Design • Dev • Growth</div>
                    </div>
                </div>

                <p class="mt-4 text-sm text-white/60 leading-relaxed max-w-md">
                    Jasa pembuatan website profesional untuk bisnis Anda.
                </p>

                <!-- Social -->
                <div class="mt-5 flex items-center gap-3">
                    <a href="#"
                        class="inline-flex h-10 w-10 items-center justify-center rounded-xl
                               bg-white/5 ring-1 ring-white/10 text-white/70
                               hover:text-white hover:bg-white/10 transition"
                        aria-label="Instagram">
                        <i class="fa-brands fa-instagram"></i>
                    </a>

                    <a href="#"
                        class="inline-flex h-10 w-10 items-center justify-center rounded-xl
                               bg-white/5 ring-1 ring-white/10 text-white/70
                               hover:text-white hover:bg-white/10 transition"
                        aria-label="TikTok">
                        <i class="fa-brands fa-facebook"></i>
                    </a>

                    <a href="#"
                        class="inline-flex h-10 w-10 items-center justify-center rounded-xl
                               bg-white/5 ring-1 ring-white/10 text-white/70
                               hover:text-white hover:bg-white/10 transition"
                        aria-label="TikTok">
                        <i class="fa-brands fa-tiktok"></i>
                    </a>

                    <span class="mx-1 h-6 w-px bg-white/10"></span>

                    <a href="https://wa.me/628970038125" target="_blank" rel="noopener"
                        class="inline-flex items-center gap-2 rounded-xl px-4 py-2 text-sm font-semibold text-white
                               bg-white/5 ring-1 ring-white/10
                               hover:bg-white/10 transition">
                        <i class="fa-brands fa-whatsapp"></i>
                        WhatsApp
                    </a>
                </div>
            </div>

            <!-- Links -->
            <div class="md:col-span-7">
                <div class="grid gap-8 sm:grid-cols-2 md:grid-cols-2">
                    <div>
                        <div class="text-xs font-semibold tracking-widest text-white/40">NAVIGATION</div>
                        <div class="mt-4 grid gap-3 text-sm text-white/70">
                            <a href="{{ route('home') }}" class="hover:text-white transition">Beranda</a>
                            <a href="{{ route('services.index') }}" class="hover:text-white transition">Layanan</a>
                            <a href="{{ route('portfolio.index') }}" class="hover:text-white transition">Portfolio</a>
                            <a href="{{ route('about.index') }}" class="hover:text-white transition">Tentang Kami</a>
                            <a href="{{ route('contact.index') }}" class="hover:text-white transition">Hubungi Kami</a>
                            <a href="{{ route('articles.index') }}" class="hover:text-white transition">Artikel</a>
                        </div>
                    </div>

                    <div>
                        <div class="text-xs font-semibold tracking-widest text-white/40">CONTACT</div>
                        <div class="mt-4 grid gap-3 text-sm text-white/70">
                            <a href="mailto:hello@veloradigital.com" class="hover:text-white transition">
                                hello@veloradigital.com
                            </a>
                            <a href="https://wa.me/628970038125" target="_blank" rel="noopener"
                                class="hover:text-white transition">
                                +62 8970038125
                            </a>
                            <span class="text-white/55">Indonesia</span>
                        </div>
                    </div>


                </div>
            </div>

        </div>

        <!-- Bottom -->
        <div
            class="mt-10 pt-6 border-t border-white/10 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
            <p class="text-xs text-white/55">
                © {{ date('Y') }} Velora Digital. All rights reserved.
            </p>

            <div class="flex items-center gap-4 text-xs text-white/55">
                <span class="hidden sm:inline">Built with care.</span>
                <span class="h-4 w-px bg-white/10 hidden sm:inline"></span>
                <a href="#" class="hover:text-white transition">Back to top</a>
            </div>
        </div>
    </div>
</footer>
