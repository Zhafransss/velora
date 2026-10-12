<?php

namespace App\Services\Notifications;

use App\Models\Channel;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\TenantNotification;
use App\Services\Plans\Entitlements;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Membuat notifikasi dashboard untuk pemilik workspace (DEC-16): bot gagal menjawab, kuota habis, kanal terputus,
 * bot belum aktif, langganan bermasalah/berakhir. Teks dipusatkan di sini.
 *
 * - Kejadian berulang pada jendela yang sama (dedupe_key) menaikkan `occurrences`, bukan membuat baris baru.
 * - Tidak pernah memuat isi pesan pelanggan.
 * - Tidak pernah melempar galat: kegagalan membuat notifikasi tidak boleh mengganggu pemrosesan pesan.
 */
class TenantNotifier
{
    public function quotaExceeded(Tenant $tenant): void
    {
        $e = Entitlements::forTenant($tenant);
        $limit = $e->dailyAiMessages === null ? 'tanpa batas keras' : number_format($e->dailyAiMessages, 0, ',', '.') . ' pesan per hari';

        $this->safely(fn () => $this->notify(
            $tenant, 'quota_exceeded', TenantNotification::CRITICAL, 'Kuota AI harian habis',
            "Pesan pelanggan tidak dibalas sampai kuota diperbarui besok. Paket {$e->label}: {$limit}. Untuk kuota lebih besar, tingkatkan paket Anda.",
            'app.subscription', 'quota_exceeded:' . $this->day($tenant),
        ));
    }

    public function aiFailed(Tenant $tenant): void
    {
        $this->safely(fn () => $this->notify(
            $tenant, 'ai_failed', TenantNotification::WARNING, 'Chatbot gagal menjawab beberapa pesan',
            'Layanan AI sedang bermasalah. Pelanggan menerima pesan cadangan dari bot Anda. Pesan yang terdampak dapat dilihat di menu Percakapan.',
            'app.conversations', 'ai_failed:' . $this->hour($tenant),
        ));
    }

    public function botInactive(Tenant $tenant): void
    {
        $this->safely(fn () => $this->notify(
            $tenant, 'bot_inactive', TenantNotification::WARNING, 'Pelanggan mengirim pesan, tetapi bot belum aktif',
            'Bot Anda belum berstatus Aktif sehingga pesan pelanggan tidak dibalas. Ubah status bot menjadi Aktif di menu Bot.',
            'app.bot', 'bot_inactive:' . $this->day($tenant),
        ));
    }

    public function subscriptionInactive(Tenant $tenant): void
    {
        $this->safely(fn () => $this->notify(
            $tenant, 'subscription_inactive', TenantNotification::CRITICAL, 'Pesan pelanggan tidak dibalas: langganan tidak aktif',
            'Langganan workspace ini tidak aktif sehingga chatbot berhenti membalas. Hubungi tim Velora Digital untuk mengaktifkan kembali.',
            'app.subscription', 'subscription_inactive:' . $this->day($tenant),
        ));
    }

    public function sendFailed(Tenant $tenant, ?string $reason): void
    {
        $why = match ($reason) {
            'channel_not_connected' => 'WhatsApp sedang tidak terhubung',
            'connector_unreachable' => 'layanan WhatsApp tidak dapat dihubungi',
            'send_timeout_unknown' => 'pengiriman tidak terkonfirmasi (waktu habis)',
            'channel_revoked' => 'kanal telah dicabut',
            default => 'terjadi kendala pengiriman',
        };

        $this->safely(fn () => $this->notify(
            $tenant, 'send_failed', TenantNotification::WARNING, 'Balasan gagal terkirim ke pelanggan',
            "Alasan terakhir: {$why}. Periksa koneksi di menu WhatsApp.",
            'app.failed-messages', 'send_failed:' . $this->hour($tenant),
        ));
    }

