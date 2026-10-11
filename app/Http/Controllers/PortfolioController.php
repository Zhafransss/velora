<?php

namespace App\Http\Controllers;

use App\Models\Category;
use App\Models\Portfolio;
use Illuminate\Http\Request;

class PortfolioController extends Controller
{
    public function index()
    {
        $categories = Category::query()
            ->orderBy('name')
            ->get()
            ->map(fn($c) => [
                'key' => $c->slug,   // ini pengganti key lama
                'name' => $c->name,
            ])
            ->toArray();

        $portfolios = Portfolio::query()
            ->with('category:id,name,slug')
            ->where('is_active', true)
            ->latest()
            ->get()
            ->map(fn($p) => [
                'title' => $p->title,
                'tag' => $p->tag ?? '',
                'category' => $p->category?->slug, // PAKAI SLUG juga
                'cover_image' => $p->cover_image ? asset('storage/' . ltrim($p->cover_image, '/')) : asset('images/placeholder.jpg'),
                'href' => route('portfolio.show', $p->slug),
                'category_name' => $p->category?->name,
            ])
            ->toArray();

        return view('pages.portfolio.index', compact(['portfolios', 'categories']));
    }

    public function show(string $slug)
    {
        $portfolio = Portfolio::query()
            ->with([
                'category:id,name,slug',
                'activeImages:id,portfolio_id,image_path,sort_order,is_active',
            ])
            ->where('slug', $slug)
            ->where('is_active', true)
            ->firstOrFail();

        // siapkan array url screenshot utk Blade
        $screenshots = $portfolio->activeImages
            ->map(fn($img) => asset('storage/' . ltrim($img->image_path, '/')))
            ->values()
            ->toArray();

        return view('pages.portfolio.show.index', compact('portfolio', 'screenshots'));
    }
}
