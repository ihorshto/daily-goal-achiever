<?php

declare(strict_types=1);

namespace App\Telegram;

/**
 * Converts model text to Telegram-safe HTML: everything is escaped first, then a tiny,
 * well-formed subset (**bold**, `code`) is re-introduced. Splits long text under the 4096 limit.
 */
final class TelegramFormatter
{
    public const LIMIT = 4000;

    public static function toHtml(string $text): string
    {
        // Telegram HTML only understands &lt; &gt; &amp; &quot; (no &apos;), and text never lands in an
        // attribute, so escape just & < >. Double-encode so literal entities in model text show as typed.
        $escaped = htmlspecialchars($text, ENT_NOQUOTES | ENT_SUBSTITUTE | ENT_HTML401, 'UTF-8', true);
        $escaped = preg_replace('/\*\*([^*\n]{1,200})\*\*/u', '<b>$1</b>', $escaped) ?? $escaped;
        $escaped = preg_replace('/`([^`\n]{1,200})`/u', '<code>$1</code>', $escaped) ?? $escaped;

        return $escaped;
    }

    /** Plain-text fallback: strip markdown markers. */
    public static function toPlain(string $text): string
    {
        return str_replace(['**', '`'], '', $text);
    }

    /** @return list<string> */
    public static function split(string $text, int $limit = self::LIMIT): array
    {
        $text = trim($text);
        if (mb_strlen($text) <= $limit) {
            return [$text];
        }
        $parts = [];
        $current = '';
        foreach (preg_split("/(\n\n)/u", $text, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [$text] as $piece) {
            if (mb_strlen($current.$piece) > $limit) {
                if ($current !== '') {
                    $parts[] = trim($current);
                    $current = '';
                }
                while (mb_strlen($piece) > $limit) {
                    $cut = mb_strrpos(mb_substr($piece, 0, $limit), ' ') ?: $limit;
                    $parts[] = trim(mb_substr($piece, 0, $cut));
                    $piece = mb_substr($piece, $cut);
                }
            }
            $current .= $piece;
        }
        if (trim($current) !== '') {
            $parts[] = trim($current);
        }

        return $parts;
    }
}
