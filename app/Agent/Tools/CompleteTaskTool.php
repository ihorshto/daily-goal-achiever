<?php

declare(strict_types=1);

namespace App\Agent\Tools;

use App\Agent\ToolContext;
use App\Agent\ToolResult;
use App\Domain\ProgressService;
use App\Domain\TaskService;
use App\Enums\Actor;

final class CompleteTaskTool extends BaseTool
{
    public function __construct(private readonly TaskService $tasks, private readonly ProgressService $progress) {}

    public function name(): string
    {
        return 'complete_task';
    }

    public function description(): string
    {
        return 'Mark a task done ONLY when the user explicitly said in this message that it is finished. evidence = the user\'s words.';
    }

    public function parameters(): array
    {
        return $this->object([
            'task_id' => $this->integer('Task id'),
            'evidence' => $this->string('Short quote of the user confirming completion'),
        ]);
    }

    public function rules(): array
    {
        return [
            'task_id' => ['required', 'integer', 'min:1'],
            'evidence' => ['required', 'string', 'min:2', 'max:500'],
        ];
    }

    public function handle(ToolContext $context, array $args): ToolResult
    {
        $task = $this->tasks->findForUser($context->user, $args['task_id']);
        $task = $this->progress->complete($context->user, $task, Actor::User, $context->sourceMessageId(), $args['evidence']);

        return ToolResult::ok(['task' => $this->tasks->describe($task), 'pending_checkins_canceled' => true]);
    }
}
