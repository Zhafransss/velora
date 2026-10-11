@props([
    'articles',
])

<section class="bg-white pb-20 sm:pb-24">
    <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">

        <div class="grid gap-8 sm:grid-cols-2 lg:grid-cols-3">

            @forelse ($articles as $article)
                <x-pages.articles.article-card :article="$article" />
            @empty
                <div
                    class="col-span-full rounded-2xl border border-dashed border-slate-200 bg-slate-50 px-6 py-12 text-center text-slate-600">
                    Belum ada artikel yang dipublikasikan.
                </div>
            @endforelse

        </div>

        @if ($articles instanceof \Illuminate\Contracts\Pagination\Paginator || $articles instanceof \Illuminate\Contracts\Pagination\LengthAwarePaginator)
            <div class="mt-14 flex justify-center">
                {{ $articles->links() }}
            </div>
        @endif

    </div>
</section>