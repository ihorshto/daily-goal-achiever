<?php

declare(strict_types=1);

namespace App\Agent\OpenAI;

final readonly class Transcription
{
    public function __construct(
        public string $text,
        public string $model,
    ) {}
}
