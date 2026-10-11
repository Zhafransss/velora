<section id="contact-form" class="relative overflow-hidden bg-white pb-16 sm:pb-20">
    <!-- soft glow -->
    <div class="pointer-events-none absolute inset-0">
        <div
            class="absolute -bottom-52 left-1/2 h-[520px] w-[520px] -translate-x-1/2 rounded-full blur-3xl
                bg-gradient-to-tr from-[#20B7FF]/10 via-[#696FC7]/10 to-[#7C3AED]/10">
        </div>
    </div>

    <div class="relative mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
        <div class="grid gap-8 lg:grid-cols-12 lg:items-start">

            <!-- Form Card -->
            <div class="lg:col-span-7">
                <div
                    class="rounded-3xl border border-slate-200 bg-white/70 backdrop-blur p-6 sm:p-8
                    shadow-[0_25px_70px_rgba(15,23,42,0.10)]">
                    <h2 class="text-xl font-semibold text-slate-900">Kirim Pesan</h2>
                    <p class="mt-1 text-sm text-slate-600">
                        Isi data singkat. Kami akan hubungi via WhatsApp / email.
                    </p>

                    <!-- Alert success/error (opsional Laravel) -->
                    @if (session('success'))
                        <div
                            class="mt-5 rounded-2xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800">
                            {{ session('success') }}
                        </div>
                    @endif

                    <form action="{{ route('contact-messages.store') }}" method="POST" class="mt-6 space-y-4">
                        @csrf

                        <div class="grid gap-4 sm:grid-cols-2">
                            <div>
                                <label class="text-sm font-semibold text-slate-700">Nama</label>
                                <input name="name" required value="{{ old('name') }}"
                                    class="mt-2 w-full rounded-2xl border border-slate-200 bg-white px-4 py-3 text-sm text-slate-900
                         placeholder:text-slate-400 shadow-sm outline-none
                         focus:border-[#696FC7] focus:ring-4 focus:ring-[#696FC7]/15 transition"
                                    placeholder="Nama lengkap" />
                                @error('name')
                                    <div class="mt-1 text-xs text-red-600">{{ $message }}</div>
                                @enderror
                            </div>

                            <div>
                                <label class="text-sm font-semibold text-slate-700">Nomor WhatsApp</label>
                                <input name="phone" required value="{{ old('phone') }}"
                                    class="mt-2 w-full rounded-2xl border border-slate-200 bg-white px-4 py-3 text-sm text-slate-900
                         placeholder:text-slate-400 shadow-sm outline-none
                         focus:border-[#696FC7] focus:ring-4 focus:ring-[#696FC7]/15 transition"
                                    placeholder="cth: 0812xxxxxxx" />
                                @error('phone')
                                    <div class="mt-1 text-xs text-red-600">{{ $message }}</div>
                                @enderror
                            </div>
                        </div>

                        <div class="grid gap-4 sm:grid-cols-2">
                            <div>
                                <label class="text-sm font-semibold text-slate-700">Email (opsional)</label>
                                <input type="email" name="email" value="{{ old('email') }}"
                                    class="mt-2 w-full rounded-2xl border border-slate-200 bg-white px-4 py-3 text-sm text-slate-900
                         placeholder:text-slate-400 shadow-sm outline-none
                         focus:border-[#696FC7] focus:ring-4 focus:ring-[#696FC7]/15 transition"
                                    placeholder="email@domain.com" />
                                @error('email')
                                    <div class="mt-1 text-xs text-red-600">{{ $message }}</div>
                                @enderror
                            </div>

                            <div>
                                <label class="text-sm font-semibold text-slate-700">Jenis Website</label>
                                <select name="type" required
                                    class="mt-2 w-full rounded-2xl border border-slate-200 bg-white px-4 py-3 text-sm text-slate-900
                         shadow-sm outline-none
                         focus:border-[#696FC7] focus:ring-4 focus:ring-[#696FC7]/15 transition">
                                    <option value="" disabled {{ old('type') ? '' : 'selected' }}>Pilih</option>
                                    <option value="Company Profile" @selected(old('type') === 'Company Profile')>Company Profile
                                    </option>
                                    <option value="Landing Page" @selected(old('type') === 'Landing Page')>Landing Page</option>
                                    <option value="E-Commerce" @selected(old('type') === 'E-Commerce')>E-Commerce</option>
                                    <option value="Sistem / Dashboard" @selected(old('type') === 'Sistem / Dashboard')>Sistem / Dashboard
                                    </option>
                                    <option value="Lainnya" @selected(old('type') === 'Lainnya')>Lainnya</option>
                                </select>
                                @error('type')
                                    <div class="mt-1 text-xs text-red-600">{{ $message }}</div>
                                @enderror
                            </div>
                        </div>

                        <div>
                            <label class="text-sm font-semibold text-slate-700">Pesan</label>
                            <textarea name="message" rows="5" required
                                class="mt-2 w-full rounded-2xl border border-slate-200 bg-white px-4 py-3 text-sm text-slate-900
                       placeholder:text-slate-400 shadow-sm outline-none
                       focus:border-[#696FC7] focus:ring-4 focus:ring-[#696FC7]/15 transition"
                                placeholder="Ceritakan kebutuhan: jumlah halaman, fitur (WA, form, katalog, pembayaran), referensi desain, dll.">{{ old('message') }}</textarea>
                            @error('message')
                                <div class="mt-1 text-xs text-red-600">{{ $message }}</div>
                            @enderror
                        </div>

                        <div>
                            <div class="g-recaptcha" data-sitekey="{{ config('services.recaptcha.site_key') }}"></div>
                            @error('g-recaptcha-response')
                                <div class="mt-1 text-xs text-red-600">{{ $message }}</div>
                            @enderror
                        </div>

                        <div class="flex flex-col sm:flex-row sm:items-center gap-3 pt-2">
                            <button type="submit"
                                class="w-full sm:w-auto
         inline-flex items-center justify-center gap-2 whitespace-nowrap
         rounded-lg px-5 py-2.5 text-sm font-semibold text-white
         bg-gradient-to-r from-[#20B7FF] via-[#696FC7] to-[#7C3AED]
         shadow-[0_10px_25px_rgba(105,111,199,0.20)]
         hover:brightness-105 transition">

                                <i class="fa-solid fa-paper-plane text-xs"></i>
                                Kirim Pesan
                            </button>
                            <p class="text-xs text-slate-500">
                                Dengan mengirim form, kamu setuju dihubungi oleh tim Velora via WhatsApp / email.
                            </p>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Right Info -->
            <div class="lg:col-span-5 space-y-4">
                <div
                    class="rounded-3xl border border-slate-200 bg-white/70 backdrop-blur p-6
                    shadow-[0_25px_70px_rgba(15,23,42,0.10)]">
                    <h3 class="text-base font-semibold text-slate-900">Kontak Cepat</h3>

                    <div class="mt-4 space-y-3 text-sm text-slate-700">
                        <a class="flex items-center gap-3 rounded-2xl border border-slate-200 bg-white px-4 py-3 hover:bg-slate-50 transition"
                            href="https://wa.me/628970038125?text=Halo%20Velora%20Digital,%20saya%20ingin%20konsultasi%20pembuatan%20website."
                            target="_blank" rel="noopener">
                            <span
                                class="h-9 w-9 rounded-2xl grid place-items-center
                           bg-gradient-to-r from-[#20B7FF]/15 via-[#696FC7]/15 to-[#7C3AED]/15 border border-slate-200">
                                <svg class="h-5 w-5 text-slate-700" viewBox="0 0 24 24" fill="none"
                                    stroke="currentColor" stroke-width="2">
                                    <path
                                        d="M22 16.92v3a2 2 0 0 1-2.18 2 19.86 19.86 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6A19.86 19.86 0 0 1 2.08 4.18 2 2 0 0 1 4.06 2h3a2 2 0 0 1 2 1.72c.12.86.31 1.7.57 2.5a2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.58-1.09a2 2 0 0 1 2.11-.45c.8.26 1.64.45 2.5.57A2 2 0 0 1 22 16.92Z" />
                                </svg>
                            </span>
                            <div class="flex-1">
                                <div class="font-semibold text-slate-900">WhatsApp</div>
                                <div class="text-slate-500">Balas cepat (jam kerja)</div>
                            </div>
                            <span class="text-slate-400">↗</span>
                        </a>

                        <div class="flex items-center gap-3 rounded-2xl border border-slate-200 bg-white px-4 py-3">
                            <span
                                class="h-9 w-9 rounded-2xl grid place-items-center
                           bg-gradient-to-r from-[#20B7FF]/15 via-[#696FC7]/15 to-[#7C3AED]/15 border border-slate-200">
                                <svg class="h-5 w-5 text-slate-700" viewBox="0 0 24 24" fill="none"
                                    stroke="currentColor" stroke-width="2">
                                    <path d="M4 4h16v16H4z"></path>
                                    <path d="M22 6l-10 7L2 6"></path>
                                </svg>
                            </span>
                            <div>
                                <div class="font-semibold text-slate-900">Email</div>
                                <div class="text-slate-500">team@veloradigitalworks.com</div>
                            </div>
                        </div>
                    </div>
                </div>

                <div
                    class="rounded-3xl border border-slate-200 bg-white/70 backdrop-blur p-6
                    shadow-[0_25px_70px_rgba(15,23,42,0.10)]">
                    <h3 class="text-base font-semibold text-slate-900">Jam Operasional</h3>
                    <p class="mt-2 text-sm text-slate-600">Senin – Sabtu (09:00 – 18:00 WIB)</p>
                    <p class="mt-1 text-xs text-slate-500">*Di luar jam kerja tetap bisa kirim form, akan dibalas
                        setelahnya.</p>
                </div>
            </div>

        </div>
    </div>
</section>

 <script src="https://www.google.com/recaptcha/api.js" async defer></script>