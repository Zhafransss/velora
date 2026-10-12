<?php

use App\Http\Controllers\HomeController;
use App\Http\Controllers\PortfolioController;
use App\Http\Controllers\ServiceController;
use App\Http\Controllers\ArticleController;
use App\Models\Article;
use App\Models\Portfolio;
use App\Models\Service;
use App\Http\Controllers\ContactMessageController;
use Illuminate\Support\Facades\Route;



Route::get('/', [HomeController::class, 'index'])->name('home');
Route::get('/contact', [ContactMessageController::class, 'index'])->name('contact.index');
Route::view('/about-us', 'pages.about.index')->name('about.index');

Route::get('/portfolio', [PortfolioController::class, 'index'])->name('portfolio.index');
Route::get('/portfolio/{slug}', [PortfolioController::class, 'show'])->name('portfolio.show');


Route::get('/services', [ServiceController::class, 'index'])
    ->name('services.index');

Route::get('/services/{slug}', [ServiceController::class, 'show'])
    ->name('services.show');

Route::post('/contact', [ContactMessageController::class, 'store'])
    ->name('contact-messages.store');



Route::get('/articles', [ArticleController::class, 'index'])->name('articles.index');
Route::get('/articles/{slug}', [ArticleController::class, 'show'])->name('articles.show');

Route::get('/sitemap.xml', function () {
    $staticPages = collect([
        [
            'loc' => route('home'),
            'lastmod' => now()->toDateString(),
            'priority' => '1.0',
        ],
        [
            'loc' => route('services.index'),
            'lastmod' => now()->toDateString(),
            'priority' => '0.9',
        ],
        [
            'loc' => route('portfolio.index'),
            'lastmod' => now()->toDateString(),
            'priority' => '0.9',
        ],
        [
            'loc' => route('articles.index'),
            'lastmod' => now()->toDateString(),
            'priority' => '0.8',
        ],
        [
            'loc' => route('about.index'),
            'lastmod' => now()->toDateString(),
            'priority' => '0.7',
        ],
        [
            'loc' => route('contact.index'),
            'lastmod' => now()->toDateString(),
            'priority' => '0.7',
        ],
    ]);

    $services = Service::query()
    ->with('category:id,slug')
    ->select('id', 'category_id', 'updated_at')
    ->get()
    ->filter(function ($item) {
        return !empty(optional($item->category)->slug);
    })
    ->map(function ($item) {
        return [
            'loc' => route('services.show', $item->category->slug),
            'lastmod' => optional($item->updated_at)->toDateString(),
            'priority' => '0.9',
        ];
    });

    $portfolios = Portfolio::query()
        ->select('slug', 'updated_at')
        ->get()
        ->map(function ($item) {
            return [
                'loc' => route('portfolio.show', $item->slug),
                'lastmod' => optional($item->updated_at)->toDateString(),
                'priority' => '0.8',
            ];
        });

    $articles = Article::query()
        ->select('slug', 'updated_at')
        ->get()
        ->map(function ($item) {
            return [
                'loc' => route('articles.show', $item->slug),
                'lastmod' => optional($item->updated_at)->toDateString(),
                'priority' => '0.8',
            ];
        });

    $urls = $staticPages
        ->concat($services)
        ->concat($portfolios)
        ->concat($articles);

    return response()
        ->view('sitemap', ['urls' => $urls])
        ->header('Content-Type', 'application/xml');
});
// Invoice PDF (hanya admin yang login ke panel Filament)
Route::get('/admin/invoices/{invoice}/pdf', \App\Http\Controllers\InvoicePdfController::class)
    ->middleware(\Filament\Http\Middleware\Authenticate::class)
    ->name('invoices.pdf');

require __DIR__ . '/tenant.php';
