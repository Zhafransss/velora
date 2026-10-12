<?php

use App\Http\Controllers\Tenant\Auth\EmailVerificationController;
use App\Http\Controllers\Tenant\Auth\LoginController;
use App\Http\Controllers\Tenant\Auth\PasswordResetController;
use App\Http\Controllers\Tenant\Auth\RegisterController;
use App\Livewire\Tenant\BotSettingsForm;
use App\Livewire\Tenant\BusinessProfileForm;
use App\Livewire\Tenant\ChatTester;
use App\Livewire\Tenant\Catalog\FaqsManager;
use App\Livewire\Tenant\Catalog\ProductsManager;
use App\Livewire\Tenant\Catalog\ServicesManager;
use App\Livewire\Tenant\ConversationHistory;
use App\Livewire\Tenant\NotificationsInbox;
use App\Livewire\Tenant\Dashboard;
use App\Livewire\Tenant\WhatsAppConnection;
use Illuminate\Support\Facades\Route;

// Dashboard tenant (DEC-05, DEC-10). Admin platform tetap di /admin (Filament).

Route::middleware('guest')->group(function () {
    Route::get('/register', [RegisterController::class, 'create'])->name('register');
    Route::post('/register', [RegisterController::class, 'store'])->middleware('throttle:10,1');

    Route::get('/login', [LoginController::class, 'create'])->name('login');
    Route::post('/login', [LoginController::class, 'store']);

    Route::get('/forgot-password', [PasswordResetController::class, 'request'])->name('password.request');
    Route::post('/forgot-password', [PasswordResetController::class, 'email'])->middleware('throttle:5,1')->name('password.email');
    Route::get('/reset-password/{token}', [PasswordResetController::class, 'reset'])->name('password.reset');
    Route::post('/reset-password', [PasswordResetController::class, 'update'])->name('password.update');
});

Route::middleware('auth')->group(function () {
    Route::post('/logout', [LoginController::class, 'destroy'])->name('logout');

    Route::get('/email/verify', [EmailVerificationController::class, 'notice'])->name('verification.notice');
    Route::get('/email/verify/{id}/{hash}', [EmailVerificationController::class, 'verify'])
        ->middleware('signed')->name('verification.verify');
    Route::post('/email/verification-notification', [EmailVerificationController::class, 'send'])
        ->middleware('throttle:3,1')->name('verification.send');
});

// Wajib login + email terverifikasi + punya workspace aktif.
Route::middleware(['auth', 'verified', 'tenant'])->prefix('app')->name('app.')->group(function () {
    Route::get('/', Dashboard::class)->name('dashboard');
    Route::view('/subscription', 'tenant.subscription')->name('subscription');
    // Tanpa gerbang langganan: pemilik harus tetap bisa membaca peringatan "langganan berakhir" (DEC-16).
    Route::get('/notifications', NotificationsInbox::class)->name('notifications');

    // Fitur di bawah ini terkunci sampai workspace punya langganan aktif.
    Route::middleware('subscribed')->group(function () {
        Route::get('/business-profile', BusinessProfileForm::class)->name('business-profile');

        // Konfigurasi AI (FR-006)
        Route::get('/bot', BotSettingsForm::class)->name('bot');

        // Koneksi WhatsApp dan riwayat percakapan (FR-009.2, FR-013)
        Route::get('/whatsapp', WhatsAppConnection::class)->name('whatsapp');
        Route::get('/conversations', ConversationHistory::class)->name('conversations');

        // Tes chatbot (FR-008)
        Route::get('/chat-test', ChatTester::class)->name('chat-test');

        // Knowledge base (FR-005)
        Route::get('/products', ProductsManager::class)->name('products');
        Route::get('/services', ServicesManager::class)->name('services');
        Route::get('/faqs', FaqsManager::class)->name('faqs');
    });
});
