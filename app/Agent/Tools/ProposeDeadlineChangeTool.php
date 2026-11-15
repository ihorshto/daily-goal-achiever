<?php

declare(strict_types=1);

namespace App\Agent\Tools;

use App\Agent\ToolContext;
use App\Agent\ToolResult;
use App\Domain\PendingActionService;
use App\Domain\TaskService;
use App\Enums\Actor;
use App\Enums\PendingActionType;
use App\Support\LocalTime;
use App\Telegram\Keyboards;

final class ProposeDeadlineChangeTool extends BaseTool
{
    public function __construct(private readonly TaskService $tasks, private readonly PendingActionService $pending) {}

    public function name(): string
    {
        return 'propose_deadline_change';
    }

    public function description(): string
    {
        return 'Change a task deadline. Committed deadlines are NOT changed immediately: a confirmation button is sent and the change applies only when the user taps Confirm. Soft deadlines on non-committed tasks apply directly.';
    }

    public function parameters(): array
    {
        return $this->object([
            'task_id' => $this->integer('Task id'),
            'new_due' => $this->string('New deadline: local "YYYY-MM-DDTHH:MM" or "YYYY-MM-DD", or null to remove', true),
            'reason' => $this->string('Why it is moving (user words)', true),
        ]);
    }

    public function rules(): array
    {
        return [
            'task_id' => ['required', 'integer', 'min:1'],
            'new_due' => ['nullable', 'string', 'max:40'],
            'reason' => ['nullable', 'string', 'max:500'],
        ];
    }

    public function handle(ToolContext $context, array $args): ToolResult
    {
        $user = $context->user;
        $task = $this->tasks->findForUser($user, $args['task_id']);
        if (! $task->isOpen()) {
            return ToolResult::error("Task is already {$task->status->value}.");
        }
        [$dueAt, $dueDate] = $this->parseWhen($args['new_due'], $user->timezone);
        if ($dueAt !== null && $dueAt->isPast()) {
            return ToolResult::error('The new deadline is in the past.');
        }
        $label = $dueAt !== null ? LocalTime::human($dueAt, $user->timezone) : ($dueDate ?? 'no deadline');

        if (! $this->tasks->deadlineChangeNeedsConfirmation($task)) {
            $task = $this->tasks->applyDeadlineChange($user, $task, $dueAt, $dueDate, Actor::Agent, $context->sourceMessageId(), (string) $args['reason']);

            return ToolResult::ok(['applied' => true, 'task' => $this->tasks->describe($task)]);
        }

        $old = $task->due_at !== null ? LocalTime::human($task->due_at, $user->timezone) : $task->due_date?->toDateString();
        $proposal = $this->pending->propose($user, PendingActionType::DeadlineChange, 'task', $task->id, [
            'due_at' => $dueAt?->toIso8601String(),
            'due_date' => $dueDate,
            'reason' => $args['reason'],
        ], "Move \"{$task->title}\" deadline from {$old} to {$label}", $context->sourceMessageId());
        $context->addKeyboardRow(Keyboards::confirmationRow($proposal));

        return ToolResult::ok([
            'applied' => false,
            'awaiting_confirmation' => true,
            'pending_action_id' => $proposal->id,
            'summary' => $proposal->summary,
            'instruction' => 'Tell the user a Confirm/Cancel button was attached; the deadline is unchanged until they confirm.',
        ]);
    }
}
