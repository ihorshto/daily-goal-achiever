<?php

namespace Tests\Feature;

use App\Jobs\SendCalendarInvite;
use App\Memory\PersonalDataEraser;
use App\Models\CalendarEvent;
use App\Models\User;
use App\Telegram\TelegramResult;
use Carbon\CarbonImmutable;
use Tests\Fakes\FakeLlmClient;
use Tests\TestCase;

class CalendarEventTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // Thursday 2026-11-12 10:00 Paris (CET, UTC+1)
        $this->travelTo(CarbonImmutable::parse('2026-11-12 09:00:00', 'UTC'));
    }

    private function eventArgs(array $overrides = []): array
    {
        return array_merge([
            'title' => 'Зустріч з Олегом',
            'start' => '2026-11-13T15:00',
            'end' => null,
            'duration_minutes' => null,
            'location' => 'Кафе "Львів", вул. Шевченка 1',
            'notes' => null,
            'alert_minutes_before' => 15,
        ], $overrides);
    }

    public function test_timed_event_is_sent_as_ics_after_the_reply(): void
    {
        $this->llm->push('agent',
            FakeLlmClient::tools(['name' => 'create_calendar_event', 'args' => $this->eventArgs()]),
            FakeLlmClient::text('Готово: надсилаю файл події, відкрий його, щоб додати в календар.'),
        );

        $this->webhook($this->textUpdate('Додай в календар зустріч з Олегом завтра о 15:00'))->assertOk();

        $event = CalendarEvent::sole();
        $this->assertSame('2026-11-13T14:00:00+00:00', $event->starts_at->toIso8601String()); // 15:00 CET
        $this->assertSame('2026-11-13T15:00:00+00:00', $event->ends_at->toIso8601String());  // default 60 min
        $this->assertNotNull($event->sent_at);

        $methods = array_values(array_filter(array_column($this->telegram->calls, 'method'), fn ($m) => in_array($m, ['sendMessage', 'sendDocument'], true)));
        $this->assertSame(['sendMessage', 'sendDocument'], $methods, 'Reply first, then the file');

        $doc = $this->telegram->documents()[0];
        $this->assertSame('111', $doc['chat_id']);
        $this->assertSame('text/calendar', $doc['document']['mime']);
        $this->assertStringEndsWith('.ics', $doc['document']['filename']);
        $this->assertStringContainsString('Зустріч з Олегом', $doc['caption']);

        $ics = $doc['document']['contents'];
        $this->assertStringContainsString("DTSTART:20261113T140000Z\r\n", $ics);
        $this->assertStringContainsString("DTEND:20261113T150000Z\r\n", $ics);
        $this->assertStringContainsString("TRIGGER:-PT15M\r\n", $ics);
        $this->assertStringContainsString('UID:'.$event->uid, $ics);
        $this->assertStringContainsString('LOCATION:Кафе "Львів"\, вул. Шевченка 1', str_replace("\r\n ", '', $ics));
    }

    public function test_multi_day_all_day_event_uses_exclusive_end_date(): void
    {
        $this->llm->push('agent',
            FakeLlmClient::tools(['name' => 'create_calendar_event', 'args' => $this->eventArgs([
                'title' => 'Відпустка', 'start' => '2026-11-20', 'end' => '2026-11-22', 'location' => null, 'alert_minutes_before' => null,
            ])]),
            FakeLlmClient::text('Надсилаю подію.'),
        );

        $this->webhook($this->textUpdate('Відпустка з 20 по 22 листопада, додай в календар'))->assertOk();

        $ics = $this->telegram->documents()[0]['document']['contents'];
        $this->assertStringContainsString("DTSTART;VALUE=DATE:20261120\r\n", $ics);
        $this->assertStringContainsString("DTEND;VALUE=DATE:20261123\r\n", $ics);
        $this->assertStringNotContainsString('VALARM', $ics);
    }

    public function test_invalid_times_are_rejected_without_creating_an_event(): void
    {
        $this->llm->push('agent',
            FakeLlmClient::tools(
                ['name' => 'create_calendar_event', 'args' => $this->eventArgs(['start' => '2026-11-11T15:00'])],
                ['name' => 'create_calendar_event', 'args' => $this->eventArgs(['start' => '2026-11-13', 'end' => '2026-11-13T18:00'])],
                ['name' => 'create_calendar_event', 'args' => $this->eventArgs(['end' => '2026-11-13T14:00'])],
            ),
            FakeLlmClient::text('Уточни, будь ласка, час.'),
        );

        $this->webhook($this->textUpdate('Додай подію'))->assertOk();

        $this->assertSame(0, CalendarEvent::count());
        $this->assertSame([], $this->telegram->documents());
        $tools = array_values(array_filter($this->llm->requestsFor('agent')[1]->messages, fn ($m) => $m['role'] === 'tool'));
        $this->assertStringContainsString('in the past', $tools[0]['content']);
        $this->assertStringContainsString('both be date-only', $tools[1]['content']);
        $this->assertStringContainsString('end after it starts', $tools[2]['content']);
    }

    public function test_invite_is_delivered_once_and_retried_after_a_transient_failure(): void
    {
        $user = $this->makeUser();
        $event = CalendarEvent::create([
            'user_id' => $user->id, 'uid' => 'abc@companion', 'title' => 'Call', 'all_day' => false,
            'starts_at' => now()->addDay(), 'ends_at' => now()->addDay()->addHour(), 'timezone' => 'Europe/Paris',
        ]);

        $this->telegram->fail('sendDocument', new TelegramResult(false, null, 500, 'Internal Server Error'));
        SendCalendarInvite::dispatchSync($event->id);
        $this->assertNull($event->refresh()->sent_at);

        SendCalendarInvite::dispatchSync($event->id); // the queue's retry
        SendCalendarInvite::dispatchSync($event->id); // a duplicate dispatch
        $this->assertNotNull($event->refresh()->sent_at);
        $this->assertCount(2, $this->telegram->documents(), 'One failed attempt + one successful send, no duplicates');
    }

    public function test_events_are_scoped_per_user_and_erased_with_their_data(): void
    {
        $this->llm->push('agent',
            FakeLlmClient::tools(['name' => 'create_calendar_event', 'args' => $this->eventArgs()]),
            FakeLlmClient::text('Ok.'),
        );
        $this->webhook($this->textUpdate('Додай зустріч', 222))->assertOk();

        $owner = User::where('telegram_user_id', 222)->sole();
        $event = CalendarEvent::sole();
        $this->assertSame($owner->id, $event->user_id);
        $this->assertSame('222', $this->telegram->documents()[0]['chat_id']);

        app(PersonalDataEraser::class)->eraseAll($owner);
        $this->assertSame(0, CalendarEvent::count());
    }
}
