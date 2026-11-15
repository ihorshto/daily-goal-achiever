<?php

declare(strict_types=1);

namespace App\Agent\Tools;

use App\Agent\ToolContext;
use App\Agent\ToolResult;
use App\Domain\TaskService;
use App\Enums\Actor;
use App\Enums\Commitment;
use Illuminate\Validation\Rule;

final class UpdateTaskTool extends BaseTool
{
    public function __construct(private readonly TaskService $tasks) {}

    public function name(): string
    {
        return 'update_task';
    }

    public function description(): string
    {
        return 'Non-material edits: title, notes, planned work date, goal link, or promoting an idea/intention to a commitment. Cannot change deadlines (use propose_deadline_change) or weaken a committed task.';
    }

    public function parameters(): array
    {
        return $this->object([
            'task_id' => $this->integer('Task id'),
            'title' => $this->string('New title', true),
            'notes' => $this->string('Notes', true),
            'planned_for_date' => $this->string('YYYY-MM-DD', true),
            'goal_id' => $this->integer('Goal id', true),
            'commitment' => $this->enum(['idea', 'intention', 'commitment'], 'New commitment level', true),
        ]);
    }

    public function rules(): array
    {
        return [
            'task_id' => ['required', 'integer', 'min:1'],
            'title' => ['nullable', 'string', 'min:2', 'max:200'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'planned_for_date' => ['nullable', 'date_format:Y-m-d'],
            'goal_id' => ['nullable', 'integer', 'min:1'],
            'commitment' => ['nullable', Rule::enum(Commitment::class)],
        ];
    }

    public function handle(ToolContext $context, array $args): ToolResult
    {
        $task = $this->tasks->findForUser($context->user, $args['task_id']);
        if ($args['commitment'] !== null) {
            $target = Commitment::from($args['commitment']);
            if ($task->commitment === Commitment::Commitment && $target !== Commitment::Commitment && $task->hasDeadline()) {
                return ToolResult::error('Weakening a committed task with a deadline is a material change; use propose_deadline_change or propose_task_cancellation.');
            }
            $task = $this->tasks->setCommitment($context->user, $task, $target, Actor::Agent, $context->sourceMessageId());
        }
        $task = $this->tasks->updateDetails($context->user, $task, [
            'title' => $args['title'],
            'notes' => $args['notes'],
            'planned_for_date' => $args['planned_for_date'],
            'goal_id' => $args['goal_id'],
        ], Actor::Agent, $context->sourceMessageId());

        return ToolResult::ok(['task' => $this->tasks->describe($task)]);
    }
}
