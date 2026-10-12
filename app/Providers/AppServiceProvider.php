<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;

use Illuminate\Support\Facades\View;
use App\Models\Category;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Scoped: instance baru per request/job agar konteks tenant tidak bocor antar eksekusi.
        $this->app->scoped(\App\Support\Tenancy\CurrentTenant::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
         View::composer('components.partials.navbar', function ($view) {
            $navServices = Category::select('id', 'name', 'slug')
                ->orderBy('name')
                ->get();

            $view->with('services', $navServices);
        });
    }
}
