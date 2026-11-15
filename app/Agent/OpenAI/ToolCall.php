<?php

declare(strict_types=1);

namespace App\Agent\OpenAI;

final readonly class ToolCall
{
    public function __construct(
        public string $id,
        public string $name,
        public string $argumentsJson,
    ) {}
}
