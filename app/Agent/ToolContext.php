<?php

declare(strict_types=1);

namespace App\Agent;

use App\Models\AgentRun;
use App\Models\Message;
use App\Models\User;
use Carbon\CarbonImmutable;

/** Authenticated execution context resolved from Telegram identity — the only source of "who". */
final class ToolContext
{
    /** @var list<list<array{text: string, callback_data: string}>> */
    public array $keyboard = [];

    public function __construct(
        public readonly User $user,
        public readonly ?Message $sourceMessage,
        public readonly AgentRun $run,
        public readonly string $trigger, // user_message | callback | proactive
        public readonly CarbonImmutable $now,
    ) {}

    public function sourceMessageId(): ?int
    {
        return $this->sourceMessage?->id;
    }

    public function isUserTurn(): bool
    {
        return $this->trigger === 'user_message' && $this->sourceMessage !== null && $this->sourceMessage->role->value === 'user';
    }

    /** @param list<array{text: string, callback_data: string}> $row */
    public function addKeyboardRow(array $row): void
    {
        if (count($this->keyboard) < 6) {
            $this->keyboard[] = $row;
        }
    }
}
