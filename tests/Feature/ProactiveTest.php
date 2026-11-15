<?php

namespace Tests\Feature;

use App\Agent\OpenAI\LlmException;
use App\Domain\CheckpointService;
use App\Domain\ProgressService;
use App\Enums\Actor;
use App\Enums\CheckpointKind;
use App\Enums\CheckpointState;
use App\Enums\Commitment;
use App\Enums\MessageSource;
use App\Models\Checkpoint;
use App\Models\Message;
use App\Models\NotificationLog;
use App\Models\Task;
use App\Proactive\FollowUpDispatcher;
use App\Telegram\TelegramResult;
use Carbon\CarbonImmutable;
use Tests\Fakes\FakeLlmClient;
use Tests\TestCase;

class ProactiveTest extends TestCase
{
    private function checkpoints(): CheckpointService
    {
        return app(CheckpointService::class);
    }

    private function runDispatcher(): void
    {
        $this->artisan('companion:dispatch-checkpoints')->assertSuccessful();
    }

    /** Scenario 4a: context-aware check-in during allowed hours. */
    public function test_due_checkin_sends_context_aware_message_with_buttons(): void
    {
        $this->travelToLocal('2026-11-12 14:00');
        $user = $this->makeUser();
        $task = Task::factory()->for($user)->committed()->create(['title' => 'Deploy Laravel app', 'due_at' => CarbonImmutable::parse('2026-11-12 18:00', 'Europe/Paris')]);
        $this->checkpoints()->planForTask($task);

        $this->llm->push('proactive', function ($request) {
            $this->assertStringContainsString('Deploy Laravel app', $request->messages[1]['content']);

            return FakeLlmClient::json(['send' => true, 'message' => 'How is the Laravel deploy going? Anything I can help unblock before 18:00?', 'reason' => 'pre-deadline']);
        });
        $this->travelToLocal('2026-11-12 16:01');
        $this->runDispatcher();

        $sent = $this->telegram->sent();
        $this->assertCount(1, $sent);
        $this->assertStringContainsString('Laravel deploy', $sent[0]['text']);
        $this->assertSame("t:{$task->id}:d", $sent[0]['reply_markup']['inline_keyboard'][0][0]['callback_data']);
        $cp = Checkpoint::where('kind', 'deadline_check')->firstOrFail();
        $this->assertSame(CheckpointState::Sent, $cp->state);
        $this->assertTrue(Message::where('source', MessageSource::Proactive->value)->where('checkpoint_id', $cp->id)->exists());
    }

    /** Scenario 4b: a recently completed task suppresses stale check-ins. */
    public function test_completed_task_gets_no_obsolete_reminder(): void
    {
        $this->travelToLocal('2026-11-12 14:00');
        $user = $this->makeUser();
        $task = Task::factory()->for($user)->committed()->create(['due_at' => CarbonImmutable::parse('2026-11-12 18:00', 'Europe/Paris')]);
        $this->checkpoints()->planForTask($task);
        $this->assertSame(2, $task->checkpoints()->where('state', 'pending')->count());

        // Completed via button.
        $this->webhook($this->callbackUpdate("t:{$task->id}:d"))->assertOk();
        $this->assertSame(0, $task->checkpoints()->where('state', 'pending')->count());

        $this->telegram->calls = [];
        $this->travelToLocal('2026-11-12 19:00');
        $this->runDispatcher();
        $this->assertSame([], $this->telegram->sent());
    }

    public function test_task_closed_after_claim_is_suppressed_at_send_time(): void
    {
        $this->travelToLocal('2026-11-12 15:00');
        $user = $this->makeUser();
        $task = Task::factory()->for($user)->committed()->create(['due_at' => now()->addHours(3)]);
        $cp = Checkpoint::factory()->for($user)->create(['task_id' => $task->id, 'kind' => CheckpointKind::DeadlineCheck, 'earliest_at' => now()->subMinute()]);
        $claim = $this->checkpoints()->claimDue()->first();
        // Task completes between claim and send (race): re-fetch at send time catches it.
        $task->update(['status' => 'done']);
        $outcome = app(FollowUpDispatcher::class)->dispatch($claim['id'], $claim['token']);
        $this->assertSame('suppressed:task_closed', $outcome);
        $this->assertSame([], $this->telegram->sent());
    }

    public function test_quiet_hours_defer_to_morning(): void
    {
        $this->travelToLocal('2026-11-12 23:10');
        $user = $this->makeUser();
        Checkpoint::factory()->for($user)->create(['kind' => CheckpointKind::Promised, 'reason' => 'Check gym', 'earliest_at' => now()->subMinutes(5), 'latest_at' => now()->addHours(12)]);
        $this->runDispatcher();
        $this->assertSame([], $this->telegram->sent());
        $cp = Checkpoint::firstOrFail();
        $this->assertSame(CheckpointState::Pending, $cp->state);
        $this->assertSame('2026-11-13 08:00', $cp->earliest_at->setTimezone('Europe/Paris')->format('Y-m-d H:i'));
        $this->assertStringContainsString('quiet_hours', $cp->outcome_reason);

        $this->travelToLocal('2026-11-13 08:01');
        $this->runDispatcher();
        $this->assertCount(1, $this->telegram->sent());
    }

