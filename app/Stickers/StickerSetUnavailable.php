<?php

declare(strict_types=1);

namespace App\Stickers;

use RuntimeException;

/** A sticker set cannot be imported. The message is safe to show the user. */
final class StickerSetUnavailable extends RuntimeException
{
    private function __construct(string $message, public readonly bool $retryable = false)
    {
        parent::__construct($message);
    }

    public static function notFound(): self
    {
        return new self('Sticker set not found.');
    }

    public static function unsupportedType(): self
    {
        return new self("Custom emoji sets can't be used by bots.");
    }

    public static function empty(): self
    {
        return new self('That sticker set is empty.');
    }

    public static function limitReached(int $max): self
    {
        return new self("You already have {$max} sticker sets; remove one with /stickers remove N.");
    }

    public static function invalidName(): self
    {
        return new self('That is not a sticker set name or t.me/addstickers link.');
    }

    public static function retryLater(): self
    {
        return new self("Telegram didn't answer, please try again in a minute.", true);
    }
}
