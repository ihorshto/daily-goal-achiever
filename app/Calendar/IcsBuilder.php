<?php

declare(strict_types=1);

namespace App\Calendar;

use App\Models\CalendarEvent;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

/**
 * Renders a CalendarEvent as an RFC 5545 iCalendar document (one VEVENT).
 * Timed events are written in UTC ("Z"), so every client shows them in its own local zone;
 * all-day events use VALUE=DATE with an exclusive end date.
 */
final class IcsBuilder
{
    public function build(CalendarEvent $event, ?CarbonImmutable $stamp = null): string
    {
        $stamp ??= CarbonImmutable::now();
        $lines = [
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:-//AI Companion//Telegram Bot//EN',
            'CALSCALE:GREGORIAN',
            'METHOD:PUBLISH',
            'BEGIN:VEVENT',
            'UID:'.$this->escape($event->uid),
            'DTSTAMP:'.$this->utc($stamp),
            'SEQUENCE:'.$event->sequence,
        ];

        if ($event->all_day) {
            $lines[] = 'DTSTART;VALUE=DATE:'.$event->start_date->format('Ymd');
            $lines[] = 'DTEND;VALUE=DATE:'.$event->end_date->format('Ymd');
        } else {
            $lines[] = 'DTSTART:'.$this->utc($event->starts_at);
            $lines[] = 'DTEND:'.$this->utc($event->ends_at);
        }

        $lines[] = 'SUMMARY:'.$this->escape($event->title);
        if ($event->location !== null && $event->location !== '') {
            $lines[] = 'LOCATION:'.$this->escape($event->location);
        }
        if ($event->notes !== null && $event->notes !== '') {
            $lines[] = 'DESCRIPTION:'.$this->escape($event->notes);
        }

        if ($event->alert_minutes_before !== null) {
            $lines[] = 'BEGIN:VALARM';
            $lines[] = 'ACTION:DISPLAY';
            $lines[] = 'DESCRIPTION:'.$this->escape($event->title);
            $lines[] = 'TRIGGER:'.($event->alert_minutes_before === 0 ? 'PT0M' : '-PT'.$event->alert_minutes_before.'M');
            $lines[] = 'END:VALARM';
        }

        $lines[] = 'END:VEVENT';
        $lines[] = 'END:VCALENDAR';

        return implode("\r\n", array_map($this->fold(...), $lines))."\r\n";
    }

    /** A safe ASCII file name derived from the title, e.g. "zustrich-z-olegom.ics". */
    public function filename(CalendarEvent $event): string
    {
        $slug = Str::limit(Str::slug($event->title), 60, '');

        return ($slug !== '' ? $slug : 'event').'.ics';
    }

    private function utc(CarbonImmutable $instant): string
    {
        return $instant->utc()->format('Ymd\THis\Z');
    }

    /** RFC 5545 §3.3.11 TEXT escaping. */
    private function escape(string $value): string
    {
        $value = str_replace(["\r\n", "\r"], "\n", $value);
        $value = preg_replace('/[\x00-\x08\x0B-\x1F\x7F]/u', '', $value) ?? '';

        return str_replace(['\\', ';', ',', "\n"], ['\\\\', '\\;', '\\,', '\\n'], $value);
    }

    /** Fold lines longer than 75 octets without splitting a multi-byte UTF-8 character. */
    private function fold(string $line): string
    {
        if (strlen($line) <= 75) {
            return $line;
        }
        $out = [];
        $current = '';
        $limit = 75;
        foreach (mb_str_split($line) as $char) {
            if (strlen($current) + strlen($char) > $limit) {
                $out[] = $current;
                $current = '';
                $limit = 74; // continuation lines start with a space
            }
            $current .= $char;
        }
        $out[] = $current;

        return implode("\r\n ", $out);
    }
}