    /** Scenario 10: rituals at 09:00 / 20:00 Paris, DST-safe. */
    public function test_rituals_scheduled_at_local_times_across_dst(): void
    {
        $user = $this->makeUser();
        $this->travelTo(CarbonImmutable::parse('2026-10-24 06:00', 'UTC'));
        $this->artisan('companion:plan-rituals')->assertSuccessful();
        $rows = Checkpoint::orderBy('earliest_at')->get();
        $this->assertSame(4, $rows->count()); // today + tomorrow, morning + evening
        $utc = $rows->map(fn ($c) => $c->earliest_at->utc()->format('m-d H:i'))->all();
        // Oct 24 is CEST (UTC+2); Oct 25 after fall-back is CET (UTC+1).
        $this->assertSame(['10-24 07:00', '10-24 18:00', '10-25 08:00', '10-25 19:00'], $utc);
        foreach ($rows as $row) {
            $this->assertContains($row->earliest_at->setTimezone('Europe/Paris')->format('H:i'), ['09:00', '20:00']);
        }
        // Idempotent re-planning.
        $this->artisan('companion:plan-rituals')->assertSuccessful();
        $this->assertSame(4, Checkpoint::count());
    }

    public function test_morning_planning_distinguishes_confirmed_from_planned(): void
    {
        $this->travelToLocal('2026-11-12 18:00');
        $user = $this->makeUser();
        $done = Task::factory()->for($user)->create(['title' => 'Write migration', 'planned_for_date' => '2026-11-12']);
        app(ProgressService::class)->complete($user, $done, Actor::User, null, 'button');
        Task::factory()->for($user)->create(['title' => 'Review PR', 'planned_for_date' => '2026-11-13']);

        $this->travelToLocal('2026-11-13 08:00');
        $this->artisan('companion:plan-rituals');
        $this->llm->push('proactive', function ($request) {
            $facts = $request->messages[1]['content'];
            $this->assertStringContainsString('"yesterday_confirmed_done": [', $facts);
            $this->assertStringContainsString('Write migration', $facts);
            $this->assertStringContainsString('Review PR', $facts);

            return FakeLlmClient::json(['send' => true, 'message' => 'Morning! Yesterday you finished the migration. Today: Review PR. Top priority?', 'reason' => 'ritual']);
        });
        $this->travelToLocal('2026-11-13 09:00');
        $this->runDispatcher();
        $this->assertStringContainsString('Top priority', $this->telegram->lastText());
        $this->assertSame(CheckpointState::Sent, Checkpoint::where('kind', 'morning_planning')->whereDate('local_date', '2026-11-13')->first()->state);
    }

    public function test_ritual_falls_back_to_template_when_llm_fails(): void
    {
        $this->travelToLocal('2026-11-12 19:59');
        $user = $this->makeUser();
        $this->artisan('companion:plan-rituals');
        $this->llm->push('proactive', new LlmException('down', true));
        $this->travelToLocal('2026-11-12 20:00');
        $this->runDispatcher();
        $this->assertStringContainsString('Evening check-in', $this->telegram->lastText());
    }

    /** Scenario 11: concurrent workers cannot double-claim or double-send. */
    public function test_concurrent_claims_and_duplicate_dispatch_send_once(): void
    {
        $this->travelToLocal('2026-11-12 11:00');
        $user = $this->makeUser();
        $cp = Checkpoint::factory()->for($user)->create(['kind' => CheckpointKind::Promised, 'earliest_at' => now()->subMinute()]);

        $first = $this->checkpoints()->claimDue();
        $second = $this->checkpoints()->claimDue(); // a second scheduler/worker racing
        $this->assertCount(1, $first);
        $this->assertCount(0, $second);

        $dispatcher = app(FollowUpDispatcher::class);
        $this->assertSame('sent', $dispatcher->dispatch($cp->id, $first[0]['token']));
        $this->assertSame('not_owner', $dispatcher->dispatch($cp->id, $first[0]['token'])); // duplicate job delivery
        $this->assertSame('not_owner', $dispatcher->dispatch($cp->id, 'forged-token'));
        $this->assertCount(1, $this->telegram->sent());
        $this->assertSame(1, NotificationLog::where('idempotency_key', 'checkpoint:'.$cp->id)->count());
    }

    public function test_stale_claim_recovery_never_resends_a_delivered_message(): void
    {
        $this->travelToLocal('2026-11-12 11:00');
        $user = $this->makeUser();
        $cp = Checkpoint::factory()->for($user)->create(['kind' => CheckpointKind::Promised, 'earliest_at' => now()->subMinute()]);
        // Simulate: worker A delivered, then crashed before marking the checkpoint sent.
        NotificationLog::create(['user_id' => $user->id, 'checkpoint_id' => $cp->id, 'kind' => 'proactive_adaptive', 'idempotency_key' => 'checkpoint:'.$cp->id, 'status' => 'sent', 'sent_at' => now()]);
        $claim = $this->checkpoints()->claimDue()->first();
        $this->travel(10)->minutes();
        $this->runDispatcher(); // recovers the stale claim and re-dispatches
        $this->assertSame([], $this->telegram->sent());
        $this->assertSame(CheckpointState::Sent, $cp->refresh()->state);
        $this->assertNotNull($claim);
    }

