<?php

declare(strict_types=1);

namespace App\Agent\OpenAI;

use RuntimeException;

final class LlmException extends RuntimeException
{
    public function __construct(string $message, public readonly bool $retryable = false, public readonly ?int $status = null)
    {
        parent::__construct($message);
    }
}
