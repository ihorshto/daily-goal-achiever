<?php

declare(strict_types=1);

namespace App\Proactive;

final readonly class ProactiveDraft
{
    /** @param list<list<array{text: string, callback_data: string}>> $keyboard */
    public function __construct(
        public bool $send,
        public string $text,
        public array $keyboard,
        public ?string $reason,
        public bool $usedLlm,
    ) {}
}
