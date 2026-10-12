<?php

namespace App\Console\Commands;

use App\Jobs\ProcessInboundMessage;
use App\Jobs\SendOutboundMessage;
use App\Models\Message;
use App\Models\Tenant;
use App\Services\Notifications\TenantNotifier;
use App\Services\Usage\QuotaManager;
use App\Models\Channel;
use App\Models\Conversation;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Penyapu pesan menggantung (FR-015/NFR-002). Pesan bisa macet bila worker mati, job hilang, atau proses terbunuh.
 * Perlakuan tiap keadaan dibedakan menurut RISIKO, bukan disamaratakan:
 *
 *  - inbound `received` / outbound `pending` (belum diambil worker): AMAN diantrekan ulang — klaim job bersifat atomik.
 *    Yang sudah lewat expire_after dinyatakan gagal (membalas berjam-jam kemudian lebih merugikan).
 *  - inbound `processing` (macet di tengah AI): ditandai gagal dan unit kuota dikembalikan; TIDAK diulang karena AI
 *    mungkin sudah dipanggil (biaya) dan hasilnya tidak diketahui.
 *  - outbound `sending` (macet saat mengirim): ditandai gagal "tidak terkonfirmasi"; TIDAK dikirim ulang karena pesan
 *    bisa saja sudah sampai ke pelanggan (kirim ganda lebih buruk daripada tidak terkirim).
 *
 * Umur diukur dari updated_at (klaim dan antre-ulang menyentuhnya), sehingga pesan yang baru diantrekan ulang tidak
 * diantrekan lagi pada sapuan berikutnya sebelum waktunya.
 */
class SweepStuckMessages extends Command
{
    protected $signature = 'messages:sweep-stuck {--dry-run : Hanya hitung}';

    protected $description = 'Antre ulang atau tandai gagal pesan yang menggantung (received/processing/pending/sending).';

    public function handle(QuotaManager $quota, TenantNotifier $notifier): int
    {
        $cfg = config('whatsapp.sweep');
        $dry = (bool) $this->option('dry-run');
        $requeueBefore = now()->subMinutes(max(1, $cfg['requeue_after_minutes']));
        $stuckBefore = now()->subMinutes(max(1, $cfg['stuck_after_minutes']));
        $expireBefore = now()->subMinutes(max(1, $cfg['expire_after_minutes']));
        $batch = (int) $cfg['batch'];

        $counts = ['requeued_inbound' => 0, 'requeued_outbound' => 0, 'expired' => 0, 'stuck_processing' => 0, 'stuck_sending' => 0];
        $notify = []; // tenantId => alasan terakhir

        $query = fn (string $direction, string $status, $before) => Message::withoutTenancy()
            ->where('direction', $direction)->where('status', $status)->where('updated_at', '<', $before)
            ->orderBy('id')->limit($batch)->get();

        foreach ($query(Message::IN, Message::RECEIVED, $requeueBefore) as $m) {
            if ($m->created_at < $expireBefore) {
                $counts['expired']++;
                $dry || $this->markFailed($m, 'stale_expired');
                $notify[$m->tenant_id] = 'stale_expired';
            } else {
                $counts['requeued_inbound']++;
                $dry || ($m->touch() && ProcessInboundMessage::dispatch($m->tenant_id, $m->id));
            }
        }

        foreach ($query(Message::OUT, Message::PENDING, $requeueBefore) as $m) {
            if ($m->created_at < $expireBefore) {
                $counts['expired']++;
                $dry || $this->markFailed($m, 'stale_expired');
                $notify[$m->tenant_id] = 'stale_expired';
            } else {
                $counts['requeued_outbound']++;
                $dry || ($m->touch() && SendOutboundMessage::dispatch($m->tenant_id, $m->id));
            }
        }

        foreach ($query(Message::IN, Message::PROCESSING, $stuckBefore) as $m) {
            $counts['stuck_processing']++;
            $notify[$m->tenant_id] = 'stuck_processing';

            if (! $dry && $this->markFailed($m, 'stuck_processing', Message::PROCESSING)) {
                $this->releaseQuota($m, $quota);
            }
        }

        foreach ($query(Message::OUT, Message::SENDING, $stuckBefore) as $m) {
            $counts['stuck_sending']++;
            $notify[$m->tenant_id] = 'send_timeout_unknown';
            $dry || $this->markFailed($m, 'send_timeout_unknown', Message::SENDING);
        }

        if (! $dry) {
            foreach ($notify as $tenantId => $reason) {
                if ($tenant = Tenant::find($tenantId)) {
                    $notifier->sendFailed($tenant, $reason);
                }
            }
        }

        $label = $dry ? 'akan ditangani' : 'ditangani';
        $this->info(sprintf(
            'Diantre ulang: %d masuk, %d keluar. Kedaluwarsa: %d. Macet (ditandai gagal): %d diproses, %d dikirim — %s.',
            $counts['requeued_inbound'], $counts['requeued_outbound'], $counts['expired'], $counts['stuck_processing'], $counts['stuck_sending'], $label,
        ));

        if (! $dry && array_sum($counts) > 0) {
            Log::warning('messages.swept', $counts); // hanya angka agregat, tanpa isi pesan
        }

        return self::SUCCESS;
    }

    /** Tandai gagal hanya bila status masih seperti yang dibaca (job asli mungkin baru saja selesai). */
    private function markFailed(Message $m, string $reason, ?string $expectedStatus = null): bool
    {
        return Message::withoutTenancy()->where('id', $m->id)->where('status', $expectedStatus ?? $m->status)
            ->update(['status' => Message::FAILED, 'error_type' => $reason]) === 1;
    }

    private function releaseQuota(Message $m, QuotaManager $quota): void
    {
        $tenant = Tenant::find($m->tenant_id);
        $conversation = Conversation::withoutTenancy()->find($m->conversation_id);
        $channel = $conversation ? Channel::withoutTenancy()->find($conversation->channel_id) : null;

        if ($tenant && $channel) {
            $quota->release($tenant, $m->quotaKey($channel->public_id), $m->id);
        }
    }
}