    public function test_telegram_rate_limit_defers_then_delivers(): void
    {
        $this->travelToLocal('2026-11-12 11:00');
        $user = $this->makeUser();
        Checkpoint::factory()->for($user)->create(['kind' => CheckpointKind::Promised, 'earliest_at' => now()->subMinute()]);
        $this->telegram->fail('sendMessage', new TelegramResult(false, null, 429, 'Too Many Requests', 30));
        $this->runDispatcher();
        $cp = Checkpoint::firstOrFail();
        $this->assertSame(CheckpointState::Pending, $cp->state);
        $this->travel(1)->minutes();
        $this->runDispatcher();
        $this->assertSame(CheckpointState::Sent, $cp->refresh()->state);
        $this->assertSame(2, collect($this->telegram->calls)->where('method', 'sendMessage')->count());
    }

    public function test_unanswered_adaptive_followup_is_not_repeated(): void
    {
        $this->travelToLocal('2026-11-12 10:00');
        $user = $this->makeUser(111, ['last_user_message_at' => now()->subHours(3)]);
        Checkpoint::factory()->for($user)->create(['kind' => CheckpointKind::Promised, 'earliest_at' => now()->subMinute(), 'idempotency_key' => 'a']);
        $this->runDispatcher();
        $this->assertCount(1, $this->telegram->sent());

        $this->travel(3)->hours();
        Checkpoint::factory()->for($user)->create(['kind' => CheckpointKind::Promised, 'earliest_at' => now()->subMinute(), 'idempotency_key' => 'b']);
        $this->runDispatcher();
        $this->assertCount(1, $this->telegram->sent());
        $this->assertSame('awaiting_reply_to_previous', Checkpoint::where('idempotency_key', 'b')->first()->outcome_reason);
    }

    public function test_min_gap_and_daily_cap(): void
    {
        $this->travelToLocal('2026-11-12 10:00');
        $user = $this->makeUser();
        $user->settingsOrDefault()->update(['max_checkins_per_day' => 1, 'min_gap_minutes' => 60]);
        $user->forceFill(['last_proactive_at' => now()->subMinutes(10), 'last_user_message_at' => now()->subMinutes(30)])->save();
        Checkpoint::factory()->for($user)->create(['kind' => CheckpointKind::Promised, 'earliest_at' => now()->subMinute(), 'idempotency_key' => 'gap']);
        $this->runDispatcher();
        $cp = Checkpoint::where('idempotency_key', 'gap')->first();
        $this->assertSame(CheckpointState::Pending, $cp->state);
        $this->assertSame('10:50', $cp->earliest_at->setTimezone('Europe/Paris')->format('H:i'));

        NotificationLog::create(['user_id' => $user->id, 'kind' => 'proactive_adaptive', 'idempotency_key' => 'prior', 'status' => 'sent', 'sent_at' => now()]);
        $this->travelToLocal('2026-11-12 10:51');
        $this->runDispatcher();
        $this->assertSame('daily_cap', $cp->refresh()->outcome_reason);
        $this->assertSame([], $this->telegram->sent());
    }

    public function test_paused_user_receives_nothing_and_ideas_are_never_chased(): void
    {
        $this->travelToLocal('2026-11-12 10:00');
        $user = $this->makeUser();
        $idea = Task::factory()->for($user)->create(['commitment' => Commitment::Idea]);
        Checkpoint::factory()->for($user)->create(['task_id' => $idea->id, 'earliest_at' => now()->subMinute(), 'idempotency_key' => 'idea']);
        $this->webhook($this->textUpdate('/pause'))->assertOk();
        $this->telegram->calls = [];
        $this->travel(2)->hours();
        Checkpoint::factory()->for($user)->create(['kind' => CheckpointKind::MorningPlanning, 'earliest_at' => now()->subMinute(), 'idempotency_key' => 'ritual']);
        $this->runDispatcher();
        $this->assertSame([], $this->telegram->sent());
        $this->assertSame('user_paused', Checkpoint::where('idempotency_key', 'ritual')->first()->outcome_reason);
    }

    public function test_active_conversation_defers_adaptive_checkin(): void
    {
        $this->travelToLocal('2026-11-12 10:00');
        $user = $this->makeUser(111, ['last_user_message_at' => now()->subMinutes(3)]);
        Checkpoint::factory()->for($user)->create(['kind' => CheckpointKind::Promised, 'earliest_at' => now()->subMinute()]);
        $this->runDispatcher();
        $this->assertSame([], $this->telegram->sent());
        $this->assertStringContainsString('active_conversation', Checkpoint::first()->outcome_reason);
    }
}
