<?php

declare(strict_types=1);

namespace App\Support;

use App\Domain\DomainException;

/**
 * Normalizes a user-chosen tone (free text, e.g. "direct and a bit ironic, no emojis"),
 * or null for the built-in default tone.
 */
final class ReplyTone
{
    public const MAX_CHARS = 300;

    private const DEFAULT_WORDS = ['default', 'reset', 'auto', 'off', 'за замовчуванням', 'стандартний', 'скинути'];

    /** @return string|null cleaned tone, or null for the default */
    public static function normalize(string $input): ?string
    {
        // Single line, no control characters: the value is interpolated into the system prompt.
        $clean = trim((string) preg_replace('/[\p{C}\s]+/u', ' ', $input));
        if ($clean === '' || in_array(mb_strtolower($clean), self::DEFAULT_WORDS, true)) {
            return null;
        }
        if (mb_strlen($clean) > self::MAX_CHARS) {
            throw new DomainException('Tone description is too long (max '.self::MAX_CHARS.' characters).');
        }

        return $clean;
    }

    public static function describe(?string $tone): string
    {
        return $tone ?? 'default (warm, concise, nonjudgmental)';
    }
}
