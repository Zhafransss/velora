<?php

namespace App\Services\Whatsapp;

use App\Jobs\ProcessInboundMessage;
use App\Models\Channel;
use App\Models\Conversation;
use App\Models\Message;
use App\Services\Notifications\TenantNotifier;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Menerima event dari konektor (setelah tanda tangan terverifikasi) dan memetakannya ke tenant lewat channel.
 * Tenant SELALU berasal dari channel di database, tidak pernah dari isi payload (FR-003, FR-009.3).
 */
class InboundMessageHandler
{
    public const ACCEPTED = 'accepted';
    public const DUPLICATE = 'duplicate';
    public const IGNORED = 'ignored';
    public const UNKNOWN_CHANNEL = 'unknown_channel';

    private const MAX_BODY = 4000;

    public function __construct(private CurrentTenant $context)
    {
    }

    /** @param array{channelId: string, externalMessageId: string, from: string, fromAlt?: ?string, pushName?: ?string, text: string, timestamp?: int} $payload */
    public function handleInbound(array $payload): string
    {
        $channel = Channel::findByPublicId($payload['channelId']);

        if ($channel === null) {
            return self::UNKNOWN_CHANNEL;
        }

        if ($channel->status === Channel::STATUS_REVOKED) {
            return self::IGNORED;
        }

        $tenant = $channel->tenant()->firstOrFail();

        return $this->context->runAs($tenant, function () use ($channel, $payload) {
            $conversation = $this->conversationFor($channel, $payload);

            try {
                $message = Message::create([
                    'conversation_id' => $conversation->id,
                    'direction' => Message::IN,
                    'external_message_id' => $payload['externalMessageId'],
                    'body' => Str::limit($payload['text'], self::MAX_BODY, ''),
                    'status' => Message::RECEIVED,
                ]);
            } catch (UniqueConstraintViolationException) {
                // Idempotency (TC-IDEM-01): pesan yang sama tidak diproses, dan tidak memakai kuota, dua kali.
                return self::DUPLICATE;
            }

            $conversation->update(['last_message_at' => Carbon::createFromTimestamp($payload['timestamp'] ?? time())]);

            ProcessInboundMessage::dispatch($channel->tenant_id, $message->id);

            return self::ACCEPTED;
        });
    }

    /** @param array{channelId: string, state: string, me?: mixed, at?: ?string} $payload */
    public function handleStatus(array $payload): string
    {
        $channel = Channel::findByPublicId($payload['channelId']);

        if ($channel === null) {
            return self::UNKNOWN_CHANNEL;
        }

        if ($channel->status === Channel::STATUS_REVOKED || ! in_array($payload['state'], Channel::CONNECTOR_STATES, true)) {
            return self::IGNORED;
        }

        $me = $payload['me'] ?? null;
        $account = is_array($me) ? ($me['id'] ?? null) : (is_string($me) ? $me : null);

        $previous = $channel->status;

        // Update langsung lewat query terpusat: konteks tenant tidak ada pada jalur ini, dan hanya status yang disentuh.
        Channel::withoutTenancy()->whereKey($channel->id)->update(array_filter([
            'status' => $payload['state'],
            'external_account_id' => is_string($account) ? Str::limit($account, 100, '') : null,
            'last_status_at' => now(),
        ], fn ($v) => $v !== null));

        // Kanal yang sebelumnya baik lalu putus permanen: beri tahu pemilik (DEC-16). 'reconnecting' bersifat sementara.
        if (in_array($payload['state'], ['logged_out', 'failed'], true) && $previous !== $payload['state']) {
            app(TenantNotifier::class)->channelDown($channel->tenant()->firstOrFail(), $channel, $payload['state']);
        }

        return self::ACCEPTED;
    }

    private function conversationFor(Channel $channel, array $payload): Conversation
    {
        $attrs = ['channel_id' => $channel->id, 'customer_external_id' => $payload['from']];
        $extra = array_filter([
            'bot_id' => $channel->bot_id,
            'customer_alt_id' => $payload['fromAlt'] ?? null,
            'customer_name' => isset($payload['pushName']) ? Str::limit($payload['pushName'], 150, '') : null,
        ], fn ($v) => $v !== null);

        try {
            $conversation = Conversation::firstOrCreate($attrs, $extra);
        } catch (UniqueConstraintViolationException) {
            // Dua pesan pertama dari pelanggan yang sama tiba bersamaan.
            $conversation = Conversation::where($attrs)->firstOrFail();
        }

        // Perbarui nama/nomor bila pelanggan mengubahnya; jangan menimpa dengan kosong.
        $conversation->fill(array_diff_key($extra, ['bot_id' => 1]))->save();

        return $conversation;
    }
}
