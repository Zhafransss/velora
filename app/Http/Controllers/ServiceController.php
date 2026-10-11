<?php

namespace App\Http\Controllers;

use App\Models\Category;
use App\Models\Service;
use App\Models\Portfolio;
use Illuminate\Http\Request;

class ServiceController extends Controller
{
    /**
     * Halaman /services
     */
    public function index()
    {
        $services = Service::with([
            'category',
            'cheapestPackage'
        ])
        ->where('is_active', true)
        ->orderBy('sort_order')
        ->get();

        return view('pages.services.index', compact('services'));
    }

    /**
     * Halaman /services/{slug}
     */
    public function show($slug)
    {
        $category = Category::where('slug', $slug)->firstOrFail();

        $service = $category->service()
            ->with([
                'packages.features',
                'serviceFeatures'
            ])
            ->first();

            $portfolios = Portfolio::with('category')
        ->where('category_id', $category->id)
        ->where('is_active', true)
        ->latest()
        ->take(6)
        ->get();

        return view('pages.services.show.index', compact('service', 'category','portfolios'));
    }
}