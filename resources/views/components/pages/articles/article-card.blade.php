@props([
    'article',
])

<a href="{{ route('articles.show', $article->slug) }}"
    class="group block overflow-hidden rounded-2xl border border-slate-200 bg-white
    transition-all duration-300 hover:-translate-y-1 hover:shadow-xl">

    <!-- IMAGE -->
    <div class="relative aspect-[16/10] overflow-hidden bg-slate-100">

        <img
            src="{{ $article->thumbnail ? asset('storage/' . ltrim($article->thumbnail, '/')) : asset('images/placeholder.jpg') }}"
            alt="{{ $article->title }}"
            class="h-full w-full object-cover transition duration-500 group-hover:scale-[1.06]">

        <!-- overlay -->
        <div class="absolute inset-0 bg-gradient-to-t from-black/50 via-black/10 to-transparent opacity-80"></div>

        <!-- category badge -->
        @if ($article->category)
            <div class="absolute left-4 top-4">
                <span
                    class="rounded-full bg-gradient-to-r from-[#20B7FF] via-[#696FC7] to-[#7C3AED]
                    px-3 py-1 text-xs font-semibold text-white shadow-md">
                    {{ $article->category }}
                </span>
            </div>
        @endif

    </div>

    <!-- CONTENT -->
    <div class="p-6">

        <!-- TITLE -->
        <h3
            class="line-clamp-2 text-xl font-semibold leading-snug text-slate-900 transition group-hover:text-indigo-600">
            {{ $article->title }}
        </h3>

        <!-- DESCRIPTION -->
        @if ($article->excerpt)
            <p class="mt-3 line-clamp-2 text-sm leading-relaxed text-slate-600">
                {{ $article->excerpt }}
            </p>
        @endif

        <!-- META -->
        <div class="mt-4 flex items-center justify-between text-sm text-slate-500">

            @if ($article->published_at)
                <span>
                    {{ $article->published_at->format('d M Y') }}
                </span>
            @endif

            <span
                class="flex items-center gap-1 font-medium text-indigo-600 transition group-hover:opacity-100">
                Baca
                <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4"
                    fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round"
                        stroke-width="2" d="M9 5l7 7-7 7" />
                </svg>
            </span>

        </div>

    </div>
</a>