<?php

declare(strict_types=1);

namespace App\Proactive;

use Carbon\CarbonImmutable;

final readonly class PolicyDecision
{
    private function __construct(
        public string $action, // send | defer | suppress | expire
        public ?string $reason = null,
        public ?CarbonImmutable $until = null,
    ) {}

    public static function send(): self
    {
        return new self('send');
    }

    public static function defer(CarbonImmutable $until, string $reason): self
    {
        return new self('defer', $reason, $until);
    }

    public static function suppress(string $reason): self
    {
        return new self('suppress', $reason);
    }

    public static function expire(string $reason): self
    {
        return new self('expire', $reason);
    }
}
