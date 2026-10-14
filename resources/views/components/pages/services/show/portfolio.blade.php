@props(['portfolios'])

<section class="py-16">
    <div class="mx-auto max-w-6xl px-4 sm:px-6 lg:px-8">

        {{-- Header --}}
        <div class="mb-10">
            <h2 class="text-2xl font-semibold tracking-tight text-slate-900">
                Portfolio {{ $portfolios->first()?->category->name ?? '' }}
            </h2>

            <p class="mt-2 text-slate-600">
                Beberapa contoh project yang pernah kami kerjakan.
            </p>
        </div>

        {{-- Grid --}}
        <div class="grid gap-6 sm:grid-cols-2 lg:grid-cols-3">

            @foreach ($portfolios as $item)

                <a href="{{ route('portfolio.show', $item->slug) }}"
                   class="group relative block overflow-hidden rounded-2xl ring-1 ring-slate-200">

                    <div class="relative aspect-[16/10] overflow-hidden">

                        <img src="{{ \App\Support\Media::url($item->cover_image, asset('images/placeholder.jpg')) }}"
                             alt="{{ $item->title }}"
                             loading="lazy" decoding="async"
                             class="h-full w-full object-cover transition duration-500 group-hover:scale-[1.05]">

                        <div class="absolute inset-0 bg-gradient-to-t from-black/70 via-black/20 to-transparent"></div>

                        <div class="absolute bottom-0 left-0 right-0 p-6">

                            <div class="text-xs font-medium text-white/80">
                                {{ $item->category->name }}
                            </div>

                            <h3 class="mt-1 text-lg font-semibold leading-snug text-white">
                                {{ $item->title }}
                            </h3>

                        </div>

                    </div>

                </a>

            @endforeach

        </div>

        {{-- Button --}}
        <div class="mt-12 flex justify-center">

            <a href="{{ route('portfolio.index') }}"
               class="inline-flex items-center gap-2 rounded-xl bg-gradient-to-r from-[#20B7FF] via-[#696FC7] to-[#7C3AED] px-6 py-3 text-sm font-semibold text-white shadow-lg transition hover:scale-[1.03] hover:shadow-xl">

                Lihat Semua Portfolio

                <svg xmlns="http://www.w3.org/2000/svg"
                     class="h-4 w-4"
                     fill="none"
                     viewBox="0 0 24 24"
                     stroke="currentColor">

                    <path stroke-linecap="round"
                          stroke-linejoin="round"
                          stroke-width="2"
                          d="M9 5l7 7-7 7"/>

                </svg>

            </a>

        </div>

    </div>
</section>