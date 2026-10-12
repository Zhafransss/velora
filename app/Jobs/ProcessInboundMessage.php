<?php

namespace App\Jobs;

use App\Models\Message;
use App\Models\Tenant;
use App\Services\Ai\AiGateway;
use App\Services\Ai\AiGatewayException;
use App\Services\Ai\PromptBuilder;
use App\Services\Usage\QuotaManager;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Throwable;

/**
 * Memproses satu pesan masuk: gerbang -> kuota -> prompt -> AI -> simpan jawaban -> antre pengiriman.
 *
 * Aman diulang (idempoten): status pesan dipindah dengan UPDATE bersyarat sehingga hanya satu eksekusi yang
 * memproses; kuota memakai kunci per pesan. Job membawa id tenant secara eksplisit (konteks tenant tidak
 * terbawa otomatis ke antrean). AI TIDAK dipanggil ulang saat pengiriman gagal (lihat SendOutboundMessage).
 */
class ProcessInboundMessage implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;
    public int $timeout = 120;

    public function __construct(public int $tenantId, public int $messageId)
    {
    }

    public function handle(QuotaManager $quota, PromptBuilder $builder, AiGateway $gateway, CurrentTenant $context): void
    {
        $tenant = Tenant::find($this->tenantId);

        if ($tenant === null) {
            return;
        }

        $outbound = $context->runAs($tenant, fn () => $this->process($tenant, $quota, $builder, $gateway));

        if ($outbound !== null) {
            SendOutboundMessage::dispatch($this->tenantId, $outbound);
        }
    }

    /** @return int|null id pesan keluar yang perlu dikirim */
    private function process(Tenant $tenant, QuotaManager $quota, PromptBuilder $builder, AiGateway $gateway): ?int
    {
        // Klaim atomik: hanya satu eksekusi yang berhasil memindahkan received -> processing.
        $claimed = Message::where('id', $this->messageId)
            ->where('direction', Message::IN)->where('status', Message::RECEIVED)
            ->update(['status' => Message::PROCESSING]);

        if ($claimed === 0) {
            return null;
        }

        $message = Message::with(['conversation.channel', 'conversation.bot'])->findOrFail($this->messageId);
        $channel = $message->conversation->channel;
        $bot = $message->conversation->bot;

        if ($reason = $this->skipReason($tenant, $channel, $bot)) {
            $message->update(['status' => Message::SKIPPED, 'error_type' => $reason]);

            return null;
        }

        $key = "msg:{$channel->public_id}:{$message->external_message_id}";
        $decision = $quota->reserve($tenant, $key, 'chat', $message->id);

        if ($decision->exceeded()) {
            // Tidak ada pemanggilan AI. Pemilik dapat dinotifikasi kelak (OQ-06).
            $message->update(['status' => Message::QUOTA_EXCEEDED, 'error_type' => 'daily_quota']);

            return null;
        }

        try {
            $built = $builder->build($bot, $message->body, $this->history($message));
            $answer = $gateway->generate($built->request, 'chat');
        } catch (AiGatewayException $e) {
            // Pelanggan tidak mendapat nilai: unit dikembalikan, dan pelanggan menerima pesan fallback bot.
            $quota->release($tenant, $key, $message->id);

            return $this->finish($message, Message::FAILED, $e->lastType() ?? 'ai_failed', $bot->fallback_message, Message::SOURCE_FALLBACK);
        } catch (InvalidArgumentException) {
            $quota->release($tenant, $key, $message->id);
            $message->update(['status' => Message::SKIPPED, 'error_type' => 'empty_message']);

            return null;
        }

        return $this->finish($message, Message::ANSWERED, null, $answer->text, Message::SOURCE_AI, $answer);
    }

    private function skipReason(Tenant $tenant, $channel, $bot): ?string
    {
        return match (true) {
            $tenant->status !== Tenant::STATUS_ACTIVE => 'tenant_inactive',
            $channel->status === $channel::STATUS_REVOKED => 'channel_revoked',
            $bot->status !== $bot::STATUS_ACTIVE => 'bot_not_active',
            ! $tenant->hasActiveSubscription() => 'no_subscription',
            default => null,
        };
    }

    /** Jawaban + status pesan masuk disimpan bersamaan; "AI selesai" (pending) belum berarti "terkirim". */
    private function finish(Message $inbound, string $inboundStatus, ?string $error, string $text, string $source, $answer = null): int
    {
        return DB::transaction(function () use ($inbound, $inboundStatus, $error, $text, $source, $answer) {
            $outbound = Message::create([
                'conversation_id' => $inbound->conversation_id,
                'direction' => Message::OUT,
                'body' => $text,
                'status' => Message::PENDING,
                'source' => $source,
                'in_reply_to_id' => $inbound->id,
                'provider' => $answer?->provider,
                'model' => $answer?->model,
                'input_tokens' => $answer?->inputTokens,
                'output_tokens' => $answer?->outputTokens,
            ]);

            $inbound->update(['status' => $inboundStatus, 'error_type' => $error]);

            return $outbound->id;
        });
    }

    /** @return list<array{role: string, content: string}> */
    private function history(Message $current): array
    {
        $limit = (int) config('whatsapp.history_messages');

        return Message::where('conversation_id', $current->conversation_id)
            ->where('id', '<', $current->id)
            ->where(fn ($q) => $q->where('direction', Message::IN)->orWhere(fn ($o) => $o->where('direction', Message::OUT)->where('status', '!=', Message::FAILED)))
            ->orderByDesc('id')->limit($limit)->get()->reverse()
            ->map(fn (Message $m) => ['role' => $m->direction === Message::IN ? 'user' : 'assistant', 'content' => $m->body])
            ->values()->all();
    }

    /** Kegagalan tak terduga: jangan biarkan pesan menggantung di "processing" dan kembalikan unit kuota. */
    public function failed(Throwable $e): void
    {
        $tenant = Tenant::find($this->tenantId);

        if ($tenant === null) {
            return;
        }

        app(CurrentTenant::class)->runAs($tenant, function () use ($tenant) {
            $message = Message::with('conversation.channel')->find($this->messageId);

            if ($message && $message->direction === Message::IN && $message->status === Message::PROCESSING) {
                app(QuotaManager::class)->release($tenant, "msg:{$message->conversation->channel->public_id}:{$message->external_message_id}", $message->id);
                $message->update(['status' => Message::FAILED, 'error_type' => 'exception']);
            }
        });
    }
}
