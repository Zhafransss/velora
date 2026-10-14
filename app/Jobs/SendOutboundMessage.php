<?php

namespace App\Jobs;

use App\Models\Message;
use App\Models\Tenant;
use App\Services\Notifications\TenantNotifier;
use App\Services\Whatsapp\ChannelSendException;
use App\Services\Whatsapp\WhatsAppChannelAdapter;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\RateLimiter;
use Throwable;

/**
 * Mengirim satu balasan yang SUDAH dibuat (FR-011: "AI selesai" terpisah dari "terkirim"). Tidak pernah memanggil AI.
 *
 *  - Hanya membalas pesan masuk (FR-009.7): wajib punya in_reply_to ke pesan masuk di percakapan yang sama.
 *  - Kanal selalu diambil dari percakapan (FR-009.4), penerima = pelanggan percakapan itu.
 *  - Batas laju per kanal; kelebihannya ditunda (dispatch ulang dengan delay), bukan dibuang.
 *  - Klaim atomik pending -> sending mencegah pengiriman ganda; status "sending" yang menggantung (crash saat
 *    mengirim) sengaja TIDAK dikirim ulang karena ambigu.
 *  - Hanya galat yang pasti belum terkirim yang diulang (lihat ChannelSendException).
 */
class SendOutboundMessage implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** Worker antrean ini tertahan selama jeda anti-blokir konektor (±2–6 dtk per pesan); dipisah dari antrean AI. */
    public const QUEUE = 'whatsapp';

    public int $tries = 1;
    public int $timeout = 90;

    public function __construct(public int $tenantId, public int $messageId)
    {
        $this->onQueue(self::QUEUE);
    }

    public function handle(WhatsAppChannelAdapter $adapter, CurrentTenant $context): void
    {
        $tenant = Tenant::find($this->tenantId);

        if ($tenant === null) {
            return;
        }

        $context->runAs($tenant, fn () => $this->send($adapter));
    }

    private function send(WhatsAppChannelAdapter $adapter): void
    {
        $message = Message::with(['conversation.channel', 'inReplyTo'])->find($this->messageId);

        if ($message === null || $message->direction !== Message::OUT || $message->status !== Message::PENDING) {
            return;
        }

        $conversation = $message->conversation;
        $channel = $conversation->channel;

        // FR-009.7: hanya membalas pesan masuk pelanggan yang sama.
        $reply = $message->inReplyTo;
        if ($reply === null || $reply->direction !== Message::IN || $reply->conversation_id !== $conversation->id) {
            $this->markFailed($message, 'not_a_reply');

            return;
        }

        if ($channel->status === $channel::STATUS_REVOKED) {
            $this->markFailed($message, 'channel_revoked');

            return;
        }

        $max = (int) config('whatsapp.send_max_attempts');

        if (! $channel->isConnected()) {
            $this->retryOrFail($message, ChannelSendException::NOT_CONNECTED, $max, 60, countAttempt: true);

            return;
        }

        // Batas laju per kanal (FR-009.7): tunda, jangan buang.
        $rateKey = 'wa-send:' . $channel->id;
        $perMinute = (int) config('whatsapp.max_sends_per_minute');
        if (RateLimiter::tooManyAttempts($rateKey, $perMinute)) {
            self::dispatch($this->tenantId, $this->messageId)->delay(RateLimiter::availableIn($rateKey) + 1);

            return;
        }

        // Klaim atomik: hanya satu pengirim yang boleh memegang pesan ini.
        $claimed = Message::where('id', $message->id)->where('status', Message::PENDING)
            ->update(['status' => Message::SENDING, 'send_attempts' => $message->send_attempts + 1]);

        if ($claimed === 0) {
            return;
        }

        RateLimiter::hit($rateKey, 60);

        try {
            $result = $adapter->send($channel, $conversation->customer_external_id, $message->body);
        } catch (ChannelSendException $e) {
            // send_attempts sudah naik saat klaim; segarkan agar hitungan akurat.
            $message->refresh();

            $e->retryable
                ? $this->retryOrFail($message, $e->type, $max, 30 * max(1, $message->send_attempts))
                : $this->markFailed($message, $e->type);

            return;
        }

        $this->markSent($message, $result->externalMessageId);
    }

    /**
     * Pesan SUDAH terkirim; mencatatnya tidak boleh gagal. Bila id WhatsApp bentrok dengan baris lain (mis. konektor
     * mengembalikan id yang sama), status tetap dicatat tanpa id daripada melempar error dan menandainya gagal.
     */
    private function markSent(Message $message, ?string $externalId): void
    {
        try {
            $message->update(['status' => Message::SENT, 'sent_at' => now(), 'external_message_id' => $externalId, 'error_type' => null]);
        } catch (UniqueConstraintViolationException) {
            // Buang atribut bentrok yang masih "kotor" di model sebelum menyimpan ulang tanpa id.
            $message->refresh();
            $message->update(['status' => Message::SENT, 'sent_at' => now(), 'error_type' => 'external_id_conflict']);
        }
    }

    /**
     * Jadwalkan percobaan berikutnya (pesan kembali "pending") atau nyatakan gagal bila percobaan habis.
     * $countAttempt: true bila percobaan ini belum dihitung (mis. menunggu kanal tersambung).
     */
    private function retryOrFail(Message $message, string $reason, int $max, int $delaySeconds, bool $countAttempt = false): void
    {
        $attempts = $message->send_attempts + ($countAttempt ? 1 : 0);

        if ($attempts >= $max) {
            $this->markFailed($message, $reason);

            return;
        }

        Message::where('id', $message->id)->update(['status' => Message::PENDING, 'send_attempts' => $attempts, 'error_type' => $reason]);

        self::dispatch($this->tenantId, $this->messageId)->delay($delaySeconds);
    }

    private function markFailed(Message $message, string $reason): void
    {
        $message->update(['status' => Message::FAILED, 'error_type' => $reason]);

        // Pemilik perlu tahu bahwa balasan tidak sampai ke pelanggan (DEC-16). 'not_a_reply' adalah cacat internal.
        if ($reason !== 'not_a_reply' && ($tenant = Tenant::find($this->tenantId))) {
            app(TenantNotifier::class)->sendFailed($tenant, $reason);
        }
    }

    public function failed(Throwable $e): void
    {
        $tenant = Tenant::find($this->tenantId);

        $tenant && app(CurrentTenant::class)->runAs($tenant, function () {
            $m = Message::find($this->messageId);

            // "sending" yang menggantung berarti hasil tidak diketahui: tandai gagal, jangan kirim ulang.
            if ($m && in_array($m->status, [Message::PENDING, Message::SENDING], true)) {
                $m->update(['status' => Message::FAILED, 'error_type' => 'exception']);
            }
        });
    }
}
