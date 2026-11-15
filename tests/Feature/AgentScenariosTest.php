<?php

namespace Tests\Feature;

use App\Agent\AgentOrchestrator;
use App\Agent\ConversationService;
use App\Agent\OpenAI\ChatResponse;
use App\Agent\OpenAI\LlmException;
use App\Agent\OpenAI\ToolCall;
use App\Agent\ToolContext;
use App\Agent\ToolExecutor;
use App\Agent\UsageTracker;
use App\Domain\CheckpointService;
use App\Enums\CheckpointKind;
use App\Enums\Commitment;
use App\Enums\MessageSource;
use App\Enums\PendingActionStatus;
use App\Enums\TaskStatus;
use App\Models\ActivityEvent;
use App\Models\AgentRun;
use App\Models\Checkpoint;
use App\Models\PendingActionConfirmation;
use App\Models\Task;
use App\Models\ToolExecution;
use App\Models\User;
use Carbon\CarbonImmutable;
use Tests\Fakes\FakeLlmClient;
use Tests\TestCase;

class AgentScenariosTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // Thursday 2026-11-12 10:00 Paris (CET, UTC+1)
        $this->travelTo(CarbonImmutable::parse('2026-11-12 09:00:00', 'UTC'));
    }

    /** Scenario 1: definite task vs tentative idea. */
    public function test_tomorrow_definite_task_and_tentative_idea(): void
    {
        $this->llm->push('agent',
            FakeLlmClient::tools(
                ['name' => 'create_task', 'args' => ['title' => 'Fix authentication', 'commitment' => 'intention', 'priority' => null, 'due' => null, 'planned_for_date' => '2026-11-13', 'notes' => null, 'goal_id' => null, 'project' => null]],
            ),
            FakeLlmClient::tools(
                ['name' => 'create_task', 'args' => ['title' => 'Start Stripe integration', 'commitment' => 'idea', 'priority' => null, 'due' => null, 'planned_for_date' => '2026-11-13', 'notes' => 'Only if time allows', 'goal_id' => null, 'project' => null]],
            ),
            FakeLlmClient::text('Got it: fixing auth is on for tomorrow; Stripe is noted as a maybe.'),
        );

        $this->webhook($this->textUpdate('Tomorrow I want to fix authentication and maybe start Stripe integration.'))->assertOk();

        $auth = Task::where('title', 'Fix authentication')->firstOrFail();
        $stripe = Task::where('title', 'Start Stripe integration')->firstOrFail();
        $this->assertSame(Commitment::Intention, $auth->commitment);
        $this->assertSame('2026-11-13', $auth->planned_for_date->toDateString());
        $this->assertSame(Commitment::Idea, $stripe->commitment);
        $this->assertSame(0, $stripe->checkpoints()->count(), 'Tentative ideas are never chased');
        $this->assertNull($auth->due_at);
        $this->assertStringContainsString('maybe', $this->telegram->lastText());

        // The model saw tool results with ok=true before claiming success.
        $last = $this->llm->requestsFor('agent')[2]->messages;
        $toolMsgs = array_values(array_filter($last, fn ($m) => $m['role'] === 'tool'));
        $this->assertStringContainsString('"ok":true', $toolMsgs[0]['content']);
    }

    /** Scenario 2: "Deploy my Laravel app by 18:00" → committed task + follow-up plan. */
    public function test_deadline_task_creates_followup_plan(): void
    {
        $this->llm->push('agent',
            FakeLlmClient::tools(['name' => 'create_task', 'args' => ['title' => 'Deploy Laravel app', 'commitment' => 'commitment', 'priority' => 'high', 'due' => '2026-11-12T18:00', 'planned_for_date' => '2026-11-12', 'notes' => null, 'goal_id' => null, 'project' => null]]),
            FakeLlmClient::text('Deploy by 18:00 — I\'ll check in at 16:00.'),
        );
        $this->webhook($this->textUpdate('Deploy my Laravel app by 18:00.'))->assertOk();

        $task = Task::where('title', 'Deploy Laravel app')->firstOrFail();
        $this->assertSame(Commitment::Commitment, $task->commitment);
        $this->assertSame('2026-11-12T17:00:00+00:00', $task->due_at->toIso8601String()); // 18:00 CET
        $kinds = $task->checkpoints()->orderBy('earliest_at')->get();
        $this->assertSame([CheckpointKind::DeadlineCheck, CheckpointKind::TaskFollowUp], $kinds->pluck('kind')->all());
        $this->assertSame('16:00', $kinds[0]->earliest_at->setTimezone('Europe/Paris')->format('H:i'));
        $this->assertSame('18:20', $kinds[1]->earliest_at->setTimezone('Europe/Paris')->format('H:i'));
    }

    /** Scenario 3: Docker done, PostgreSQL blocked. */
    public function test_progress_report_records_completion_and_blocker(): void
    {
        $user = $this->makeUser();
        $docker = Task::factory()->for($user)->create(['title' => 'Set up Docker']);
        $pg = Task::factory()->for($user)->create(['title' => 'Configure PostgreSQL']);

        $this->llm->push('agent',
            FakeLlmClient::tools(['name' => 'complete_task', 'args' => ['task_id' => $docker->id, 'evidence' => 'Docker is done']]),
            FakeLlmClient::tools(['name' => 'update_task_progress', 'args' => ['task_id' => $pg->id, 'status' => 'blocked', 'note' => 'Postgres blocked', 'progress_percent' => null, 'blocker' => 'Container cannot connect: auth failed']]),
            FakeLlmClient::text('Docker done 🎉. What error do you see from Postgres?'),
        );
        $this->webhook($this->textUpdate('Docker is done, but PostgreSQL is blocked — auth fails.'))->assertOk();

        $docker->refresh();
        $pg->refresh();
        $this->assertSame(TaskStatus::Done, $docker->status);
        $this->assertNotNull($docker->completion_confirmed_at);
        $this->assertNotNull($docker->completion_source_message_id, 'completion links to the user message as evidence');
        $this->assertSame(TaskStatus::Blocked, $pg->status);
        $this->assertSame('Container cannot connect: auth failed', $pg->blocker);
        $this->assertTrue(ActivityEvent::where('subject_id', $docker->id)->where('event_type', 'completed')->exists());
        $this->assertTrue(ActivityEvent::where('subject_id', $pg->id)->where('event_type', 'blocked')->exists());
        $this->assertSame(1, $pg->checkpoints()->where('kind', 'blocker_followup')->where('state', 'pending')->count());
        $this->assertStringContainsString('?', $this->telegram->lastText());
    }

    /** Scenario 5: deadline move needs confirmation; button applies exactly once. */
    public function test_moving_committed_deadline_requires_button_confirmation_applied_once(): void
    {
        $user = $this->makeUser();
        $task = Task::factory()->for($user)->committed()->create(['title' => 'Ship release', 'due_at' => CarbonImmutable::parse('2026-11-12 17:00', 'UTC')]);
        app(CheckpointService::class)->planForTask($task);

        $this->llm->push('agent',
            FakeLlmClient::tools(['name' => 'propose_deadline_change', 'args' => ['task_id' => $task->id, 'new_due' => '2026-11-13T18:00', 'reason' => 'Need more time']]),
            FakeLlmClient::text('Want me to move it to tomorrow 18:00? Tap Confirm.'),
        );
        $this->webhook($this->textUpdate('Move my deadline to tomorrow.'))->assertOk();

        $this->assertSame('2026-11-12T17:00:00+00:00', $task->refresh()->due_at->toIso8601String(), 'unchanged before confirmation');
        $pending = PendingActionConfirmation::firstOrFail();
        $markup = $this->telegram->sent()[0]['reply_markup'];
        $this->assertSame("c:{$pending->nonce}:y", $markup['inline_keyboard'][0][0]['callback_data']);

        // User taps Confirm — twice (double tap / Telegram retry with new update ids).
        $this->webhook($this->callbackUpdate("c:{$pending->nonce}:y"))->assertOk();
        $this->webhook($this->callbackUpdate("c:{$pending->nonce}:y"))->assertOk();

        $this->assertSame('2026-11-13T17:00:00+00:00', $task->refresh()->due_at->toIso8601String());
        $this->assertSame(PendingActionStatus::Applied, $pending->refresh()->status);
        $this->assertSame(1, ActivityEvent::where('subject_id', $task->id)->where('event_type', 'deadline_changed')->count(), 'applied exactly once');
        // Checkpoints were replanned around the new deadline.
        $this->assertSame(0, Checkpoint::where('task_id', $task->id)->where('state', 'pending')->where('earliest_at', '<', now()->addHours(12))->count());
        $this->assertSame(2, Checkpoint::where('task_id', $task->id)->where('state', 'pending')->count());
        $answers = array_filter($this->telegram->calls, fn ($c) => $c['method'] === 'answerCallbackQuery');
        $this->assertCount(2, $answers);
        $this->assertSame('Already handled.', array_values($answers)[1]['payload']['text']);
    }

    public function test_soft_deadline_on_intention_applies_directly(): void
    {
        $user = $this->makeUser();
        $task = Task::factory()->for($user)->create(['title' => 'Write blog post', 'due_date' => '2026-11-12']);
        $this->llm->push('agent',
            FakeLlmClient::tools(['name' => 'propose_deadline_change', 'args' => ['task_id' => $task->id, 'new_due' => '2026-11-14', 'reason' => null]]),
            FakeLlmClient::text('Moved to Saturday.'),
        );
        $this->webhook($this->textUpdate('Push the blog post to Saturday'))->assertOk();
        $this->assertSame('2026-11-14', $task->refresh()->due_date->toDateString());
        $this->assertSame(0, PendingActionConfirmation::count());
    }

    public function test_cancellation_always_needs_confirmation_and_reject_keeps_task(): void
    {
        $user = $this->makeUser();
        $task = Task::factory()->for($user)->create(['title' => 'Old idea']);
        $this->llm->push('agent',
            FakeLlmClient::tools(['name' => 'propose_task_cancellation', 'args' => ['task_id' => $task->id, 'reason' => 'not needed']]),
            FakeLlmClient::text('Cancel it?'),
        );
        $this->webhook($this->textUpdate('drop the old idea task'))->assertOk();
        $pending = PendingActionConfirmation::firstOrFail();
        $this->webhook($this->callbackUpdate("c:{$pending->nonce}:n"))->assertOk();
        $this->assertSame(TaskStatus::Todo, $task->refresh()->status);
        $this->assertSame(PendingActionStatus::Rejected, $pending->refresh()->status);
    }

    /** Scenario 9: natural-language notification preferences. */
    public function test_notification_preferences_are_validated_and_saved(): void
    {
        $this->llm->push('agent',
            FakeLlmClient::tools(['name' => 'update_notification_preferences', 'args' => [
                'morning_time' => '08:30', 'evening_time' => null, 'quiet_start' => '21:00', 'quiet_end' => null,
                'max_checkins_per_day' => null, 'min_gap_minutes' => null, 'timezone' => null,
                'morning_enabled' => null, 'evening_enabled' => null, 'proactive_enabled' => null, 'pause_hours' => null,
            ]]),
            FakeLlmClient::text('Done: mornings at 08:30, quiet from 21:00.'),
        );
        $this->webhook($this->textUpdate("From now on message me at 08:30; don't disturb me after 21:00."))->assertOk();
        $user = User::firstOrFail();
        $s = $user->settingsOrDefault();
        $this->assertSame('08:30', $s->morning_time);
        $this->assertSame('21:00', $s->quiet_start);
        // Evening reflection (20:00) still before quiet start → valid. Tomorrow's morning ritual planned at 08:30 local.
        app(CheckpointService::class)->ensureDailyRituals($user, '2026-11-13');
        $morning = Checkpoint::where('kind', 'morning_planning')->whereDate('local_date', '2026-11-13')->where('state', 'pending')->firstOrFail();
        $this->assertSame('08:30', $morning->earliest_at->setTimezone('Europe/Paris')->format('H:i'));
    }

    public function test_preferences_inside_quiet_hours_are_rejected(): void
    {
        $this->llm->push('agent',
            FakeLlmClient::tools(['name' => 'update_notification_preferences', 'args' => [
                'morning_time' => '07:00', 'evening_time' => null, 'quiet_start' => null, 'quiet_end' => null,
                'max_checkins_per_day' => null, 'min_gap_minutes' => null, 'timezone' => 'Mars/Base',
                'morning_enabled' => null, 'evening_enabled' => null, 'proactive_enabled' => null, 'pause_hours' => null,
            ]]),
            FakeLlmClient::text('That conflicts with quiet hours.'),
        );
        $this->webhook($this->textUpdate('morning message at 7 please, timezone mars'))->assertOk();
        $this->assertSame('09:00', User::firstOrFail()->settingsOrDefault()->morning_time);
        $tool = ToolExecution::where('tool_name', 'update_notification_preferences')->firstOrFail();
        $this->assertSame('error', $tool->status);
    }

    public function test_completion_cannot_be_claimed_without_user_turn(): void
    {
        $user = $this->makeUser();
        $task = Task::factory()->for($user)->create();
        $run = app(UsageTracker::class)->start($user, 'proactive');
        $ctx = new ToolContext($user, null, $run, 'proactive', CarbonImmutable::now());
        $result = app(ToolExecutor::class)->execute(new ToolCall('c1', 'complete_task', json_encode(['task_id' => $task->id, 'evidence' => 'x'])), $ctx);
        $this->assertFalse($result->ok);
        $this->assertSame(TaskStatus::Todo, $task->refresh()->status);
    }

    public function test_malformed_and_unknown_tool_calls_are_rejected_safely(): void
    {
        $this->llm->push('agent',
            new ChatResponse(null, [
                new ToolCall('a', 'drop_database', '{}'),
            ], 'tool_calls', 10, 10, 'm'),
            new ChatResponse(null, [
                new ToolCall('b', 'create_task', '{not json'),
            ], 'tool_calls', 10, 10, 'm'),
            FakeLlmClient::tools(['name' => 'create_task', 'args' => ['title' => '', 'commitment' => 'definitely', 'priority' => null, 'due' => null, 'planned_for_date' => null, 'notes' => null, 'goal_id' => null, 'project' => null]]),
            FakeLlmClient::text('Sorry, could you clarify?'),
        );
        $this->webhook($this->textUpdate('do something weird please'))->assertOk();
        $this->assertSame(0, Task::count());
        $agentRequests = $this->llm->requestsFor('agent');
        $toolResults = array_values(array_filter(end($agentRequests)->messages, fn ($m) => $m['role'] === 'tool'));
        $this->assertStringContainsString('Unknown tool', $toolResults[0]['content']);
        $this->assertStringContainsString('not valid JSON', $toolResults[1]['content']);
        $this->assertStringContainsString('Invalid arguments', $toolResults[2]['content']);
    }

    public function test_tool_loop_is_bounded(): void
    {
        config(['companion.agent.max_tool_iterations' => 3]);
        $loop = fn () => FakeLlmClient::tools(['name' => 'get_active_goals', 'args' => []]);
        $this->llm->push('agent', $loop(), $loop(), FakeLlmClient::text('final answer'));
        $this->webhook($this->textUpdate('loop forever please'))->assertOk();
        $requests = $this->llm->requestsFor('agent');
        $this->assertCount(3, $requests);
        $this->assertSame([], $requests[2]->tools, 'last iteration forces a text answer');
        $this->assertSame('final answer', $this->telegram->lastText());
    }

    public function test_retried_turn_does_not_duplicate_tasks(): void
    {
        $user = $this->makeUser();
        [$message] = app(ConversationService::class)->recordInbound($user, 'tg:x:1', MessageSource::TelegramText, 'Write tests today');
        $args = ['title' => 'Write tests', 'commitment' => 'intention', 'priority' => null, 'due' => null, 'planned_for_date' => '2026-11-12', 'notes' => null, 'goal_id' => null, 'project' => null];
        $this->llm->push('agent',
            FakeLlmClient::tools(['name' => 'create_task', 'args' => $args]),
            new LlmException('timeout', retryable: true),
        );
        try {
            app(AgentOrchestrator::class)->respond($user, $message);
            $this->fail('expected retryable exception');
        } catch (LlmException) {
        }
        // Queue retry of the same turn:
        $this->llm->push('agent', FakeLlmClient::tools(['name' => 'create_task', 'args' => $args]), FakeLlmClient::text('Added.'));
        app(AgentOrchestrator::class)->respond($user, $message);
        $this->assertSame(1, Task::count());
    }

    public function test_daily_budget_degrades_gracefully(): void
    {
        config(['companion.agent.daily_budget_usd' => 0.01]);
        $user = $this->makeUser();
        AgentRun::create(['user_id' => $user->id, 'trigger' => 'message', 'correlation_id' => 'x', 'status' => 'completed', 'estimated_cost_usd' => 0.02]);
        $this->webhook($this->textUpdate('hello again after budget'))->assertOk();
        $this->assertSame([], $this->llm->requestsFor('agent'));
        $this->assertStringContainsString('budget', $this->telegram->lastText());
    }
}
