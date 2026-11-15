<?php

declare(strict_types=1);

namespace App\Agent\Tools;

use App\Agent\ToolContext;
use App\Agent\ToolResult;
use App\Domain\GoalService;
use App\Enums\Priority;
use Illuminate\Validation\Rule;

final class CreateGoalTool extends BaseTool
{
    public function __construct(private readonly GoalService $goals) {}

    public function name(): string
    {
        return 'create_goal';
    }

    public function description(): string
    {
        return 'Record a longer-term goal or outcome the user is working toward (tasks can link to it).';
    }

    public function parameters(): array
    {
        return $this->object([
            'title' => $this->string('Goal title'),
            'description' => $this->string('Why it matters / definition of done', true),
            'priority' => $this->enum(['low', 'normal', 'high', 'critical'], 'Priority', true),
            'target_date' => $this->string('YYYY-MM-DD', true),
            'project' => $this->string('Project name', true),
        ]);
    }

    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'min:2', 'max:200'],
            'description' => ['nullable', 'string', 'max:2000'],
            'priority' => ['nullable', Rule::enum(Priority::class)],
            'target_date' => ['nullable', 'date_format:Y-m-d'],
            'project' => ['nullable', 'string', 'max:160'],
        ];
    }

    public function handle(ToolContext $context, array $args): ToolResult
    {
        $key = $context->sourceMessageId() !== null ? 'm'.$context->sourceMessageId().':g:'.substr(hash('sha256', mb_strtolower($args['title'])), 0, 32) : null;
        [$goal, $created] = $this->goals->create(
            $context->user, $args['title'], $args['description'],
            $args['priority'] ? Priority::from($args['priority']) : Priority::Normal,
            $args['target_date'], $args['project'], $context->sourceMessageId(), $key,
        );

        return ToolResult::ok(['created' => $created, 'goal' => ['id' => $goal->id, 'title' => $goal->title, 'priority' => $goal->priority->value, 'target_date' => $goal->target_date?->toDateString()]]);
    }
}
