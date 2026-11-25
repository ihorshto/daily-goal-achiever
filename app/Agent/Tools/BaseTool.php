<?php

declare(strict_types=1);

namespace App\Agent\Tools;

use App\Agent\ToolContext;
use App\Support\LocalTime;
use Carbon\CarbonImmutable;
use InvalidArgumentException;

abstract class BaseTool implements Tool
{
    public function mutates(): bool
    {
        return true;
    }

    public function availableFor(ToolContext $context): bool
    {
        return true;
    }

    /** @param array<string, array<string, mixed>> $properties */
    protected function object(array $properties): array
    {
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => array_keys($properties),
            'properties' => $properties === [] ? new \stdClass : $properties,
        ];
    }

    protected function string(string $description, bool $nullable = false): array
    {
        return ['type' => $nullable ? ['string', 'null'] : 'string', 'description' => $description];
    }

    protected function integer(string $description, bool $nullable = false): array
    {
        return ['type' => $nullable ? ['integer', 'null'] : 'integer', 'description' => $description];
    }

    protected function boolean(string $description, bool $nullable = false): array
    {
        return ['type' => $nullable ? ['boolean', 'null'] : 'boolean', 'description' => $description];
    }

    /** @param list<string> $values */
    protected function enum(array $values, string $description, bool $nullable = false): array
    {
        return [
            'type' => $nullable ? ['string', 'null'] : 'string',
            'enum' => $nullable ? [...$values, null] : $values,
            'description' => $description,
        ];
    }

    /**
     * Parse a model-supplied local time: "YYYY-MM-DD" → date-only, "YYYY-MM-DDTHH:MM" → instant.
     *
     * @return array{0: CarbonImmutable|null, 1: string|null}
     */
    protected function parseWhen(?string $value, string $tz): array
    {
        if ($value === null || trim($value) === '') {
            return [null, null];
        }
        $value = trim($value);
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            [$y, $m, $d] = array_map('intval', explode('-', $value));
            if (! checkdate($m, $d, $y)) {
                throw new InvalidArgumentException("Invalid date [$value].");
            }

            return [null, $value];
        }

        return [LocalTime::parseLocal($value, $tz), null];
    }

    protected function localDate(?string $value, string $tz): string
    {
        if ($value === null || $value === '') {
            return LocalTime::today($tz);
        }
        [, $date] = $this->parseWhen($value, $tz);
        if ($date === null) {
            throw new InvalidArgumentException('Expected a date in YYYY-MM-DD format.');
        }

        return $date;
    }
}
