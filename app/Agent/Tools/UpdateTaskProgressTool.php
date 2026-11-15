<?php

declare(strict_types=1);

namespace App\Agent\Tools;

use App\Agent\ToolContext;
use App\Agent\ToolResult;
use App\Domain\ProgressService;
use App\Domain\TaskService;
use App\Enums\Actor;
use App\Enums\TaskStatus;
use App\Support\LocalTime;

final class UpdateTaskProgressTool extends BaseTool
{
    public function __construct(private readonly TaskService $tasks, private readonly ProgressService $progress) {}

    public function name(): string
    {
        return 'update_task_progress';
    }

    public function description(): string
    {
        return 'Record explicit progress the user reported: in_progress, blocked (include the blocker), or back to todo. Not for completion.';
    }

    public function parameters(): array
    {
        return $this->object([
            'task_id' => $this->integer('Task id'),
            'status' => $this->enum(['todo', 'in_progress', 'blocked'], 'New status'),
            'note' => $this->string('What the user said about progress', true),
            'progress_percent' => $this->integer('0-99 if the user gave an estimate', true),
            'blocker' => $this->string('Blocker description when status=blocked', true),
        ]);
    }

    public function rules(): array
    {
        return [
            'task_id' => ['required', 'integer', 'min:1'],
            'status' => ['required', 'in:todo,in_progress,blocked'],
            'note' => ['nullable', 'string', 'max:2000'],
            'progress_percent' => ['nullable', 'integer', 'min:0', 'max:99'],
            'blocker' => ['nullable', 'string', 'max:1000'],
        ];
    }

    public function handle(ToolContext $context, array $args): ToolResult
    {
        $task = $this->tasks->findForUser($context->user, $args['task_id']);
        $task = $this->progress->updateProgress(
            $context->user, $task, TaskStatus::from($args['status']), $args['note'],
            $args['progress_percent'], $args['blocker'], Actor::Agent, $context->sourceMessageId(),
        );
        $followUp = $task->checkpoints()->where('state', 'pending')->where('kind', 'blocker_followup')->orderBy('earliest_at')->first();

        return ToolResult::ok([
            'task' => $this->tasks->describe($task),
            'blocker_followup_at' => $followUp ? LocalTime::human($followUp->earliest_at, $context->user->timezone) : null,
        ]);
    }
}
