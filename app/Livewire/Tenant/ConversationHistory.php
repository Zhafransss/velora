<?php

namespace App\Livewire\Tenant;

use App\Models\Conversation;
use App\Models\Message;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * FR-013: riwayat percakapan (baca saja). Semua query lewat model ber-BelongsToTenant sehingga id percakapan
 * milik tenant lain (mis. query string dimanipulasi) menghasilkan 404, bukan akses lintas tenant.
 * Daftar dibatasi (data UMKM kecil); retensi data belum diputuskan (OQ-05).
 */
class ConversationHistory extends Component
{
    private const LIST_LIMIT = 50;
    private const MESSAGE_LIMIT = 200;

    #[Url(as: 'c')]
    public ?int $selected = null;

    public function select(int $id): void
    {
        $this->selected = Conversation::findOrFail($id)->id;
    }

    public function render()
    {
        $conversations = Conversation::with('channel')->orderByDesc('last_message_at')->orderByDesc('id')->limit(self::LIST_LIMIT)->get();

        $current = null;
        $messages = collect();

        if ($this->selected !== null) {
            $current = Conversation::findOrFail($this->selected);
            $messages = $current->messages()->orderByDesc('id')->limit(self::MESSAGE_LIMIT)->get()->reverse()->values();
        }

        return view('livewire.tenant.conversation-history', [
            'conversations' => $conversations,
            'current' => $current,
            'messages' => $messages,
        ])->layout('tenant.layouts.app', ['title' => 'Percakapan']);
    }

    public static function customerLabel(Conversation $c): string
    {
        $number = \Illuminate\Support\Str::before($c->customer_alt_id ?: $c->customer_external_id, '@');

        return $c->customer_name ? "{$c->customer_name} ({$number})" : $number;
    }

    /** Penjelasan status untuk pemilik bisnis (bukan istilah teknis). */
    public static function statusLabel(Message $m): string
    {
        if ($m->direction === Message::IN) {
            return match ($m->status) {
                Message::RECEIVED => 'Diterima, menunggu diproses',
                Message::PROCESSING => 'Sedang diproses',
                Message::ANSWERED => 'Sudah dijawab',
                Message::FAILED => 'AI gagal menjawab (pelanggan menerima pesan cadangan)',
                Message::QUOTA_EXCEEDED => 'Tidak dijawab: kuota AI harian habis',
                Message::SKIPPED => 'Tidak dijawab: ' . match ($m->error_type) {
                    'bot_not_active' => 'bot belum aktif',
                    'no_subscription' => 'langganan tidak aktif',
                    'tenant_inactive' => 'workspace dinonaktifkan',
                    'channel_revoked' => 'kanal dicabut',
                    'empty_message' => 'pesan kosong',
                    default => 'dilewati',
                },
                default => $m->status,
            };
        }

        return match ($m->status) {
            Message::PENDING => 'Menunggu dikirim',
            Message::SENDING => 'Sedang dikirim',
            Message::SENT => 'Terkirim',
            Message::FAILED => 'Gagal dikirim (' . ($m->error_type ?? 'tidak diketahui') . ')',
            default => $m->status,
        };
    }
}
