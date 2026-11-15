<?php

declare(strict_types=1);

namespace App\Agent\Tools;

use App\Agent\ToolContext;
use App\Agent\ToolResult;
use App\Domain\PendingActionService;
use App\Domain\TaskService;
use App\Enums\PendingActionType;
use App\Telegram\Keyboards;

final class ProposeTaskCancellationTool extends BaseTool
{
    public function __construct(private readonly TaskService $tasks, private readonly PendingActionService $pending) {}

    public function name(): string
    {
        return 'propose_task_cancellation';
    }

    public function description(): string
    {
        return 'Propose canceling/dropping a task. Always requires the user to confirm via button.';
    }

    public function parameters(): array
    {
        return $this->object([
            'task_id' => $this->integer('Task id'),
            'reason' => $this->string('Reason', true),
        ]);
    }

    public function rules(): array
    {
        return ['task_id' => ['required', 'integer', 'min:1'], 'reason' => ['nullable', 'string', 'max:500']];
    }

    public function handle(ToolContext $context, array $args): ToolResult
    {
        $task = $this->tasks->findForUser($context->user, $args['task_id']);
        if (! $task->isOpen()) {
            return ToolResult::error("Task is already {$task->status->value}.");
        }
        $proposal = $this->pending->propose($context->user, PendingActionType::TaskCancellation, 'task', $task->id,
            ['reason' => $args['reason']], "Cancel \"{$task->title}\"", $context->sourceMessageId());
        $context->addKeyboardRow(Keyboards::confirmationRow($proposal));

        return ToolResult::ok(['awaiting_confirmation' => true, 'pending_action_id' => $proposal->id, 'summary' => $proposal->summary]);
    }
}
