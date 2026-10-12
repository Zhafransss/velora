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

        $this->app->bind(\App\Services\Whatsapp\WhatsAppChannelAdapter::class, fn () => \App\Services\Whatsapp\BaileysConnectorAdapter::fromConfig());
        $this->app->bind(\App\Services\Whatsapp\WebhookSignature::class, fn () => \App\Services\Whatsapp\WebhookSignature::fromConfig());

        $this->app->bind(\App\Services\Ai\PromptBuilder::class, fn () => \App\Services\Ai\PromptBuilder::fromConfig());

        $this->app->bind(\App\Services\Ai\AiGateway::class, function () {
            $cfg = config('ai');
            $classes = ['gemini' => \App\Services\Ai\GeminiProvider::class, 'groq' => \App\Services\Ai\GroqProvider::class];
            $providers = [];
            $pricing = [];

            foreach ($cfg['order'] as $name) {
                $p = $cfg['providers'][$name] ?? null;

                // Provider tanpa API key dilewati, sehingga satu key cukup untuk mulai.
                if (! isset($classes[$name]) || ! $p || empty($p['api_key'])) {
                    continue;
                }

                $providers[] = new $classes[$name]($p['api_key'], $p['model'], $p['base_url'], $p['timeout'], $p['reasoning'] ?: null);
                $pricing[$name] = [
                    'input' => $p['price_input_per_mtok'] !== null ? (float) $p['price_input_per_mtok'] : null,
                    'output' => $p['price_output_per_mtok'] !== null ? (float) $p['price_output_per_mtok'] : null,
                ];
            }

            return new \App\Services\Ai\AiGateway(
                $providers, $cfg['max_output_tokens'], $cfg['temperature'],
                $cfg['retries'], $cfg['retry_delay_ms'], $cfg['budget_seconds'], $pricing,
            );
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->registerAuthMails();

        // Request AJAX Livewire (/livewire/update) tidak melewati middleware route secara otomatis.
        // Tanpa ini, konteks tenant dan gerbang langganan HILANG pada aksi komponen.
        \Livewire\Livewire::addPersistentMiddleware([
            \Illuminate\Auth\Middleware\Authenticate::class,
            \Illuminate\Auth\Middleware\EnsureEmailIsVerified::class,
            \App\Http\Middleware\SetCurrentTenant::class,
            \App\Http\Middleware\EnsureSubscribed::class,
        ]);

         View::composer('components.partials.navbar', function ($view) {
            $navServices = Category::select('id', 'name', 'slug')
                ->orderBy('name')
                ->get();

            $view->with('services', $navServices);
        });
    }

    /**
     * Email autentikasi dalam Bahasa Indonesia (verifikasi email dan reset password).
     * Pengirim (team@veloradigitalworks.com) diatur lewat MAIL_FROM_ADDRESS di .env, bukan di kode.
     */
    private function registerAuthMails(): void
    {
        \Illuminate\Auth\Notifications\VerifyEmail::toMailUsing(function ($notifiable, string $url) {
            $minutes = (int) config('auth.verification.expire', 60);

            return (new \Illuminate\Notifications\Messages\MailMessage())
                ->subject('Verifikasi email Anda — Velora Digital')
                ->greeting('Halo, ' . $notifiable->name . '!')
                ->line('Terima kasih telah mendaftar di Velora AI. Klik tombol di bawah untuk memverifikasi alamat email Anda.')
                ->action('Verifikasi Email', $url)
                ->line("Tautan ini berlaku selama {$minutes} menit.")
                ->line('Jika Anda tidak merasa mendaftar, abaikan email ini — tidak ada tindakan yang perlu dilakukan.')
                ->salutation("Salam,\nTim Velora Digital");
        });

        \Illuminate\Auth\Notifications\ResetPassword::toMailUsing(function ($notifiable, string $token) {
            $url = url(route('password.reset', ['token' => $token, 'email' => $notifiable->getEmailForPasswordReset()], false));
            $minutes = (int) config('auth.passwords.' . config('auth.defaults.passwords') . '.expire', 60);

            return (new \Illuminate\Notifications\Messages\MailMessage())
                ->subject('Atur ulang password Anda — Velora Digital')
                ->greeting('Halo, ' . $notifiable->name . '!')
                ->line('Kami menerima permintaan untuk mengatur ulang password akun Anda.')
                ->action('Atur Ulang Password', $url)
                ->line("Tautan ini berlaku selama {$minutes} menit.")
                ->line('Jika Anda tidak meminta pengaturan ulang password, abaikan email ini. Password Anda tidak akan berubah.')
                ->salutation("Salam,\nTim Velora Digital");
        });
    }
}
