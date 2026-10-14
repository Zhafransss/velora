<?php

namespace App\Livewire\Tenant;

use App\Models\AiTestMessage;
use App\Models\Bot;
use App\Models\CatalogService;
use App\Models\Channel;
use App\Models\Conversation;
use App\Models\Faq;
use App\Models\Message;
use App\Models\Product;
use App\Models\TenantNotification;
use App\Services\Usage\QuotaManager;
use App\Support\Tenancy\CurrentTenant;
use Livewire\Component;

/**
 * Beranda dashboard tenant (FR-013): ringkasan hari ini, langkah menyiapkan chatbot, dan percakapan terbaru.
 * Semua model memakai TenantScope, jadi hitungan otomatis terbatas pada workspace aktif.
 */
class Dashboard extends Component
{
    public function render(QuotaManager $quota)
    {
        $tenant = app(CurrentTenant::class)->get();
        $subscription = $tenant->currentSubscription;
        $isSubscribed = $tenant->hasActiveSubscription();
        $startOfToday = now($tenant->timezone ?: 'Asia/Jakarta')->startOfDay();

        $channel = Channel::query()->orderByRaw("case status when 'connected' then 0 else 1 end")->orderBy('id')->first();
        $bot = Bot::query()->orderBy('id')->first();
        $products = Product::where('is_active', true)->count();
        $services = CatalogService::where('is_active', true)->count();
        $faqs = Faq::where('is_active', true)->count();

        // Urutan = urutan kerja yang disarankan; tiap langkah menautkan halaman tempat menyelesaikannya.
        $steps = [
            ['label' => 'Lengkapi profil bisnis', 'hint' => 'Jenis usaha, deskripsi, jam buka', 'route' => 'app.business-profile',
                'done' => filled($tenant->businessProfile?->description)],
            ['label' => 'Tambahkan produk atau layanan', 'hint' => "{$products} produk · {$services} layanan", 'route' => 'app.products',
                'done' => $products + $services > 0],
            ['label' => 'Tulis FAQ', 'hint' => "{$faqs} pertanyaan", 'route' => 'app.faqs', 'done' => $faqs > 0],
            ['label' => 'Aktifkan bot', 'hint' => 'Persona dan gaya bahasa', 'route' => 'app.bot',
                'done' => $bot?->status === Bot::STATUS_ACTIVE],
            ['label' => 'Coba di Tes Chatbot', 'hint' => 'Pastikan jawabannya sesuai', 'route' => 'app.chat-test',
                'done' => AiTestMessage::query()->exists()],
            ['label' => 'Hubungkan WhatsApp', 'hint' => 'Scan QR dari HP bisnis Anda', 'route' => 'app.whatsapp',
                'done' => $channel?->isConnected() ?? false],
        ];

        $limit = $quota->limitFor($tenant);
        $used = $quota->used($tenant);

        return view('livewire.tenant.dashboard', [
            'tenant' => $tenant,
            'subscription' => $subscription,
            'isSubscribed' => $isSubscribed,
            'daysLeft' => $isSubscribed && $subscription?->current_period_end
                ? (int) ceil(now()->diffInSeconds($subscription->current_period_end, false) / 86400) : null,
            'used' => $used,
            'limit' => $limit,
            'usedPercent' => $limit ? min(100, (int) round($used / max(1, $limit) * 100)) : 0,
            'conversationsToday' => Conversation::where('last_message_at', '>=', $startOfToday)->count(),
            'channel' => $channel,
            'failedCount' => FailedMessages::recentFailedQuery()->count(),
            'steps' => $steps,
            'stepsDone' => collect($steps)->where('done', true)->count(),
            'recent' => Conversation::query()
                ->whereNotNull('last_message_at')
                ->addSelect(['last_body' => Message::select('body')
                    ->whereColumn('messages.conversation_id', 'conversations.id')
                    ->latest('id')->limit(1)])
                ->latest('last_message_at')->limit(5)->get(),
            // Peringatan belum dibaca yang paling penting, ditampilkan di atas dashboard (DEC-16).
            'alerts' => TenantNotification::whereNull('read_at')
                ->whereIn('severity', ['critical', 'warning'])
                ->orderByRaw("case severity when 'critical' then 0 else 1 end")->orderByDesc('last_occurred_at')->limit(3)->get(),
        ])->layout('tenant.layouts.app', ['title' => 'Dashboard']);
    }
}
