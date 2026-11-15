<?php

declare(strict_types=1);

namespace App\Agent\OpenAI;

/** Estimates spend from configured per-token prices (operators set real prices via env). */
final class CostEstimator
{
    public function chat(int $promptTokens, int $completionTokens): float
    {
        $in = (float) config('companion.openai.price_input_per_million');
        $out = (float) config('companion.openai.price_output_per_million');

        return round(($promptTokens * $in + $completionTokens * $out) / 1_000_000, 6);
    }

    public function transcription(int $seconds): float
    {
        return round(max(1, $seconds) / 60 * (float) config('companion.openai.price_transcription_per_minute'), 6);
    }
}
