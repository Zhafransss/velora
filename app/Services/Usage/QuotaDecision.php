<?php

namespace App\Services\Usage;

final class QuotaDecision
{
    public const GRANTED = 'granted';
    /** Kunci idempotency sudah pernah dipakai: tidak ada unit baru yang dipakai. */
    public const DUPLICATE = 'duplicate';
    public const EXCEEDED = 'exceeded';

    public function __construct(
        public readonly string $status,
        public readonly ?int $limit,
        public readonly ?int $used,
        public readonly string $usageDate,
    ) {
    }

    public function granted(): bool
    {
        return $this->status === self::GRANTED;
    }

    public function exceeded(): bool
    {
        return $this->status === self::EXCEEDED;
    }

    public function duplicate(): bool
    {
        return $this->status === self::DUPLICATE;
    }
}
