<?php

declare(strict_types=1);

namespace App\Memory;

final readonly class ProviderMemory
{
    /** @param array<string, mixed> $metadata */
    public function __construct(
        public string $id,
        public string $text,
        public ?float $score,
        public array $metadata = [],
        public ?string $createdAt = null,
    ) {}
}
