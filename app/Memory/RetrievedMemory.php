<?php

declare(strict_types=1);

namespace App\Memory;

final readonly class RetrievedMemory
{
    public function __construct(
        public int $localId,
        public string $content,
        public string $category,
        public string $recordedAt,
        public string $verification,
        public float $score,
        public string $via,
        public int $createdAtTs,
        public string $hash,
        public bool $confirmed = false,
    ) {}

    /** Compact representation for prompts/tool results (provenance included). */
    public function toArray(): array
    {
        return [
            'memory_ref' => $this->localId,
            'content' => $this->content,
            'category' => $this->category,
            'recorded_on' => $this->recordedAt,
            'verification' => $this->verification,
        ];
    }
}
