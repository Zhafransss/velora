@props([
    'article',
])

<section class="bg-white pt-16 pb-10 sm:pt-20 sm:pb-12">
    <div class="mx-auto max-w-4xl px-4 sm:px-6 lg:px-8">

        <!-- META -->
        <div class="flex items-center gap-3 text-sm">

            @if ($article->category)
                <span
                    class="inline-flex items-center rounded-full bg-indigo-50 px-3 py-1 text-xs font-semibold text-indigo-600">
                    {{ $article->category }}
                </span>
            @endif

            @if ($article->published_at)
                <span class="text-slate-500">
                    {{ $article->published_at->format('d M Y') }}
                </span>
            @endif

        </div>

        <!-- TITLE -->
        <h1 class="mt-4 text-3xl font-semibold leading-tight tracking-tight text-slate-900 sm:text-5xl">
            {{ $article->title }}
        </h1>

        <!-- IMAGE -->
        <div class="mt-8 overflow-hidden rounded-2xl border border-slate-200 shadow-sm">

            <div class="relative aspect-[16/9] bg-slate-100">
                <img
                    src="{{ \App\Support\Media::url($article->thumbnail, asset('images/placeholder.jpg')) }}"
                    alt="{{ $article->title }}"
                    fetchpriority="high" decoding="async"
                    class="h-full max-w-3xl w-full object-cover">
            </div>

        </div>

    </div>
</section>