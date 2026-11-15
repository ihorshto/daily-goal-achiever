<?php

declare(strict_types=1);

namespace App\Telegram;

final readonly class TelegramResult
{
    public function __construct(
        public bool $ok,
        public mixed $result,
        public int $errorCode,
        public ?string $description,
        public ?int $retryAfter = null,
    ) {}

    public function isRateLimited(): bool
    {
        return $this->errorCode === 429;
    }

    public function isParseError(): bool
    {
        return $this->errorCode === 400 && $this->description !== null && str_contains(strtolower($this->description), 'parse');
    }

    /** Errors that will never succeed on retry (bot blocked, chat not found, bad request). */
    public function isPermanent(): bool
    {
        return in_array($this->errorCode, [400, 401, 403, 404], true);
    }

    public function messageId(): ?int
    {
        return is_array($this->result) && isset($this->result['message_id']) ? (int) $this->result['message_id'] : null;
    }
}
