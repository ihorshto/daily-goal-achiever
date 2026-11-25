<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Canonical form of an emoji used as a lookup key, so "❤" and "❤️", or a skin-toned and a plain
 * thumbs-up, match. Applied identically to catalog stickers and to the model's argument.
 */
final class EmojiKey
{
    public const MAX_LENGTH = 16;

    public static function normalize(?string $emoji): ?string
    {
        if ($emoji === null) {
            return null;
        }
        // Variation selectors (U+FE0E/U+FE0F) and skin tone modifiers (U+1F3FB-1F3FF) carry no meaning for matching.
        $clean = (string) preg_replace('/[\x{FE0E}\x{FE0F}\x{1F3FB}-\x{1F3FF}]/u', '', $emoji);
        // Control characters and whitespace (but not ZWJ, which glues multi-part emoji together).
        $clean = (string) preg_replace('/[\p{Z}\p{Cc}\p{Cs}]+/u', '', $clean);
        $clean = (string) preg_replace('/^\x{200D}+|\x{200D}+$/u', '', $clean); // not trim(): it is byte-based and would eat emoji ending in 0x8D/0x80/0xE2
        $clean = mb_substr($clean, 0, self::MAX_LENGTH);
        $clean = (string) preg_replace('/\x{200D}+$/u', '', $clean);

        return $clean === '' ? null : $clean;
    }
}
