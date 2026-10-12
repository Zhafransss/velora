<?php

namespace App\Livewire\Tenant;

use App\Models\Bot;
use App\Models\Channel;
use App\Services\Whatsapp\ChannelSendException;
use App\Services\Whatsapp\QrRenderer;
use App\Services\Whatsapp\WhatsAppChannelAdapter;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Support\Facades\Log;
use Livewire\Component;

/**
 * FR-009.2 / FR-013: tenant membuat kanal WhatsApp dan memindai QR dari dashboard.
 *
 * - Hanya owner/admin yang boleh membuat/menghubungkan/memutus dan melihat QR; viewer hanya melihat status.
 * - QR diambil segar dari konektor pada setiap penyegaran, tidak disimpan di database dan tidak dicatat ke log.
 * - Kanal selalu dicari lewat model ber-BelongsToTenant (MVP: satu kanal per workspace).
 * - Status disinkronkan dari konektor saat halaman terbuka (cadangan bila webhook status belum sampai).
 */
class WhatsAppConnection extends Component
{
    /** Status yang masih berubah sehingga perlu penyegaran cepat. */
    private const TRANSIENT = ['created', 'starting', 'qr', 'reconnecting', 'qr_expired'];

    public string $name = 'WhatsApp Utama';

    public ?string $error = null;

    public function createChannel(): void
    {
        $this->authorizeWrite();
        $this->error = null;

        $this->validate(['name' => ['required', 'string', 'max:100']]);

        if (Channel::exists()) {
            $this->error = 'Workspace ini sudah memiliki kanal WhatsApp.';

            return;
        }

        $bot = Bot::orderBy('id')->first();

        if ($bot === null) {
            $this->error = 'Simpan Pengaturan Bot terlebih dahulu sebelum membuat kanal.';

            return;
        }

        Channel::create(['bot_id' => $bot->id, 'name' => $this->name, 'status' => Channel::STATUS_CREATED]);
    }

    public function connect(WhatsAppChannelAdapter $adapter): void
    {
        $this->authorizeWrite();
        $this->error = null;

        $channel = Channel::firstOrFail();

        try {
            $state = $adapter->start($channel)['state'] ?? 'starting';
            $this->applyState($channel, $state);
        } catch (ChannelSendException $e) {
            $this->failed($e);
        }
    }

    public function disconnect(WhatsAppChannelAdapter $adapter): void
    {
        $this->authorizeWrite();
        $this->error = null;

        $channel = Channel::firstOrFail();

        try {
            // logout=true: perangkat ditautkan-lepas dan kredensial dihapus di konektor (FR-009.6).
            $adapter->stop($channel, logout: true);
        } catch (ChannelSendException $e) {
            // Kanal tidak dikenal konektor berarti tidak ada yang perlu diputus.
            if ($e->type !== ChannelSendException::NOT_FOUND) {
                $this->failed($e);

                return;
            }
        }

        $channel->update(['status' => 'logged_out', 'external_account_id' => null, 'last_status_at' => now()]);
    }

    public function canManage(): bool
    {
        return app(CurrentTenant::class)->userCanWrite(auth()->user());
    }

    public function render(WhatsAppChannelAdapter $adapter, QrRenderer $qrRenderer)
    {
        $channel = Channel::with('bot')->orderBy('id')->first();
        $qrImage = null;

        if ($channel !== null && in_array($channel->status, array_merge(self::TRANSIENT, ['connected']), true) && $channel->status !== 'created') {
            // Payload QR hanya diambil untuk pengguna yang berhak melihatnya (viewer tidak pernah memicu pengambilan QR).
            $qrImage = $this->syncFromConnector($channel, $adapter, $qrRenderer, fetchQr: $this->canManage());
            $channel->refresh();
        }

        return view('livewire.tenant.whatsapp-connection', [
            'channel' => $channel,
            'qrImage' => $qrImage,
            'canManage' => $this->canManage(),
            'hasBot' => Bot::exists(),
            'pollSeconds' => ($channel && in_array($channel->status, self::TRANSIENT, true)) ? 3 : 10,
            'labels' => self::labels(),
        ])->layout('tenant.layouts.app', ['title' => 'WhatsApp']);
    }

    /** @return string|null data URI gambar QR bila sedang menunggu pemindaian */
    private function syncFromConnector(Channel $channel, WhatsAppChannelAdapter $adapter, QrRenderer $qrRenderer, bool $fetchQr): ?string
    {
        try {
            $status = $adapter->status($channel);
            $state = $status['state'] ?? null;

            if (is_string($state)) {
                $this->applyState($channel, $state, $status['me'] ?? null);
            }

            if ($state === 'qr' && $fetchQr) {
                $payload = $adapter->qr($channel);

                return $payload ? $qrRenderer->dataUri($payload) : null;
            }
        } catch (ChannelSendException $e) {
            // Konektor mati/tidak mengenal kanal: tampilkan status terakhir yang diketahui, jangan error.
            if ($e->type !== ChannelSendException::NOT_FOUND) {
                $this->error = $this->message($e);
            }
        } catch (\Throwable $e) {
            Log::warning('whatsapp.connection.render_failed', ['error' => $e::class]); // tanpa payload QR
        }

        return null;
    }

    private function applyState(Channel $channel, string $state, mixed $me = null): void
    {
        if (! in_array($state, Channel::CONNECTOR_STATES, true) || $channel->status === Channel::STATUS_REVOKED) {
            return;
        }

        $account = is_array($me) ? ($me['id'] ?? null) : (is_string($me) ? $me : null);

        $channel->update(array_filter([
            'status' => $state,
            'external_account_id' => is_string($account) ? mb_substr($account, 0, 100) : null,
            'last_status_at' => now(),
        ], fn ($v) => $v !== null));
    }

    private function failed(ChannelSendException $e): void
    {
        Log::warning('whatsapp.connection.failed', ['type' => $e->type]);
        $this->error = $this->message($e);
    }

    /** Pesan untuk tenant: tanpa detail teknis (alamat/port/token konektor). */
    private function message(ChannelSendException $e): string
    {
        return match ($e->type) {
            ChannelSendException::UNREACHABLE, ChannelSendException::TIMEOUT_UNKNOWN => 'Layanan WhatsApp belum dapat dihubungi. Coba lagi beberapa saat lagi.',
            default => 'Terjadi kendala pada layanan WhatsApp. Silakan coba lagi.',
        };
    }

    private function authorizeWrite(): void
    {
        abort_unless($this->canManage(), 403);
    }

    /** @return array<string, string> */
    public static function labels(): array
    {
        return [
            'created' => 'Belum dihubungkan',
            'starting' => 'Memulai sambungan…',
            'qr' => 'Menunggu pemindaian QR',
            'qr_expired' => 'QR kedaluwarsa — klik "Hubungkan" lagi',
            'reconnecting' => 'Menyambung ulang…',
            'connected' => 'Terhubung',
            'failed' => 'Gagal tersambung — klik "Hubungkan" lagi',
            'logged_out' => 'Perangkat terputus — klik "Hubungkan" untuk memindai ulang',
            'stopped' => 'Berhenti',
            'revoked' => 'Dicabut',
        ];
    }
}
