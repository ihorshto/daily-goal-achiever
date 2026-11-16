<?php

namespace Tests\Unit;

use App\Calendar\IcsBuilder;
use App\Models\CalendarEvent;
use Carbon\CarbonImmutable;
use Tests\TestCase;

class IcsBuilderTest extends TestCase
{
    private function event(array $attrs = []): CalendarEvent
    {
        return (new CalendarEvent)->forceFill(array_merge([
            'uid' => 'abc@companion',
            'title' => 'Demo',
            'all_day' => false,
            'starts_at' => CarbonImmutable::parse('2026-11-13 15:00', 'Europe/Kyiv'),
            'ends_at' => CarbonImmutable::parse('2026-11-13 16:30', 'Europe/Kyiv'),
            'timezone' => 'Europe/Kyiv',
            'sequence' => 0,
        ], $attrs));
    }

    public function test_times_are_written_in_utc_with_crlf_line_endings(): void
    {
        $ics = (new IcsBuilder)->build($this->event(), CarbonImmutable::parse('2026-11-12 09:00:00', 'UTC'));

        $this->assertStringStartsWith("BEGIN:VCALENDAR\r\nVERSION:2.0\r\n", $ics);
        $this->assertStringEndsWith("END:VEVENT\r\nEND:VCALENDAR\r\n", $ics);
        $this->assertStringContainsString("DTSTART:20261113T130000Z\r\n", $ics); // 15:00 EET
        $this->assertStringContainsString("DTEND:20261113T143000Z\r\n", $ics);
        $this->assertStringContainsString("DTSTAMP:20261112T090000Z\r\n", $ics);
        $this->assertDoesNotMatchRegularExpression("/(?<!\r)\n/", $ics);
    }

    public function test_text_is_escaped_and_injection_is_impossible(): void
    {
        $ics = (new IcsBuilder)->build($this->event([
            'title' => 'Plan; review, ship\\done',
            'notes' => "line1\r\nEND:VEVENT\nBEGIN:VEVENT",
        ]));

        $this->assertStringContainsString('SUMMARY:Plan\; review\, ship\\\\done', $ics);
        $this->assertStringContainsString('DESCRIPTION:line1\nEND:VEVENT\nBEGIN:VEVENT', $ics);
        $this->assertSame(1, substr_count($ics, "\r\nBEGIN:VEVENT\r\n"));
    }

    public function test_long_utf8_lines_are_folded_at_75_octets_without_breaking_characters(): void
    {
        $title = str_repeat('Довга назва події українською ', 6);
        $ics = (new IcsBuilder)->build($this->event(['title' => $title]));

        foreach (explode("\r\n", rtrim($ics, "\r\n")) as $line) {
            $this->assertLessThanOrEqual(75, strlen($line));
            $this->assertTrue(mb_check_encoding($line, 'UTF-8'));
        }
        $this->assertStringContainsString('SUMMARY:'.$title, str_replace("\r\n ", '', $ics));
    }

    public function test_filename_is_ascii_with_fallback(): void
    {
        $builder = new IcsBuilder;
        $this->assertMatchesRegularExpression('/^[a-z0-9-]+\.ics$/', $builder->filename($this->event(['title' => 'Зустріч з Олегом'])));
        $this->assertSame('event.ics', $builder->filename($this->event(['title' => '🎉🎉'])));
    }
}
