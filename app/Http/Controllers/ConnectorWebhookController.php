<?php

namespace App\Http\Controllers;

use App\Services\Whatsapp\InboundMessageHandler;
use App\Services\Whatsapp\WebhookSignature;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

/**
 * Penerima webhook dari konektor Baileys. Tidak memakai sesi/CSRF; keabsahan dijamin tanda tangan HMAC.
 * Pekerjaan berat (AI) dilempar ke antrean — endpoint hanya memvalidasi, menyimpan, dan membalas cepat (FR-015).
 */
class ConnectorWebhookController extends Controller
{
    private const JID = '/^[\w.:-]+@(s\.whatsapp\.net|lid)$/';
    private const CHANNEL_ID = '/^[A-Za-z0-9_-]{1,64}$/';

    public function __invoke(Request $request, WebhookSignature $signature, InboundMessageHandler $handler): JsonResponse
    {
        // Tanda tangan dihitung atas body MENTAH, sebelum diurai.
        if (! $signature->valid($request->getContent(), $request->header('X-Velora-Timestamp'), $request->header('X-Velora-Signature'))) {
            Log::warning('webhook.rejected', ['reason' => 'invalid_signature', 'ip' => $request->ip()]);

            return response()->json(['error' => 'invalid_signature'], 401);
        }

        $payload = json_decode($request->getContent(), true);

        if (! is_array($payload) || ! is_string($payload['type'] ?? null)) {
            return response()->json(['error' => 'invalid_payload'], 422);
        }

        return match ($payload['type']) {
            'message.inbound' => $this->inbound($payload, $handler),
            'channel.status' => $this->status($payload, $handler),
            default => response()->json(['status' => 'ignored'], 202),
        };
    }

    private function inbound(array $payload, InboundMessageHandler $handler): JsonResponse
    {
        $v = Validator::make($payload, [
            'channelId' => ['required', 'string', 'regex:' . self::CHANNEL_ID],
            'externalMessageId' => ['required', 'string', 'max:100'],
            'from' => ['required', 'string', 'max:100', 'regex:' . self::JID],
            'fromAlt' => ['nullable', 'string', 'max:100', 'regex:' . self::JID],
            'pushName' => ['nullable', 'string', 'max:300'],
            'text' => ['required', 'string', 'min:1', 'max:65536'],
            'timestamp' => ['nullable', 'integer', 'min:0'],
        ]);

        if ($v->fails()) {
            return response()->json(['error' => 'invalid_payload', 'fields' => array_keys($v->errors()->messages())], 422);
        }

        return $this->respond($handler->handleInbound($v->validated()));
    }

    private function status(array $payload, InboundMessageHandler $handler): JsonResponse
    {
        $v = Validator::make($payload, [
            'channelId' => ['required', 'string', 'regex:' . self::CHANNEL_ID],
            'state' => ['required', 'string', 'max:20'],
            'me' => ['nullable'],
            'at' => ['nullable', 'string', 'max:40'],
        ]);

        if ($v->fails()) {
            return response()->json(['error' => 'invalid_payload', 'fields' => array_keys($v->errors()->messages())], 422);
        }

        return $this->respond($handler->handleStatus($v->validated()));
    }

    private function respond(string $result): JsonResponse
    {
        // Channel tak dikenal: 404 (konektor mencatatnya) dan tidak membuka informasi tenant.
        return $result === InboundMessageHandler::UNKNOWN_CHANNEL
            ? response()->json(['status' => $result], 404)
            : response()->json(['status' => $result]);
    }
}
