@props(['services'])


<section class="bg-white py-20">
  <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">

    <div class="grid gap-8 sm:grid-cols-2 lg:grid-cols-4">

      @foreach ($services->sortBy(fn($service) => $service->cheapestPackage->price ?? 0) as $index => $service)
        <div class="group border border-slate-200 bg-white transition duration-300 hover:shadow-lg">

          <!-- Gradient Top Accent -->
          <div class="h-1 w-full bg-gradient-to-r from-[#20B7FF] via-[#696FC7] to-[#7C3AED]"></div>

          <div class="px-6 py-8">

            <!-- Title -->
            <h3 class="text-base font-semibold text-slate-900">
              {{ $service->category->name }}
            </h3>

            <!-- Mulai Dari Label -->
            <p class="mt-4 text-xs uppercase tracking-wider text-slate-400 font-medium">
              Mulai dari
            </p>

            <!-- Price -->
            <div class="mt-1 flex items-baseline gap-1">
              <span class="text-sm font-medium text-slate-500">Rp</span>
              <span class="text-3xl font-bold tracking-tight text-slate-900">
                {{ $service->cheapestPackage ? number_format($service->cheapestPackage->price, 0, ',', '.') : '-' }}
              </span>
            </div>

        

            

            <!-- Features -->
            <ul class="mt-6 space-y-2 text-sm text-slate-700">
                @if ($service->cheapestPackage && $service->cheapestPackage->features->count())
                            <ul class="mt-6 space-y-2 text-sm text-slate-700">
                                @foreach ($service->cheapestPackage->features as $feature)
                                    <li class="flex items-start gap-2">
                                        {{-- CHECK ICON --}}
                                        <span style="
                                            display:inline-flex;
                                            align-items:center;
                                            justify-content:center;
                                            width:18px;
                                            height:18px;
                                            background:#7c3aed;
                                            color:#fff;
                                            font-size:12px;
                                            border-radius:50%;
                                            flex-shrink:0;
                                            margin-top:2px;
                                        ">
                                            ✓
                                        </span>
                                        <span>{{ $feature->text }}</span>
                                    </li>
                                @endforeach
                            </ul>
                        @endif
            </ul>

            <!-- Button -->
            <a href="{{ route('services.show', $service->category->slug) }}"
               class="mt-8 inline-flex w-full items-center justify-center
                      px-4 py-3 text-sm font-semibold text-white
                      bg-gradient-to-r from-[#20B7FF] via-[#696FC7] to-[#7C3AED]
                      transition hover:opacity-90">
              Lihat Semua Paket
            </a>

          </div>
        </div>
      @endforeach

    </div>

  </div>
</section>