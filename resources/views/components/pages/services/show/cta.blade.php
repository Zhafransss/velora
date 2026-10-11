@props(['service'])

<section class="bg-slate-50 py-12 sm:py-16">
    <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
        <div class="rounded-2xl border border-slate-200 bg-white p-6 sm:p-8 lg:p-10">
            <div class="h-1 w-16 sm:w-20 rounded-full bg-gradient-to-r from-[#20B7FF] via-[#696FC7] to-[#7C3AED]"></div>

            <h3 class="mt-5 text-xl font-semibold text-slate-900 sm:mt-6 sm:text-2xl">
                Siap mulai?
            </h3>

            <p class="mt-3 max-w-2xl text-sm leading-6 text-slate-600 sm:text-base">
                Ceritakan kebutuhan Anda. Kami bantu rekomendasikan paket yang paling pas.
            </p>

            <div class="mt-6 flex flex-col gap-3 sm:mt-8 sm:flex-row sm:gap-4">
                <a href="https://wa.me/628970038125?text=Halo%20Velora%20Digital"
                    class="inline-flex w-full items-center justify-center rounded-xl px-5 py-3 text-sm font-semibold text-white
                           bg-gradient-to-r from-[#20B7FF] via-[#696FC7] to-[#7C3AED]
                           hover:opacity-90 transition sm:w-auto sm:px-6">
                    Konsultasi Gratis
                </a>

                <a href="{{ route('services.index') }}"
                    class="inline-flex w-full items-center justify-center rounded-xl border border-slate-900 px-5 py-3 text-sm font-semibold
                           text-slate-900 hover:bg-slate-900 hover:text-white transition sm:w-auto sm:px-6">
                    Kembali ke Semua Layanan
                </a>
            </div>
        </div>
    </div>
</section>