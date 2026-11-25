<?php

namespace Tests\Feature;

use App\Agent\ContextBuilder;
use App\Agent\ConversationService;
use App\Agent\OpenAI\ToolCall;
use App\Agent\ToolContext;
use App\Agent\ToolExecutor;
use App\Agent\UsageTracker;
use App\Enums\MessageSource;
use App\Models\Task;
use App\Models\User;
use App\Proactive\DailyPlanningService;
use Carbon\CarbonImmutable;
use Tests\TestCase;

/** Overdue work from earlier days must never be presented as today's plan. */
class TodayTasksTest extends TestCase
{
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = $this->makeUser(111, ['timezone' => 'Europe/Paris']);
        $this->travelToLocal('2026-11-21 10:00');

        $make = fn (string $title, array $attrs) => Task::factory()->for($this->user)->create(['title' => $title] + $attrs);
        $make('Planned today', ['planned_for_date' => '2026-11-21']);
        $make('Due today', ['due_date' => '2026-11-21']);
        $make('Install fence', ['due_date' => '2026-11-18']);
        $make('Gym', ['due_at' => CarbonImmutable::parse('2026-11-20 18:00', 'Europe/Paris')->utc()]);
        $make('Late but re-planned for today', ['due_date' => '2026-11-15', 'planned_for_date' => '2026-11-21']);
        $make('Done yesterday', ['due_date' => '2026-11-20', 'status' => 'done']);
    }

    private function titles(array $tasks): array
    {
        $t = array_column($tasks, 'title');
        sort($t);

        return $t;
    }

    public function test_context_separates_overdue_from_today(): void
    {
        $data = json_decode(app(ContextBuilder::class)->structured($this->user, CarbonImmutable::now()), true);

        $this->assertSame(['Due today', 'Late but re-planned for today', 'Planned today'], $this->titles($data['today_tasks']));
        $this->assertSame(['Gym', 'Install fence'], $this->titles($data['overdue_from_earlier_days']));
        $this->assertNotContains('Install fence', array_column($data['other_open_tasks'], 'title'));
    }

    public function test_get_today_tasks_tool_separates_overdue(): void
    {
        [$msg] = app(ConversationService::class)->recordInbound($this->user, 'tg:1', MessageSource::TelegramText, 'what is my plan today?');
        $ctx = new ToolContext($this->user, $msg, app(UsageTracker::class)->start($this->user, 'message'), 'user_message', CarbonImmutable::now());
        $result = app(ToolExecutor::class)->execute(new ToolCall('x', 'get_today_tasks', json_encode(['date' => null])), $ctx);

        $this->assertTrue($result->ok);
        $this->assertSame(['Due today', 'Late but re-planned for today', 'Planned today'], $this->titles($result->data['tasks']));
        $this->assertSame(['Gym', 'Install fence'], $this->titles($result->data['overdue_from_earlier_days']));
    }

    public function test_tomorrow_does_not_include_tasks_due_today(): void
    {
        $data = json_decode(app(ContextBuilder::class)->structured($this->user, CarbonImmutable::now()), true);

        $this->assertSame([], $data['tomorrow_tasks']);
    }

    public function test_morning_planning_lists_overdue_separately(): void
    {
        $facts = app(DailyPlanningService::class)->facts($this->user, '2026-11-21');

        $this->assertSame(['Due today', 'Late but re-planned for today', 'Planned today'], $this->titles($facts['today']));
        $this->assertSame(['Gym', 'Install fence'], $this->titles($facts['overdue_from_earlier_days']));
        $text = app(DailyPlanningService::class)->fallbackText($this->user, $facts);
        $this->assertStringContainsString('Still open from earlier days', $text);
    }
}
