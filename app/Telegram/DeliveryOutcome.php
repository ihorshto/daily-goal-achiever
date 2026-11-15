<?php

declare(strict_types=1);

namespace App\Telegram;

final readonly class DeliveryOutcome
{
    public function __construct(
        public string $status, // sent | duplicate | rate_limited | retryable | failed
        public ?int $telegramMessageId,
        public ?int $retryAfter = null,
        public ?string $errorCode = null,
    ) {}

    public function delivered(): bool
    {
        return $this->status === 'sent';
    }

    public function shouldRetry(): bool
    {
        return in_array($this->status, ['rate_limited', 'retryable'], true);
    }
}
