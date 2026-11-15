<?php

declare(strict_types=1);

namespace App\Agent\OpenAI;

final readonly class ChatRequest
{
    /**
     * @param  list<array<string, mixed>>  $messages  OpenAI chat messages
     * @param  list<array<string, mixed>>  $tools  function tool definitions (strict schemas)
     * @param  array{name: string, schema: array<string, mixed>}|null  $jsonSchema  structured output schema
     */
    public function __construct(
        public string $model,
        public array $messages,
        public array $tools = [],
        public ?array $jsonSchema = null,
        public int $maxCompletionTokens = 1000,
        public ?float $temperature = null,
        public string $purpose = 'chat',
    ) {}
}
