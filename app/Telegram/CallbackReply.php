<?php

declare(strict_types=1);

namespace App\Telegram;

use Stringable;

/** A follow-up message (with its own keyboard) produced by a button press. */
final readonly class CallbackReply implements Stringable
{
    /** @param array<string, mixed>|null $markup */
    public function __construct(public string $text, public ?array $markup) {}

    public function __toString(): string
    {
        return $this->text;
    }
}
