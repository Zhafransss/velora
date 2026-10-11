<section class="relative overflow-hidden mx-auto bg-white" data-aos="fade-up" data-aos-duration="1000">
    <!-- subtle gradient background -->
    <div class="pointer-events-none absolute inset-0">
        <div
            class="absolute -top-40 left-1/2 h-[520px] w-[520px] -translate-x-1/2 rounded-full blur-3xl
                   bg-gradient-to-tr from-[#20B7FF]/20 via-[#696FC7]/15 to-[#7C3AED]/15">
        </div>
    </div>

    <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8 py-14 sm:py-16 lg:py-20 relative">
        <div class="grid items-center gap-12 lg:gap-16 lg:grid-cols-2">

            <!-- LEFT SIDE -->
            <div class="text-center lg:text-left" data-aos="fade-right" data-aos-delay="100" data-aos-duration="1000">
                <h1 class="text-4xl sm:text-5xl font-semibold tracking-tight text-slate-900 leading-tight">
                    Jasa pembuatan
                    <span class="animated-gradient-text">
                        website profesional
                    </span>
                    untuk bisnis Anda.
                </h1>

                <p class="mt-4 sm:mt-5 text-base sm:text-lg text-slate-600 max-w-xl mx-auto lg:mx-0 leading-relaxed"
                    data-aos="fade-up" data-aos-delay="250" data-aos-duration="900">
                    Desain modern, cepat, dan SEO-ready — siap bantu bisnis tampil meyakinkan dan dapet lebih banyak
                    customer.
                </p>

                <!-- CTA -->
                <div class="mt-7 sm:mt-8 flex flex-wrap justify-center lg:justify-start items-center gap-3 sm:gap-4"
                    data-aos="fade-up" data-aos-delay="400" data-aos-duration="900">
                    <!-- WhatsApp CTA -->
                    <x-ui.button href="https://wa.me/628970038125?text=Halo%20Velora%20Digital"
                        icon="fa-brands fa-whatsapp">
                        Konsultasi WhatsApp
                    </x-ui.button>

                    <!-- Portfolio button -->
                    <x-ui.button href="{{ route('portfolio.index') }}" variant="outline" icon="fa-solid fa-layer-group">
                        Lihat Portfolio
                    </x-ui.button>
                </div>

                <!-- subtle stats -->
                <div class="mt-9 sm:mt-10 hidden sm:flex items-center justify-center lg:justify-start gap-8 text-sm text-slate-500"
                    data-aos="fade-up" data-aos-delay="550" data-aos-duration="900">
                    <div>
                        <div class="text-xl font-semibold text-slate-900">50+</div>
                        Website live
                    </div>
                    <div>
                        <div class="text-xl font-semibold text-slate-900">7–14 hari</div>
                        Estimasi
                    </div>
                    <div>
                        <div class="text-xl font-semibold text-slate-900">98%</div>
                        Kepuasan
                    </div>
                </div>
            </div>

            <!-- RIGHT SIDE (IMAGE) -->
            <div class="relative flex items-center justify-center" data-aos="zoom-in" data-aos-delay="250"
    data-aos-duration="1200">
    <div
        class="absolute h-[360px] w-[360px] sm:h-[420px] sm:w-[420px] rounded-full blur-3xl
               bg-gradient-to-tr from-[#20B7FF]/20 via-[#696FC7]/20 to-[#7C3AED]/20">
    </div>

    <img
        src="{{ asset('images/hero-v2.webp') }}"
        alt="Velora Illustration"
        width="1536"
        height="1024"
        loading="eager" 
     fetchpriority="high"
        decoding="async"
        class="relative z-10 w-full max-w-md sm:max-w-xl animate-float
               drop-shadow-[0_40px_80px_rgba(105,111,199,0.25)]">
</div>

        </div>
    </div>

   <style>
    .animated-gradient-text {
        background: linear-gradient(90deg, #20B7FF, #696FC7, #7C3AED, #20B7FF);
        background-size: 200% auto;
        -webkit-background-clip: text;
        -webkit-text-fill-color: transparent;
        background-clip: text;
        animation: gradientMove 4s linear infinite;
    }

    @keyframes gradientMove {
        0% {
            background-position: 0% center;
        }
        100% {
            background-position: 200% center;
        }
    }
</style>

<style>
    .animated-gradient-text {
        background: linear-gradient(90deg, #20B7FF, #696FC7, #7C3AED, #20B7FF);
        background-size: 200% auto;
        -webkit-background-clip: text;
        -webkit-text-fill-color: transparent;
        background-clip: text;
        animation: gradientMove 4s linear infinite;
    }

    @keyframes gradientMove {
        0% {
            background-position: 0% center;
        }
        100% {
            background-position: 200% center;
        }
    }

    /* animasi gambar naik turun */
    @keyframes float {
        0%, 100% {
            transform: translateY(0);
        }
        50% {
            transform: translateY(-12px);
        }
    }

    .animate-float {
        animation: float 6s ease-in-out infinite;
    }
</style>
</section>
