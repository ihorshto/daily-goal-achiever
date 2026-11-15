<?php

declare(strict_types=1);

namespace App\Agent;

/**
 * Conservative token estimate (~3.5 chars/token) for budgeting. Avoids a tokenizer
 * dependency; actual usage is recorded from the API response.
 */
final class TokenEstimator
{
    public function estimate(string $text): int
    {
        return (int) ceil(mb_strlen($text) / 3.5);
    }

    /** Trim text to fit a token budget, cutting at a line boundary when possible. */
    public function fit(string $text, int $budget): string
    {
        if ($this->estimate($text) <= $budget) {
            return $text;
        }
        $chars = (int) ($budget * 3.5);
        $cut = mb_substr($text, 0, $chars);
        $nl = mb_strrpos($cut, "\n");
        if ($nl !== false && $nl > $chars * 0.6) {
            $cut = mb_substr($cut, 0, $nl);
        }

        return $cut."\n…[truncated to fit context budget]";
    }
}
