<?php

declare(strict_types=1);

namespace App\Agent;

final readonly class ToolResult
{
    /** @param array<string, mixed> $data */
    public function __construct(
        public bool $ok,
        public array $data = [],
        public ?string $error = null,
    ) {}

    /** @param array<string, mixed> $data */
    public static function ok(array $data = []): self
    {
        return new self(true, $data);
    }

    public static function error(string $message): self
    {
        return new self(false, [], $message);
    }

    public function toJson(): string
    {
        $payload = $this->ok ? ['ok' => true] + $this->data : ['ok' => false, 'error' => $this->error];

        return (string) json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);
    }

    public function toArray(): array
    {
        return $this->ok ? ['ok' => true] + $this->data : ['ok' => false, 'error' => $this->error];
    }
}
