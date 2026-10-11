@props([
    'articles' => collect(),
])

@if ($articles->count())
    <section class="bg-slate-50 py-16 sm:py-20">
        <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
            <div class="max-w-2xl">
                <h2 class="text-2xl font-semibold text-slate-900 sm:text-3xl">
                    Artikel terkait
                </h2>
                <p class="mt-3 text-slate-600">
                    Baca juga insight lain yang masih relevan dengan topik ini.
                </p>
            </div>

            <div class="mt-10 grid gap-6 sm:grid-cols-2 lg:grid-cols-3">
                @foreach ($articles as $article)
                    <x-pages.articles.article-card :article="$article" />
                @endforeach
            </div>
        </div>
    </section>
@endif