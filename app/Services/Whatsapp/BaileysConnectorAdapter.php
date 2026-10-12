<?php

namespace App\Services\Whatsapp;

use App\Models\Channel;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/** Adapter ke konektor Baileys (Node, localhost) lewat API internal ber-Bearer token. */
class BaileysConnectorAdapter implements WhatsAppChannelAdapter
{
    public function __construct(
        private string $baseUrl,
        private ?string $token,
        private int $sendTimeoutSeconds = 40,
    ) {
    }

    public static function fromConfig(): self
    {
        return new self(config('whatsapp.connector_url'), config('whatsapp.connector_token'), (int) config('whatsapp.send_timeout_seconds'));
    }

    public function send(Channel $channel, string $to, string $text): SendResult
    {
        try {
            $response = $this->http($this->sendTimeoutSeconds)->post("/channels/{$channel->public_id}/send", ['to' => $to, 'text' => $text]);
        } catch (ConnectionException $e) {
            // cURL 7 = gagal tersambung (pesan pasti belum terkirim). Lainnya (mis. timeout 28) ambigu.
            $unreachable = str_contains($e->getMessage(), 'cURL error 7') || stripos($e->getMessage(), 'Connection refused') !== false;

            throw new ChannelSendException($unreachable ? ChannelSendException::UNREACHABLE : ChannelSendException::TIMEOUT_UNKNOWN, $unreachable);
        }

        if ($response->successful()) {
            return new SendResult($response->json('messageId'));
        }

        throw $this->sendError($response);
    }

    public function start(Channel $channel): array
    {
        return $this->call('post', "/channels/{$channel->public_id}/start");
    }

    public function status(Channel $channel): array
    {
        return $this->call('get', "/channels/{$channel->public_id}");
    }

    public function qr(Channel $channel): ?string
    {
        return $this->call('get', "/channels/{$channel->public_id}/qr")['qr'] ?? null;
    }

    public function stop(Channel $channel, bool $logout = false): array
    {
        return $this->call('delete', "/channels/{$channel->public_id}" . ($logout ? '?logout=1' : ''));
    }

    private function http(int $timeout = 15): PendingRequest
    {
        return Http::baseUrl(rtrim($this->baseUrl, '/'))->withToken((string) $this->token)->acceptJson()->asJson()
            ->connectTimeout(3)->timeout($timeout);
    }

    /** @return array<string, mixed> */
    private function call(string $method, string $path): array
    {
        try {
            $response = $this->http()->{$method}($path);
        } catch (ConnectionException) {
            throw new ChannelSendException(ChannelSendException::UNREACHABLE, true);
        }

        if (! $response->successful()) {
            throw $this->sendError($response);
        }

        return (array) $response->json();
    }

    private function sendError(Response $response): ChannelSendException
    {
        $status = $response->status();

        return match (true) {
            $status === 401 => new ChannelSendException(ChannelSendException::UNAUTHORIZED, false, $status),
            $status === 404 => new ChannelSendException(ChannelSendException::NOT_FOUND, true, $status),
            $status === 409 => new ChannelSendException(ChannelSendException::NOT_CONNECTED, true, $status),
            $status === 422 => new ChannelSendException(ChannelSendException::INVALID, false, $status),
            default => new ChannelSendException(ChannelSendException::CONNECTOR_ERROR, false, $status),
        };
    }
}
