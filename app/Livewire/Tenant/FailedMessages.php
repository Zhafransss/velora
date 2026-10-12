<?php

namespace App\Livewire\Tenant;

use App\Jobs\ProcessInboundMessage;
use App\Jobs\SendOutboundMessage;
use App\Models\Message;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Component;

/**
 * Pesan pelanggan yang tidak sampai terjawab karena kegagalan teknis (FR-014/FR-015), dengan kirim ulang oleh PEMILIK
 * workspace. Sengaja tidak ada versi admin platform: admin tidak berhak mengirim pesan WhatsApp atas nama tenant.
 *
 * Aturan keamanan kirim ulang:
 *  - hanya owner/admin workspace (viewer 403); id milik tenant lain = 404 (BelongsToTenant);
 *  - hanya pesan gagal yang masih dalam jendela retry_window_hours (membalas pertanyaan kemarin tidak berguna);
 *  - peralihan status atomik (failed -> pending/received) sehingga klik ganda tidak mengirim dua kali;
 *  - pengiriman "tidak terkonfirmasi" mungkin SUDAH sampai: wajib konfirmasi pemilik (risiko terkirim ganda);
 *  - pesan masuk yang sudah punya balasan (mis. pesan cadangan terkirim) tidak diproses ulang.
 */
class FailedMessages extends Component
{
    private const LIMIT = 50;

    /** Alasan yang tidak dapat diperbaiki dengan mengirim ulang. */
    private const NOT_RETRYABLE = ['channel_revoked', 'not_a_reply'];

    public ?string $notice = null;

    public ?string $noticeType = null;

    /** Pesan gagal yang masih relevan untuk ditindaklanjuti (dipakai juga oleh lencana navigasi). */
    public static function recentFailedQuery(): Builder
    {
        $since = now()->subHours(max(1, (int) config('whatsapp.retry_window_hours')));

        return Message::query()
            ->where('status', Message::FAILED)->where('is_test', false)->where('created_at', '>=', $since)
            ->where(fn (Builder $q) => $q
                ->where(fn (Builder $o) => $o->where('direction', Message::OUT)
                    ->where(fn (Builder $e) => $e->whereNull('error_type')->orWhereNotIn('error_type', self::NOT_RETRYABLE)))
                // Pesan masuk yang sudah punya balasan apa pun (termasuk pesan cadangan) bukan lagi "tidak terjawab".
                ->orWhere(fn (Builder $i) => $i->where('direction', Message::IN)
                    ->whereNotExists(fn ($r) => $r->selectRaw('1')->from('messages as replies')
                        ->whereColumn('replies.in_reply_to_id', 'messages.id'))));
    }

    public function canManage(): bool
    {
        return app(CurrentTenant::class)->userCanWrite(auth()->user());
    }

    public function retry(int $id): void
    {
        abort_unless($this->canManage(), 403);

        $message = static::recentFailedQuery()->with('conversation.channel')->findOrFail($id);
        $channel = $message->conversation->channel;

        if ($channel->status === $channel::STATUS_REVOKED || ! $channel->isConnected()) {
            $this->flash('error', 'WhatsApp belum terhubung. Hubungkan dulu di menu WhatsApp, lalu coba kirim ulang.');

            return;
        }

        $tenantId = $message->tenant_id;

        if ($message->direction === Message::OUT) {
            // Atomik: klik ganda atau dua tab tidak menghasilkan dua pengiriman.
            $moved = Message::where('id', $message->id)->where('status', Message::FAILED)
                ->update(['status' => Message::PENDING, 'send_attempts' => 0, 'error_type' => null]);

            $moved === 1 && SendOutboundMessage::dispatch($tenantId, $message->id);
        } else {
            $moved = Message::where('id', $message->id)->where('status', Message::FAILED)
                ->update(['status' => Message::RECEIVED, 'error_type' => null, 'process_attempts' => $message->process_attempts + 1]);

            $moved === 1 && ProcessInboundMessage::dispatch($tenantId, $message->id);
        }

        $moved === 1
            ? $this->flash('ok', 'Dikirim ulang. Hasilnya akan terlihat beberapa saat lagi di menu Percakapan.')
            : $this->flash('error', 'Pesan ini sudah ditangani.');
    }

    /** Apakah pengiriman ulang berisiko terkirim ganda (hasil sebelumnya tidak diketahui). */
    public static function isAmbiguous(Message $m): bool
    {
        return $m->direction === Message::OUT && $m->error_type === 'send_timeout_unknown';
    }

    /** Penjelasan bagi pemilik bisnis, bukan istilah teknis. */
    public static function reason(Message $m): string
    {
        if ($m->direction === Message::IN) {
            return match ($m->error_type) {
                'stale_expired' => 'Pesan pelanggan terlalu lama menunggu diproses',
                'stuck_processing' => 'Proses jawaban terhenti di tengah jalan',
                default => 'AI belum berhasil menjawab pertanyaan ini',
            };
        }

        return match ($m->error_type) {
            'channel_not_connected' => 'WhatsApp sedang tidak terhubung saat balasan dikirim',
            'connector_unreachable' => 'Layanan WhatsApp tidak dapat dihubungi',
            'send_timeout_unknown' => 'Pengiriman tidak terkonfirmasi (mungkin sudah sampai ke pelanggan)',
            'stale_expired' => 'Balasan terlalu lama menunggu dikirim',
            default => 'Terjadi kendala saat mengirim balasan',
        };
    }

    public function render()
    {
        $messages = static::recentFailedQuery()->with(['conversation', 'inReplyTo'])
            ->orderByDesc('id')->limit(self::LIMIT)->get();

        return view('livewire.tenant.failed-messages', [
            'messages' => $messages,
            'canManage' => $this->canManage(),
            'windowHours' => (int) config('whatsapp.retry_window_hours'),
        ])->layout('tenant.layouts.app', ['title' => 'Pesan Gagal']);
    }

    private function flash(string $type, string $text): void
    {
        $this->noticeType = $type;
        $this->notice = $text;
    }
}
