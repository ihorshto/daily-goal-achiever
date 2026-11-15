<?php

declare(strict_types=1);

namespace App\Support;

use Carbon\CarbonImmutable;
use DateTimeZone;
use InvalidArgumentException;

/**
 * Timezone-explicit conversions between local wall-clock time and UTC instants.
 *
 * Rules (documented in docs/architecture.md §Temporal semantics):
 *  - Nonexistent local times (spring-forward gap) resolve to the first valid instant after the gap.
 *  - Ambiguous local times (fall-back overlap) resolve to the EARLIER instant (first occurrence).
 *  - Deadlines without an explicit time are date-only (due_date) and treated as end of local day.
 */
final class LocalTime
{
    public static function isValidTimezone(string $tz): bool
    {
        return in_array($tz, DateTimeZone::listIdentifiers(DateTimeZone::ALL_WITH_BC), true);
    }

    public static function isValidClock(string $hhmm): bool
    {
        return (bool) preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $hhmm);
    }

    /**
     * Resolve a local date + HH:MM in an IANA zone to a UTC instant, DST-safe.
     */
    public static function at(string $localDate, string $hhmm, string $tz): CarbonImmutable
    {
        if (! self::isValidClock($hhmm)) {
            throw new InvalidArgumentException("Invalid clock time [$hhmm].");
        }
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $localDate)) {
            throw new InvalidArgumentException("Invalid local date [$localDate].");
        }

        return self::parseLocal("$localDate $hhmm:00", $tz);
    }

    /**
     * Parse a local datetime string (no offset, "Y-m-d H:i[:s]" or ISO "Y-m-dTH:i") in $tz.
     * If the string carries an explicit offset/Z it is honored as-is.
     */
    public static function parseLocal(string $value, string $tz): CarbonImmutable
    {
        $value = trim($value);
        if (preg_match('/(Z|[+-]\d{2}:?\d{2})$/', $value)) {
            return CarbonImmutable::parse($value)->utc();
        }
        if (! preg_match('/^(\d{4})-(\d{2})-(\d{2})[T ](\d{2}):(\d{2})(?::(\d{2}))?$/', $value, $m)) {
            throw new InvalidArgumentException("Unrecognized local datetime [$value].");
        }
        [$y, $mo, $d, $h, $i, $s] = [(int) $m[1], (int) $m[2], (int) $m[3], (int) $m[4], (int) $m[5], (int) ($m[6] ?? 0)];
        if (! checkdate($mo, $d, $y) || $h > 23 || $i > 59 || $s > 59) {
            throw new InvalidArgumentException("Invalid local datetime [$value].");
        }

        $zone = new DateTimeZone($tz);
        $wall = sprintf('%04d-%02d-%02d %02d:%02d:%02d', $y, $mo, $d, $h, $i, $s);

        // Candidate offsets around this wall time: pick the earliest instant whose local rendering matches.
        $probe = new \DateTimeImmutable($wall, new DateTimeZone('UTC'));
        $offsets = array_unique([
            $zone->getOffset($probe->modify('-1 day')),
            $zone->getOffset($probe),
            $zone->getOffset($probe->modify('+1 day')),
        ]);
        $matches = [];
        foreach ($offsets as $offset) {
            $instant = $probe->modify(sprintf('%+d seconds', -$offset));
            if ($instant->setTimezone($zone)->format('Y-m-d H:i:s') === $wall) {
                $matches[] = $instant->getTimestamp();
            }
        }
        if ($matches !== []) {
            return CarbonImmutable::createFromTimestampUTC(min($matches));
        }

        // Nonexistent (inside a spring-forward gap): resolve to the transition instant,
        // i.e. the first valid instant after the gap (02:30 on a 02:00→03:00 day becomes 03:00).
        $wallEpoch = $probe->getTimestamp();
        $transitions = $zone->getTransitions($wallEpoch - 2 * 86400, $wallEpoch + 2 * 86400) ?: [];
        for ($i = 1, $n = count($transitions); $i < $n; $i++) {
            $before = $transitions[$i - 1]['offset'];
            $after = $transitions[$i]['offset'];
            $t = $transitions[$i]['ts'];
            if ($after > $before && $wallEpoch >= $t + $before && $wallEpoch < $t + $after) {
                return CarbonImmutable::createFromTimestampUTC($t);
            }
        }

        throw new InvalidArgumentException("Cannot resolve local datetime [$value] in [$tz].");
    }

    public static function localDate(CarbonImmutable $instant, string $tz): string
    {
        return $instant->setTimezone($tz)->toDateString();
    }

    public static function today(string $tz): string
    {
        return CarbonImmutable::now($tz)->toDateString();
    }

    /** End of the local day (23:59:59) as a UTC instant — used for date-only deadlines. */
    public static function endOfLocalDay(string $localDate, string $tz): CarbonImmutable
    {
        return CarbonImmutable::parse($localDate, $tz)->endOfDay()->utc();
    }

    public static function startOfLocalDay(string $localDate, string $tz): CarbonImmutable
    {
        return CarbonImmutable::parse($localDate, $tz)->startOfDay()->utc();
    }

    /** Minutes since local midnight for an HH:MM string. */
    public static function clockToMinutes(string $hhmm): int
    {
        [$h, $m] = array_map('intval', explode(':', $hhmm));

        return $h * 60 + $m;
    }

    /**
     * Is $instant inside the quiet window [start, end) in local time? Handles overnight windows (22:00–08:00).
     */
    public static function inQuietHours(CarbonImmutable $instant, string $tz, string $start, string $end): bool
    {
        $local = $instant->setTimezone($tz);
        $now = $local->hour * 60 + $local->minute;
        $s = self::clockToMinutes($start);
        $e = self::clockToMinutes($end);
        if ($s === $e) {
            return false;
        }

        return $s < $e ? ($now >= $s && $now < $e) : ($now >= $s || $now < $e);
    }

    /** Next instant at/after $instant when quiet hours end (DST-safe). */
    public static function quietHoursEnd(CarbonImmutable $instant, string $tz, string $start, string $end): CarbonImmutable
    {
        if (! self::inQuietHours($instant, $tz, $start, $end)) {
            return $instant;
        }
        $localDate = self::localDate($instant, $tz);
        $candidate = self::at($localDate, $end, $tz);
        if ($candidate->lessThanOrEqualTo($instant)) {
            $next = CarbonImmutable::parse($localDate, $tz)->addDay()->toDateString();
            $candidate = self::at($next, $end, $tz);
        }

        return $candidate;
    }

    /** Human-readable local rendering for prompts and messages. */
    public static function human(?CarbonImmutable $instant, string $tz): ?string
    {
        return $instant?->setTimezone($tz)->format('D Y-m-d H:i T');
    }
}
