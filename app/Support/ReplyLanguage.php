<?php

declare(strict_types=1);

namespace App\Support;

use App\Domain\DomainException;

/**
 * Normalizes a user-chosen reply language to a canonical English name ("Ukrainian"),
 * or null for "auto" (mirror the language the user writes in).
 * Accepts ISO 639-1 codes, English names and common native names.
 */
final class ReplyLanguage
{
    public const AUTO = 'auto';

    private const ALIASES = [
        'uk' => 'Ukrainian', 'ua' => 'Ukrainian', 'ukrainian' => 'Ukrainian', 'українська' => 'Ukrainian', 'українською' => 'Ukrainian',
        'en' => 'English', 'english' => 'English', 'англійська' => 'English', 'англійською' => 'English',
        'pl' => 'Polish', 'polish' => 'Polish', 'polski' => 'Polish',
        'de' => 'German', 'german' => 'German', 'deutsch' => 'German',
        'fr' => 'French', 'french' => 'French', 'français' => 'French', 'francais' => 'French',
        'es' => 'Spanish', 'spanish' => 'Spanish', 'español' => 'Spanish', 'espanol' => 'Spanish',
        'it' => 'Italian', 'italian' => 'Italian', 'italiano' => 'Italian',
        'pt' => 'Portuguese', 'portuguese' => 'Portuguese', 'português' => 'Portuguese',
        'ru' => 'Russian', 'russian' => 'Russian',
        'cs' => 'Czech', 'czech' => 'Czech', 'čeština' => 'Czech',
    ];

    private const AUTO_WORDS = ['auto', 'automatic', 'default', 'off', 'reset', 'авто', 'автоматично'];

    /** @return string|null canonical name, or null for auto */
    public static function normalize(string $input): ?string
    {
        $key = mb_strtolower(trim($input));
        if ($key === '' || in_array($key, self::AUTO_WORDS, true)) {
            return null;
        }
        if (isset(self::ALIASES[$key])) {
            return self::ALIASES[$key];
        }
        // Any other language: a plain name only (letters, spaces, hyphens), since it is interpolated into prompts.
        if (! preg_match('/^\p{L}[\p{L} \-]{1,39}$/u', $key)) {
            throw new DomainException('Unknown language. Use a name like "Ukrainian" or a code like "uk", or "auto".');
        }

        return mb_convert_case($key, MB_CASE_TITLE, 'UTF-8');
    }

    public static function describe(?string $language): string
    {
        return $language ?? 'auto (same as your message)';
    }
}
