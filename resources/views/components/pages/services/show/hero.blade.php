@props(['service'])


<style>
.hero-section {
    min-height: 50vh;
}

@media (min-width: 1024px) {
    .hero-section {
        min-height: 100vh;
    }
}
</style>
<section class="hero-section relative overflow-hidden bg-gradient-to-br from-[#20B7FF] via-[#696FC7] to-[#7C3AED]">

    <!-- decorative glow -->
    <div class="pointer-events-none absolute inset-0">
        <div class="absolute -top-40 -left-40 h-[600px] w-[600px] rounded-full blur-3xl bg-white/10"></div>
        <div class="absolute -bottom-40 -right-40 h-[600px] w-[600px] rounded-full blur-3xl bg-black/10"></div>
    </div>

    <div class="relative mx-auto max-w-7xl px-4 sm:px-6 lg:px-8 py-20">
        <div class="grid gap-14 lg:grid-cols-12 lg:items-center">

            <!-- LEFT CONTENT -->
            <div class="lg:col-span-6 text-white">

                <p class="text-sm font-semibold tracking-[0.25em] uppercase text-white/80">
                    Layanan Digital Profesional
                </p>

                <h1 class="mt-5 text-3xl sm:text-5xl font-semibold leading-tight">
                    Solusi Website Modern untuk Bisnis Anda
                </h1>

                <div class="mt-6 h-[3px] w-24 bg-white/60"></div>

                <p class="mt-6 text-white/85 leading-relaxed max-w-xl">
                    {{ $service->hero_description }}
                </p>

                <!-- Buttons -->
                <div class="mt-10 flex flex-col sm:flex-row gap-4">

                    <a href="https://wa.me/628970038125?text=Halo%20Velora%20Digital"
                        class="w-full sm:w-auto inline-flex items-center justify-center gap-2 whitespace-nowrap
                   px-6 py-3 text-sm font-semibold text-white
                  bg-[#25D366] hover:bg-[#20c45a]
                  shadow-[0_18px_45px_rgba(37,211,102,0.25)]
                  transition">
                        <i class="fa-brands fa-whatsapp text-base leading-none"></i>
                        Konsultasi Sekarang
                    </a>

                    <a href="#paket"
                        class="inline-flex items-center justify-center px-6 py-3 text-sm font-semibold
                    border border-white/40 text-white hover:bg-white/10 transition">
                        Lihat Paket
                    </a>



                </div>


            </div>

            <div class="lg:col-span-6">
    <div class="relative hidden sm:block">
        <div class="relative overflow-hidden p-6  shadow-2xl">
            <div class=" relative aspect-[4/3] w-full overflow-hidden rounded-2xl  p-6">
                @if ($service->hero_image)
                    <img src="{{ \App\Support\Media::url($service->hero_image) }}"
                        alt="{{ $service->category->name }}"
                        class="h-full w-full object-contain transition duration-500 hover:scale-[1.02]">
                @else
                    <div class="flex h-full w-full items-center justify-center text-slate-500">
                        Ilustrasi Layanan
                    </div>
                @endif
            </div>
        </div>

        <div class="absolute -bottom-6 -left-6 hidden sm:block">
            <div class="bg-white px-5 py-3 text-slate-900 shadow-lg">
                <p class="text-xs uppercase tracking-wider text-slate-500">
                    Estimasi Pengerjaan
                </p>
                <p class="text-sm font-semibold">
                    3 – 7 Hari Kerja
                </p>
            </div>
        </div>
    </div>
</div>

        </div>
    </div>

</section>
<script>
function setHeroHeight() {
    const hero = document.querySelector('.hero-section');
    if (window.innerWidth < 1024) {
        hero.style.minHeight = "50vh";
    } else {
        hero.style.minHeight = "100vh";
    }
}
setHeroHeight();
window.addEventListener('resize', setHeroHeight);
</script>