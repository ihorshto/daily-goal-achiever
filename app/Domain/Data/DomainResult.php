<?php

declare(strict_types=1);

namespace App\Domain\Data;

/** Outcome of a domain operation, safe to return to the model as a tool result. */
final readonly class DomainResult
{
    /** @param array<string, mixed> $data */
    public function __construct(
        public bool $ok,
        public string $message,
        public array $data = [],
    ) {}

    /** @param array<string, mixed> $data */
    public static function ok(string $message, array $data = []): self
    {
        return new self(true, $message, $data);
    }

    /** @param array<string, mixed> $data */
    public static function fail(string $message, array $data = []): self
    {
        return new self(false, $message, $data);
    }
}
