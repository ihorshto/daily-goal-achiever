<?php

declare(strict_types=1);

namespace App\Agent\Tools;

use App\Agent\ToolContext;
use App\Agent\ToolResult;
use App\Domain\TaskService;

final class GetTodayTasksTool extends BaseTool
{
    public function __construct(private readonly TaskService $tasks) {}

    public function name(): string
    {
        return 'get_today_tasks';
    }

    public function description(): string
    {
        return 'Tasks planned for, due on, or overdue as of a local date (default today). Use date for "tomorrow" etc. Also returns other open tasks without a date.';
    }

    public function mutates(): bool
    {
        return false;
    }

    public function parameters(): array
    {
        return $this->object(['date' => $this->string('Local date YYYY-MM-DD, or null for today', true)]);
    }

    public function rules(): array
    {
        return ['date' => ['nullable', 'date_format:Y-m-d']];
    }

    public function handle(ToolContext $context, array $args): ToolResult
    {
        $date = $this->localDate($args['date'], $context->user->timezone);
        $forDate = $this->tasks->forLocalDate($context->user, $date);
        $undated = $context->user->tasks()->open()->whereNull('due_at')->whereNull('due_date')->whereNull('planned_for_date')
            ->whereNotIn('id', $forDate->pluck('id'))->latest('updated_at')->limit(10)->get();

        return ToolResult::ok([
            'date' => $date,
            'tasks' => $forDate->map(fn ($t) => $this->tasks->describe($t))->all(),
            'other_open_undated' => $undated->map(fn ($t) => $this->tasks->describe($t))->all(),
        ]);
    }
}
