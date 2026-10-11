<section id="about" class="relative overflow-hidden bg-white" data-aos="fade-up" data-aos-duration="1000">
    <!-- background glow -->
    <div class="pointer-events-none absolute inset-0">
        <div
            class="absolute -top-44 left-1/2 h-[560px] w-[560px] -translate-x-1/2 rounded-full blur-3xl
                   bg-gradient-to-tr from-[#20B7FF]/18 via-[#696FC7]/14 to-[#7C3AED]/14">
        </div>
        <div
            class="absolute -bottom-40 right-[-120px] h-[520px] w-[520px] rounded-full blur-3xl
                   bg-gradient-to-tr from-[#7C3AED]/12 via-[#696FC7]/10 to-[#20B7FF]/12">
        </div>
    </div>

    <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8 py-14 sm:py-16 lg:py-20 relative">
        <div class="grid items-center gap-12 lg:grid-cols-2">

            <!-- LEFT: ABOUT -->
            <div class="text-center lg:text-left" data-aos="fade-right" data-aos-delay="100" data-aos-duration="1000">
                <!-- badge -->
                <div
                    class="inline-flex items-center gap-2 rounded-full border border-slate-200 bg-white/70 backdrop-blur px-4 py-2 text-sm font-semibold text-slate-700 shadow-[0_10px_30px_rgba(15,23,42,0.06)]"
                    data-aos="fade-up" data-aos-delay="150">
                    <span
                        class="inline-block h-2 w-2 rounded-full bg-gradient-to-r from-[#20B7FF] via-[#696FC7] to-[#7C3AED]"></span>
                    Tentang Velora Digital
                </div>

                <h2 class="mt-5 text-4xl sm:text-5xl font-semibold tracking-tight text-slate-900 leading-tight"
                    data-aos="fade-up" data-aos-delay="250">
                    Kami bantu bisnis tampil
                    <span
                        class="bg-gradient-to-r from-[#20B7FF] via-[#696FC7] to-[#7C3AED] bg-clip-text text-transparent">
                        lebih meyakinkan
                    </span>
                    di dunia digital.
                </h2>

                <p class="mt-4 sm:mt-5 text-base sm:text-lg text-slate-600 max-w-xl mx-auto lg:mx-0 leading-relaxed"
                    data-aos="fade-up" data-aos-delay="350">
                    Velora Digital adalah tim kreatif yang fokus pada pembuatan website modern, cepat, dan mudah
                    dikelola.
                    Kami mengutamakan desain yang rapi, struktur yang jelas, serta pengalaman pengguna yang nyaman agar
                    website Anda bukan hanya bagus—tapi juga menghasilkan.
                </p>

                <!-- highlights -->
                <div class="mt-7 grid gap-3 max-w-xl mx-auto lg:mx-0">
                    <div
                        class="flex items-start gap-3 rounded-2xl border border-slate-200 bg-white/70 backdrop-blur p-4"
                        data-aos="fade-up" data-aos-delay="450">
                        <span class="mt-0.5 grid h-9 w-9 place-items-center rounded-xl bg-slate-900 text-white">
                            <i class="fa-solid fa-pen-ruler text-sm"></i>
                        </span>
                        <div class="text-left">
                            <div class="font-semibold text-slate-900">Desain premium & konsisten</div>
                            <div class="text-sm text-slate-600">Tampilan modern yang sesuai brand dan mudah dipercaya.
                            </div>
                        </div>
                    </div>

                    <div
                        class="flex items-start gap-3 rounded-2xl border border-slate-200 bg-white/70 backdrop-blur p-4"
                        data-aos="fade-up" data-aos-delay="550">
                        <span class="mt-0.5 grid h-9 w-9 place-items-center rounded-xl bg-slate-900 text-white">
                            <i class="fa-solid fa-bolt text-sm"></i>
                        </span>
                        <div class="text-left">
                            <div class="font-semibold text-slate-900">Cepat, ringan, SEO-ready</div>
                            <div class="text-sm text-slate-600">Struktur rapi untuk performa dan visibilitas di Google.
                            </div>
                        </div>
                    </div>

                    <div
                        class="flex items-start gap-3 rounded-2xl border border-slate-200 bg-white/70 backdrop-blur p-4"
                        data-aos="fade-up" data-aos-delay="650">
                        <span class="mt-0.5 grid h-9 w-9 place-items-center rounded-xl bg-slate-900 text-white">
                            <i class="fa-solid fa-handshake text-sm"></i>
                        </span>
                        <div class="text-left">
                            <div class="font-semibold text-slate-900">Kolaborasi enak & jelas</div>
                            <div class="text-sm text-slate-600">Brief → desain → development → launching, transparan.
                            </div>
                        </div>
                    </div>
                </div>

                <!-- CTA -->
                <div class="mt-8 flex flex-wrap justify-center lg:justify-start items-center gap-3 sm:gap-4"
                    data-aos="fade-up" data-aos-delay="750">

                    <x-ui.button href="{{ route('about.index') }}"
                        icon="fa-solid fa-circle-info text-sm">
                        Tentang Kami
                    </x-ui.button>

                    <!-- Contact -->
                    <a href="{{ route('portfolio.index') }}"
                        class="inline-flex items-center gap-2 rounded-xl px-6 py-3 text-sm font-semibold
              text-slate-800 bg-white border border-slate-200
              shadow-[0_10px_30px_rgba(15,23,42,0.06)]
              hover:bg-slate-50 transition">
                        Kontak Kami
                        <i class="fa-regular fa-paper-plane text-sm"></i>
                    </a>

                </div>
            </div>

            <!-- RIGHT: ABOUT CARD (minimal, bukan hero) -->
            <div class="relative" data-aos="fade-left" data-aos-delay="250" data-aos-duration="1100">
                <div
                    class="absolute -inset-4 rounded-3xl blur-2xl
                            bg-gradient-to-tr from-[#20B7FF]/18 via-[#696FC7]/16 to-[#7C3AED]/18">
                </div>

                <div
                    class="relative overflow-hidden rounded-3xl border border-slate-200 bg-white/85 backdrop-blur
                            shadow-[0_25px_70px_rgba(15,23,42,0.10)]">

                    <!-- top -->
                    <div class="p-6 sm:p-8">
                        <div class="flex items-start justify-between gap-6">
                            <div>
                                <h3 class="mt-2 text-2xl font-semibold text-slate-900 leading-tight">
                                    Website yang rapi, cepat, dan siap dipakai jualan.
                                </h3>
                                <p class="mt-3 text-slate-600 text-sm leading-relaxed">
                                    Kami fokus pada hasil: tampilan profesional, struktur jelas, dan pengalaman pengguna
                                    yang nyaman.
                                </p>
                            </div>

                            <div class="hidden sm:block">
                                <div class="rounded-2xl border border-slate-200 bg-white px-4 py-3 shadow-sm"
                                    data-aos="zoom-in" data-aos-delay="500">
                                    <div class="text-xs font-semibold text-slate-500">Response</div>
                                    <div class="text-lg font-bold text-slate-900">Fast</div>
                                </div>
                            </div>
                        </div>

                        <!-- mini list -->
                        <div class="mt-6 space-y-3">
                            <div class="flex items-center gap-3" data-aos="fade-up" data-aos-delay="450">
                                <span class="grid h-9 w-9 place-items-center rounded-xl bg-slate-100 text-slate-700">
                                    <i class="fa-solid fa-check"></i>
                                </span>
                                <div>
                                    <div class="font-semibold text-slate-900">Proses jelas</div>
                                    <div class="text-sm text-slate-600">Timeline & revisi terarah</div>
                                </div>
                            </div>

                            <div class="flex items-center gap-3" data-aos="fade-up" data-aos-delay="550">
                                <span class="grid h-9 w-9 place-items-center rounded-xl bg-slate-100 text-slate-700">
                                    <i class="fa-solid fa-check"></i>
                                </span>
                                <div>
                                    <div class="font-semibold text-slate-900">Maintainable</div>
                                    <div class="text-sm text-slate-600">Mudah update konten</div>
                                </div>
                            </div>

                            <div class="flex items-center gap-3" data-aos="fade-up" data-aos-delay="650">
                                <span class="grid h-9 w-9 place-items-center rounded-xl bg-slate-100 text-slate-700">
                                    <i class="fa-solid fa-check"></i>
                                </span>
                                <div>
                                    <div class="font-semibold text-slate-900">After launch support</div>
                                    <div class="text-sm text-slate-600">Bantuan setelah website live</div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- bottom strip -->
                    <div class="px-6 py-4 border-t border-slate-200/70 bg-white" data-aos="fade-up"
                        data-aos-delay="700">
                        <div class="flex flex-wrap gap-3 text-sm text-slate-600">
                            <span
                                class="inline-flex items-center gap-2 rounded-full border border-slate-200 bg-white px-3 py-2">
                                <span class="h-2 w-2 rounded-full bg-[#20B7FF]"></span> Modern UI
                            </span>
                            <span
                                class="inline-flex items-center gap-2 rounded-full border border-slate-200 bg-white px-3 py-2">
                                <span class="h-2 w-2 rounded-full bg-[#696FC7]"></span> Performance
                            </span>
                            <span
                                class="inline-flex items-center gap-2 rounded-full border border-slate-200 bg-white px-3 py-2">
                                <span class="h-2 w-2 rounded-full bg-[#7C3AED]"></span> SEO
                            </span>
                        </div>
                    </div>
                </div>
            </div>

        </div>
    </div>
</section>