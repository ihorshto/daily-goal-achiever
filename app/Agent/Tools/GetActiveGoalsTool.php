<?php

declare(strict_types=1);

namespace App\Agent\Tools;

use App\Agent\ToolContext;
use App\Agent\ToolResult;
use App\Domain\GoalService;

final class GetActiveGoalsTool extends BaseTool
{
    public function __construct(private readonly GoalService $goals) {}

    public function name(): string
    {
        return 'get_active_goals';
    }

    public function description(): string
    {
        return 'List the user\'s active goals with open task counts.';
    }

    public function mutates(): bool
    {
        return false;
    }

    public function parameters(): array
    {
        return $this->object([]);
    }

    public function rules(): array
    {
        return [];
    }

    public function handle(ToolContext $context, array $args): ToolResult
    {
        return ToolResult::ok(['goals' => $this->goals->active($context->user)->map(fn ($g) => [
            'id' => $g->id, 'title' => $g->title, 'priority' => $g->priority->value,
            'target_date' => $g->target_date?->toDateString(), 'open_tasks' => $g->open_tasks_count,
        ])->all()]);
    }
}
