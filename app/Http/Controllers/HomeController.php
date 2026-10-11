<?php

namespace App\Http\Controllers;

use App\Models\Portfolio;
use App\Models\Service;
use Illuminate\Http\Request;

class HomeController extends Controller
{
    public function index()
    {
        $portfolios = Portfolio::query()
            ->with('category:id,name,slug')
            ->where('is_active', true)
            ->latest()
            ->take(6)
            ->get();

             $services = Service::query()
            ->with([
                'category:id,name,slug',
                'cheapestPackage.features',
            ])
            ->where('is_popular', true)
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->take(4)
            ->get();
        return view('pages.home.index', compact('portfolios','services'));
    }
}
