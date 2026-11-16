<?php

declare(strict_types=1);

namespace App\Agent\Tools;

use App\Agent\ToolContext;
use App\Agent\ToolResult;
use App\Models\CalendarEvent;
use App\Support\LocalTime;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;

/**
 * Creates a calendar event for the current user. The event is delivered after the reply as an
 * .ics file in Telegram, which the user opens to add it to Apple/Google/Outlook calendar.
 * No calendar credentials are involved: the bot never writes into anyone's calendar directly.
 */
final class CreateCalendarEventTool extends BaseTool
{
    private const DEFAULT_DURATION_MINUTES = 60;

    private const MAX_DURATION_DAYS = 31;

    public function name(): string
    {
        return 'create_calendar_event';
    }

    public function description(): string
    {
        return 'Create a calendar event the user explicitly asked to add to their calendar (Apple/Google/Outlook). '
            .'start/end: local "YYYY-MM-DDTHH:MM" for a timed event, or "YYYY-MM-DD" for an all-day event (both must use the same form; for all-day, end is the last day, inclusive). '
            .'If end is null, duration_minutes is used (default 60). The user receives an .ics file right after your reply and must open it to add the event: say so, and never claim the event is already in their calendar.';
    }

    public function parameters(): array
    {
        return $this->object([
            'title' => $this->string('Short event title'),
            'start' => $this->string('Local start "YYYY-MM-DDTHH:MM", or "YYYY-MM-DD" for all-day'),
            'end' => $this->string('Local end in the same form as start, or null', true),
            'duration_minutes' => $this->integer('Duration in minutes when end is null (timed events only), or null', true),
            'location' => $this->string('Place or meeting link, or null', true),
            'notes' => $this->string('Extra details for the event description, or null', true),
            'alert_minutes_before' => $this->integer('Reminder N minutes before start (0 = at start), or null for none', true),
        ]);
    }

    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'min:1', 'max:200'],
            'start' => ['required', 'string', 'max:40'],
            'end' => ['nullable', 'string', 'max:40'],
            'duration_minutes' => ['nullable', 'integer', 'min:1', 'max:'.(self::MAX_DURATION_DAYS * 1440)],
            'location' => ['nullable', 'string', 'max:300'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'alert_minutes_before' => ['nullable', 'integer', 'min:0', 'max:40320'],
        ];
    }

    public function handle(ToolContext $context, array $args): ToolResult
    {
        $tz = $context->user->timezone;
        [$startAt, $startDate] = $this->parseWhen($args['start'], $tz);
        [$endAt, $endDate] = $this->parseWhen($args['end'] ?? null, $tz);
        if ($startAt === null && $startDate === null) {
            return ToolResult::error('A start time is required.');
        }
        $allDay = $startDate !== null;
        if (($allDay && $endAt !== null) || (! $allDay && $endDate !== null)) {
            return ToolResult::error('start and end must both be date-only or both include a time.');
        }

        $attrs = $allDay
            ? $this->allDay($startDate, $endDate, $tz)
            : $this->timed($startAt, $endAt, $args['duration_minutes'] ?? null, $context->now);
        if (is_string($attrs)) {
            return ToolResult::error($attrs);
        }

        $title = trim((string) $args['title']);
        $uid = $this->uid($context, $title, $args['start']);
        $event = CalendarEvent::query()->where('uid', $uid)->first();
        $created = false;
        if ($event === null) {
            try {
                $event = CalendarEvent::create($attrs + [
                    'user_id' => $context->user->id,
                    'source_message_id' => $context->sourceMessageId(),
                    'uid' => $uid,
                    'title' => $title,
                    'all_day' => $allDay,
                    'timezone' => $tz,
                    'location' => $this->clean($args['location'] ?? null),
                    'notes' => $this->clean($args['notes'] ?? null),
                    'alert_minutes_before' => $args['alert_minutes_before'] ?? null,
                ]);
                $created = true;
            } catch (QueryException) {
                $event = CalendarEvent::query()->where('uid', $uid)->firstOrFail();
            }
        }

        return ToolResult::ok([
            'created' => $created,
            'event' => $this->describe($event),
            'delivery' => 'An .ics file is sent right after your reply. The user must open it and tap "Add" to put the event in their calendar.',
        ]);
    }

    /** @return array<string, mixed>|string attributes, or an error message */
    private function timed(CarbonImmutable $start, ?CarbonImmutable $end, ?int $duration, CarbonImmutable $now): array|string
    {
        if ($start->lessThan($now->subMinutes(5))) {
            return 'That start time is in the past. Ask the user for the intended time.';
        }
        $end ??= $start->addMinutes($duration ?? self::DEFAULT_DURATION_MINUTES);
        if (! $end->greaterThan($start)) {
            return 'The event must end after it starts.';
        }
        if ($end->greaterThan($start->addDays(self::MAX_DURATION_DAYS))) {
            return 'Events longer than '.self::MAX_DURATION_DAYS.' days are not supported.';
        }

        return ['starts_at' => $start, 'ends_at' => $end];
    }

    /** @return array<string, mixed>|string */
    private function allDay(string $startDate, ?string $lastDate, string $tz): array|string
    {
        if ($startDate < LocalTime::today($tz)) {
            return 'That date is in the past. Ask the user for the intended date.';
        }
        $start = CarbonImmutable::parse($startDate);
        $last = CarbonImmutable::parse($lastDate ?? $startDate);
        if ($last->lessThan($start)) {
            return 'The event must end on or after its start date.';
        }
        if ($last->diffInDays($start, true) >= self::MAX_DURATION_DAYS) {
            return 'Events longer than '.self::MAX_DURATION_DAYS.' days are not supported.';
        }

        return ['start_date' => $start->toDateString(), 'end_date' => $last->addDay()->toDateString()];
    }

    /** Stable per (source message, title, start): a retried turn never yields a second event. */
    private function uid(ToolContext $context, string $title, string $start): string
    {
        $anchor = $context->sourceMessageId() !== null ? 'm'.$context->sourceMessageId() : 'r'.$context->run->id;
        $hash = substr(hash('sha256', $context->user->id.'|'.$anchor.'|'.mb_strtolower($title).'|'.trim($start)), 0, 40);

        return $hash.'@companion';
    }

    private function clean(?string $value): ?string
    {
        $value = $value !== null ? trim($value) : null;

        return $value === '' ? null : $value;
    }

    /** @return array<string, mixed> */
    private function describe(CalendarEvent $event): array
    {
        return [
            'id' => $event->id,
            'title' => $event->title,
            'all_day' => $event->all_day,
            'start' => $event->all_day ? $event->start_date->toDateString() : LocalTime::human($event->starts_at, $event->timezone),
            'end' => $event->all_day ? $event->end_date->subDay()->toDateString() : LocalTime::human($event->ends_at, $event->timezone),
            'location' => $event->location,
            'alert_minutes_before' => $event->alert_minutes_before,
        ];
    }
}
