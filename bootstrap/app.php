<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'tenant' => \App\Http\Middleware\SetCurrentTenant::class,
            'subscribed' => \App\Http\Middleware\EnsureSubscribed::class,
            'active' => \App\Http\Middleware\EnsureAccountActive::class,
        ]);

        $middleware->redirectUsersTo('/app');

        // Global (bukan grup web) agar panel Filament dan respons Livewire ikut mendapat header keamanan.
        $middleware->append(\App\Http\Middleware\HardenResponses::class);

        // Webhook konektor memakai tanda tangan HMAC, bukan token CSRF.
        $middleware->validateCsrfTokens(except: ['webhooks/*']);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
