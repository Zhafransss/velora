@props(['service'])

@php
    $items = $service->serviceFeatures;
@endphp

<section class="bg-slate-50 py-16">
    <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">

        <!-- Heading -->
        <div class="mx-auto max-w-3xl text-center">
            <h2 class="text-2xl sm:text-4xl font-semibold tracking-tight text-slate-900">
                Fitur {{ $service->category->name }}
            </h2>

            <p class="mt-4 leading-relaxed text-slate-600">
                Fitur inti yang membantu website tampil profesional, mudah digunakan,
                dan siap untuk kebutuhan bisnis.
            </p>
        </div>

        <!-- Cards -->
        <div class="mt-12 grid gap-6 sm:grid-cols-2 lg:grid-cols-4">

            @foreach ($items as $feature)

                <div class="border border-slate-200 bg-white p-7 transition hover:shadow-lg">

                    <!-- top accent -->
                    <div class="h-[2px] w-14 bg-gradient-to-r from-[#20B7FF] via-[#696FC7] to-[#7C3AED]"></div>

                    <!-- icon -->
                    <div class="mt-6 inline-flex h-12 w-12 items-center justify-center border border-slate-200 bg-slate-50">

                        @if($feature->icon)
                            <i class="{{ $feature->icon }} text-lg text-slate-800"></i>
                        @else
                            <i class="fa-solid fa-bolt text-lg text-slate-800"></i>
                        @endif

                    </div>

                    <!-- text -->
                    <h3 class="mt-5 text-base font-semibold text-slate-900">
                        {{ $feature->title }}
                    </h3>

                    <p class="mt-2 text-sm leading-relaxed text-slate-600">
                        {{ $feature->description }}
                    </p>

                </div>

            @endforeach

        </div>

    </div>
</section>