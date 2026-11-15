<?php

namespace Tests\Unit;

use App\Support\LocalTime;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class LocalTimeTest extends TestCase
{
    public function test_morning_planning_is_0900_paris_in_winter_and_summer(): void
    {
        // CET (UTC+1) and CEST (UTC+2)
        $this->assertSame('2026-01-15T08:00:00+00:00', LocalTime::at('2026-01-15', '09:00', 'Europe/Paris')->toIso8601String());
        $this->assertSame('2026-07-15T07:00:00+00:00', LocalTime::at('2026-07-15', '09:00', 'Europe/Paris')->toIso8601String());
    }

    public function test_dst_transition_days_keep_local_wall_clock(): void
    {
        // 2026-03-29 spring forward, 2026-10-25 fall back in Europe/Paris
        $this->assertSame('2026-03-29T07:00:00+00:00', LocalTime::at('2026-03-29', '09:00', 'Europe/Paris')->toIso8601String());
        $this->assertSame('2026-03-28T08:00:00+00:00', LocalTime::at('2026-03-28', '09:00', 'Europe/Paris')->toIso8601String());
        $this->assertSame('2026-10-25T19:00:00+00:00', LocalTime::at('2026-10-25', '20:00', 'Europe/Paris')->toIso8601String());
        $this->assertSame('2026-10-24T18:00:00+00:00', LocalTime::at('2026-10-24', '20:00', 'Europe/Paris')->toIso8601String());
    }

    public function test_nonexistent_time_moves_forward_past_gap(): void
    {
        // 02:30 does not exist on 2026-03-29 in Paris (02:00 -> 03:00 CEST). First valid instant: 03:00 CEST = 01:00Z.
        $this->assertSame('2026-03-29T01:00:00+00:00', LocalTime::at('2026-03-29', '02:30', 'Europe/Paris')->toIso8601String());
    }

    public function test_ambiguous_time_resolves_to_earlier_instant(): void
    {
        // 02:30 occurs twice on 2026-10-25 (CEST then CET). Earlier = 00:30Z.
        $this->assertSame('2026-10-25T00:30:00+00:00', LocalTime::at('2026-10-25', '02:30', 'Europe/Paris')->toIso8601String());
    }

    public function test_explicit_offset_is_honored(): void
    {
        $this->assertSame('2026-05-01T16:00:00+00:00', LocalTime::parseLocal('2026-05-01T18:00:00+02:00', 'Europe/Paris')->toIso8601String());
    }

    /** @return array<string, array{string, bool}> */
    public static function quietCases(): array
    {
        return [
            'late evening' => ['2026-06-01 22:30', true],
            'just before end' => ['2026-06-02 07:59', true],
            'at end' => ['2026-06-02 08:00', false],
            'afternoon' => ['2026-06-02 15:00', false],
            'just before start' => ['2026-06-02 21:59', false],
            'midnight' => ['2026-06-02 00:00', true],
        ];
    }

    #[DataProvider('quietCases')]
    public function test_overnight_quiet_hours(string $local, bool $expected): void
    {
        $instant = LocalTime::parseLocal($local, 'Europe/Paris');
        $this->assertSame($expected, LocalTime::inQuietHours($instant, 'Europe/Paris', '22:00', '08:00'));
    }

    public function test_quiet_hours_end_across_midnight_and_dst(): void
    {
        $instant = LocalTime::parseLocal('2026-10-24 23:30', 'Europe/Paris'); // night of fall-back
        $end = LocalTime::quietHoursEnd($instant, 'Europe/Paris', '22:00', '08:00');
        $this->assertSame('2026-10-25 08:00', $end->setTimezone('Europe/Paris')->format('Y-m-d H:i'));
        $this->assertSame('2026-10-25T07:00:00+00:00', $end->toIso8601String());
    }

    public function test_rejects_invalid_input(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        LocalTime::at('2026-02-30', '09:00', 'Europe/Paris');
    }

    public function test_timezone_validation(): void
    {
        $this->assertTrue(LocalTime::isValidTimezone('Europe/Paris'));
        $this->assertFalse(LocalTime::isValidTimezone('Mars/Olympus'));
        $this->assertFalse(LocalTime::isValidClock('25:00'));
        $this->assertTrue(LocalTime::isValidClock('08:30'));
        unset($ignored);
        $this->assertInstanceOf(CarbonImmutable::class, LocalTime::endOfLocalDay('2026-05-01', 'Europe/Paris'));
    }
}
