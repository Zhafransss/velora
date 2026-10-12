<?php

namespace App\Services\Whatsapp;

use RuntimeException;

/**
 * Galat pengiriman yang dinormalisasi. `retryable` hanya true bila pesan PASTI belum terkirim
 * (tidak tersambung / konektor tak terjangkau); galat yang ambigu (timeout, 5xx) tidak diulang agar
 * pelanggan tidak menerima balasan ganda. Pesan galat tidak memuat isi pesan, token, atau body respons.
 */
class ChannelSendException extends RuntimeException
{
    public const NOT_CONNECTED = 'channel_not_connected';
    public const NOT_FOUND = 'channel_not_found';
    public const UNREACHABLE = 'connector_unreachable';
    public const TIMEOUT_UNKNOWN = 'send_timeout_unknown';
    public const CONNECTOR_ERROR = 'connector_error';
    public const INVALID = 'invalid_message';
    public const UNAUTHORIZED = 'connector_unauthorized';

    public function __construct(public readonly string $type, public readonly bool $retryable, public readonly ?int $httpStatus = null)
    {
        parent::__construct($type . ($httpStatus ? " (HTTP {$httpStatus})" : ''));
    }
}
