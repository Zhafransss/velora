@props(['services'])

<section id="pricing" class="bg-slate-50 py-16 sm:py-20" data-aos="fade-up" data-aos-duration="1000">
    <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">

        <div class="mx-auto max-w-2xl text-center" data-aos="fade-up" data-aos-delay="100">
            <h2 class="text-3xl font-semibold text-slate-900 sm:text-4xl">LAYANAN UNGGULAN</h2>
            <p class="mt-4 text-slate-600">
 
            </p>
        </div>

        <div class="mt-12 grid gap-6 sm:grid-cols-2 lg:grid-cols-4">

            @foreach ($services->sortBy(fn($service) => $service->cheapestPackage->price ?? 0) as $index => $service)
                <div
                    class="overflow-hidden rounded-3xl border border-slate-200 bg-white shadow-sm transition hover:shadow-md"
                    data-aos="fade-up"
                    data-aos-delay="{{ 150 + ($index * 100) }}"
                    data-aos-duration="900">

                    <div
                        class="bg-gradient-to-r from-[#20B7FF] via-[#696FC7] to-[#7C3AED] px-6 py-5 text-center text-white">
                        <div class="text-lg font-semibold">
                            {{ $service->category->name }}
                        </div>
                        <div class="mt-1 text-sm text-white/90">
                            Mulai dari
                        </div>
                    </div>

                    <div class="px-6 py-7 text-center">
                        <div class="flex items-baseline justify-center gap-2">
                            <span class="text-xs font-semibold text-slate-500">Rp</span>
                            <span class="text-4xl font-bold tracking-tight text-slate-900">
                                {{ $service->cheapestPackage ? number_format($service->cheapestPackage->price, 0, ',', '.') : '-' }}
                            </span>
                        </div>

                        <div class="mt-2 text-sm text-slate-500">
                            {{ $service->cheapestPackage?->name ?? 'Paket belum tersedia' }}
                        </div>

                        @if ($service->cheapestPackage && $service->cheapestPackage->features->count())
                            <ul class="mt-6 divide-y divide-slate-100 text-sm text-slate-700">
                                @foreach ($service->cheapestPackage->features as $feature)
                                    <li class="py-3">
                                        {{ $feature->text }}
                                    </li>
                                @endforeach
                            </ul>
                        @else
                            <ul class="mt-6 divide-y divide-slate-100 text-sm text-slate-700">
                                <li class="py-3 text-slate-400">Fitur belum tersedia</li>
                            </ul>
                        @endif

                        <a href="{{ route('services.show', $service->category->slug) }}"
                            class="mt-7 inline-flex w-full items-center justify-center rounded-2xl bg-gradient-to-r from-[#20B7FF] via-[#696FC7] to-[#7C3AED] px-5 py-3 text-sm font-semibold text-white shadow-[0_18px_45px_rgba(105,111,199,0.22)] transition hover:brightness-105">
                            Lihat Paket
                        </a>

                        <p class="mt-4 text-xs text-slate-500">
                            {{ \Illuminate\Support\Str::limit($service->hero_description, 60) }}
                        </p>
                    </div>
                </div>
            @endforeach

        </div>

        <div class="mt-10 text-center" data-aos="fade-up" data-aos-delay="250">
            <a href="{{ route('services.index') }}"
                class="mt-6 inline-flex items-center justify-center rounded-xl bg-gradient-to-r from-[#20B7FF] via-[#696FC7] to-[#7C3AED] px-6 py-3 text-sm font-semibold text-white shadow-[0_18px_45px_rgba(105,111,199,0.25)] transition hover:brightness-105">
                Lihat Semua Layanan
                <i class="fa-solid fa-arrow-right text-sm mx-2"></i>
            </a>
        </div>

    </div>
</section>