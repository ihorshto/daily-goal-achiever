<?php

declare(strict_types=1);

namespace App\Agent\OpenAI;

final readonly class ChatResponse
{
    /** @param list<ToolCall> $toolCalls */
    public function __construct(
        public ?string $content,
        public array $toolCalls,
        public string $finishReason,
        public int $promptTokens,
        public int $completionTokens,
        public string $model,
        public ?string $refusal = null,
    ) {}

    /** Decode a structured (json_schema) response. Returns null when malformed. */
    public function json(): ?array
    {
        if ($this->content === null) {
            return null;
        }
        $decoded = json_decode($this->content, true);

        return is_array($decoded) ? $decoded : null;
    }

    /** The assistant message to append to the transcript when tools were called. */
    public function assistantMessage(): array
    {
        $msg = ['role' => 'assistant', 'content' => $this->content];
        if ($this->toolCalls !== []) {
            $msg['tool_calls'] = array_map(fn (ToolCall $c) => [
                'id' => $c->id,
                'type' => 'function',
                'function' => ['name' => $c->name, 'arguments' => $c->argumentsJson],
            ], $this->toolCalls);
        }

        return $msg;
    }
}