    public function channelDown(Tenant $tenant, Channel $channel, string $state): void
    {
        $body = $state === 'logged_out'
            ? 'Perangkat tertaut terlepas dari WhatsApp (mis. dilepas dari ponsel). Buka menu WhatsApp lalu klik Hubungkan untuk memindai ulang.'
            : 'Koneksi WhatsApp gagal dipulihkan setelah beberapa percobaan. Buka menu WhatsApp lalu klik Hubungkan.';

        $this->safely(fn () => $this->notify(
            $tenant, 'channel_down', TenantNotification::CRITICAL, 'WhatsApp terputus', $body,
            'app.whatsapp', "channel_down:{$channel->id}:{$state}:" . $this->hour($tenant),
        ));
    }

    /** @param int $bucket 7, 3, atau 1 hari sebelum berakhir — satu notifikasi per ambang, bukan tiap hari */
    public function subscriptionExpiring(Tenant $tenant, Subscription $subscription, int $daysLeft, int $bucket): void
    {
        $when = $daysLeft <= 0 ? 'hari ini' : "dalam {$daysLeft} hari";

        $this->safely(fn () => $this->notify(
            $tenant, 'subscription_expiring', $bucket <= 1 ? TenantNotification::CRITICAL : TenantNotification::WARNING,
            "Langganan berakhir {$when}",
            'Paket ' . strtoupper($subscription->plan_code) . ' berakhir pada ' . $subscription->current_period_end->translatedFormat('d F Y') . '. Hubungi tim Velora Digital untuk perpanjangan agar chatbot tidak berhenti.',
            'app.subscription', "sub_expiring:{$subscription->id}:{$bucket}", increment: false,
        ));
    }

    public function subscriptionExpired(Tenant $tenant, Subscription $subscription): void
    {
        $this->safely(fn () => $this->notify(
            $tenant, 'subscription_expired', TenantNotification::CRITICAL, 'Langganan telah berakhir',
            'Paket ' . strtoupper($subscription->plan_code) . ' berakhir pada ' . $subscription->current_period_end->translatedFormat('d F Y') . '. Fitur terkunci dan chatbot berhenti membalas sampai langganan diperpanjang.',
            'app.subscription', "sub_expired:{$subscription->id}", increment: false,
        ));
    }

    /**
     * @return bool true bila notifikasi BARU dibuat; false bila kejadian yang sama sudah ada (hitungan dinaikkan bila $increment)
     */
    public function notify(Tenant $tenant, string $type, string $severity, string $title, ?string $body, ?string $actionRoute, ?string $dedupeKey = null, bool $increment = true): bool
    {
        $row = [
            'tenant_id' => $tenant->id, 'type' => $type, 'severity' => $severity, 'title' => $title, 'body' => $body,
            'action_route' => $actionRoute, 'dedupe_key' => $dedupeKey, 'occurrences' => 1, 'last_occurred_at' => now(), 'created_at' => now(),
        ];

        if ($dedupeKey === null) {
            DB::table('tenant_notifications')->insert($row);

            return true;
        }

        if (DB::table('tenant_notifications')->insertOrIgnore($row) === 1) {
            return true;
        }

        // $increment = false untuk pengingat terjadwal (idempoten): menjalankannya ulang bukan "kejadian baru".
        if ($increment) {
            DB::table('tenant_notifications')->where('tenant_id', $tenant->id)->where('dedupe_key', $dedupeKey)
                ->update(['occurrences' => DB::raw('occurrences + 1'), 'last_occurred_at' => now()]);
        }

        return false;
    }

    private function safely(callable $fn): void
    {
        try {
            $fn();
        } catch (\Throwable $e) {
            Log::warning('tenant_notification.failed', ['error' => $e::class]);
        }
    }

    private function day(Tenant $tenant): string
    {
        return now()->setTimezone($tenant->timezone ?: 'Asia/Jakarta')->format('Y-m-d');
    }

    private function hour(Tenant $tenant): string
    {
        return now()->setTimezone($tenant->timezone ?: 'Asia/Jakarta')->format('Y-m-d H');
    }
}
