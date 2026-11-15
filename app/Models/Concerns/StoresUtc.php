<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use Carbon\CarbonImmutable;
use DateTimeInterface;

/**
 * Eloquent formats dates as "Y-m-d H:i:s" without an offset. Normalizing every datetime to UTC
 * before serialization guarantees timestamptz columns hold correct instants even if a caller
 * passes a Carbon instance in a user timezone.
 */
trait StoresUtc
{
    public function fromDateTime($value)
    {
        if ($value instanceof DateTimeInterface) {
            $value = CarbonImmutable::instance($value)->utc();
        }

        return parent::fromDateTime($value);
    }
}
