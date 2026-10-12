<?php

namespace App\Services\Whatsapp;

final class SendResult
{
    public function __construct(public readonly ?string $externalMessageId)
    {
    }
}
