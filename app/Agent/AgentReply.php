<?php

declare(strict_types=1);

namespace App\Agent;

final readonly class AgentReply
{
    /** @param list<list<array{text: string, callback_data: string}>> $keyboard */
    public function __construct(
        public string $text,
        public array $keyboard,
        public ?int $runId,
        public string $status,
    ) {}
}
