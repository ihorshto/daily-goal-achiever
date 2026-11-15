<?php

declare(strict_types=1);

namespace App\Enums;

enum Commitment: string
{
    case Idea = 'idea';
    case Intention = 'intention';
    case Commitment = 'commitment';

    /**
     * idea: tentative ("maybe start Stripe") – never chased proactively.
     * intention: planned but soft ("I want to fix auth tomorrow").
     * commitment: explicit deadline/promise ("deploy by 18:00") – deadline changes need confirmation.
     */
    public function isCommitted(): bool
    {
        return $this === self::Commitment;
    }
}
