<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Retensi percakapan (DEC-15). Produksi membutuhkan cron: * * * * * php artisan schedule:run  (lokal: php artisan schedule:work)
\Illuminate\Support\Facades\Schedule::command('conversations:prune')->dailyAt('02:30')->withoutOverlapping();
\Illuminate\Support\Facades\Schedule::command('notifications:subscription-expiry')->dailyAt('08:00')->withoutOverlapping();
\Illuminate\Support\Facades\Schedule::command('whatsapp:prune-auth')->weeklyOn(0, '03:15')->withoutOverlapping();
