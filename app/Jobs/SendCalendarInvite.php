<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Calendar\IcsBuilder;
use App\Models\CalendarEvent;
use App\Support\LocalTime;
use App\Telegram\OutboundMessenger;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Sends a calendar event to its owner as an .ics document. Chained after the text reply so the
 * file arrives second. Idempotent via notification_log key "ics:{id}:{sequence}".
 */
class SendCalendarInvite implements ShouldQueue
{
    use Queueable;

    public int $tries = 8;

    public function __construct(public int $calendarEventId)
    {
        $this->onQueue('high');
    }

    public function handle(OutboundMessenger $messenger, IcsBuilder $ics): void
    {
        $event = CalendarEvent::query()->with('user')->find($this->calendarEventId);
        if ($event === null || $event->sent_at !== null) {
            return;
        }

        $outcome = $messenger->deliverDocument(
            $event->user,
            $ics->filename($event),
            $ics->build($event),
            'text/calendar',
            $this->caption($event),
            'calendar',
            'ics:'.$event->id.':'.$event->sequence,
        );
        if ($outcome->delivered()) {
            $event->forceFill(['telegram_message_id' => $outcome->telegramMessageId, 'sent_at' => now()])->save();

            return;
        }
        if ($outcome->shouldRetry()) {
            $this->release($outcome->retryAfter ?? min(300, 5 * 2 ** $this->attempts()));
        }
    }

    /** Language-neutral caption: the reply text before it already explains what to do. */
    private function caption(CalendarEvent $event): string
    {
        if ($event->all_day) {
            $last = $event->end_date->subDay();
            $when = $event->start_date->toDateString().($last->equalTo($event->start_date) ? '' : ' – '.$last->toDateString());
        } else {
            $sameDay = LocalTime::localDate($event->starts_at, $event->timezone) === LocalTime::localDate($event->ends_at, $event->timezone);
            $when = LocalTime::human($event->starts_at, $event->timezone).' – '
                .($sameDay ? $event->ends_at->setTimezone($event->timezone)->format('H:i') : LocalTime::human($event->ends_at, $event->timezone));
        }

        return '📅 '.$event->title."\n🕒 ".$when.($event->location !== null ? "\n📍 ".$event->location : '');
    }
}
