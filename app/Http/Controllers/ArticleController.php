<?php

namespace App\Http\Controllers;

use App\Models\Article;

class ArticleController extends Controller
{
    public function index()
    {
        $articles = Article::published()
            ->latest('published_at')
            ->paginate(9);

        return view('pages.articles.index', compact('articles'));
    }

    public function show(string $slug)
    {
        $article = Article::published()
            ->where('slug', $slug)
            ->firstOrFail();

        $relatedArticles = Article::published()
            ->where('id', '!=', $article->id)
            ->when(
                filled($article->category),
                fn ($query) => $query->where('category', $article->category)
            )
            ->latest('published_at')
            ->limit(3)
            ->get();

        if ($relatedArticles->count() < 3) {
            $excludeIds = $relatedArticles->pluck('id')->push($article->id);

            $additionalArticles = Article::published()
                ->whereNotIn('id', $excludeIds)
                ->latest('published_at')
                ->limit(3 - $relatedArticles->count())
                ->get();

            $relatedArticles = $relatedArticles->concat($additionalArticles);
        }

        return view('pages.articles.show.index', compact('article', 'relatedArticles'));
    }
}