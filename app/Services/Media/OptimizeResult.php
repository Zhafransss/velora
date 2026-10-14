<?php

namespace App\Services\Media;

/** Hasil satu optimasi gambar. `reason` diisi bila dilewati (missing, unsupported, already_optimal, ...). */
final class OptimizeResult
{
    private function __construct(
        public readonly string $path,
        public readonly ?string $newPath,
        public readonly ?string $reason,
        public readonly int $oldBytes,
        public readonly int $newBytes,
        /** @var array{0: int, 1: int}|null */
        public readonly ?array $oldSize = null,
        /** @var array{0: int, 1: int}|null */
        public readonly ?array $newSize = null,
    ) {
    }

    public static function optimized(string $path, string $newPath, int $oldBytes, int $newBytes, array $oldSize, array $newSize): self
    {
        return new self($path, $newPath, null, $oldBytes, $newBytes, $oldSize, $newSize);
    }

    public static function skipped(string $path, string $reason, int $bytes = 0): self
    {
        return new self($path, null, $reason, $bytes, $bytes);
    }

    public function wasOptimized(): bool
    {
        return $this->newPath !== null;
    }
}
