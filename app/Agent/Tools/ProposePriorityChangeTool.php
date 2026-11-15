<?php

declare(strict_types=1);

namespace App\Agent\Tools;

use App\Agent\ToolContext;
use App\Agent\ToolResult;
use App\Domain\GoalService;
use App\Domain\PendingActionService;
use App\Domain\TaskService;
use App\Enums\Actor;
use App\Enums\Commitment;
use App\Enums\PendingActionType;
use App\Enums\Priority;
use App\Telegram\Keyboards;
use Illuminate\Validation\Rule;

final class ProposePriorityChangeTool extends BaseTool
{
    public function __construct(
        private readonly TaskService $tasks,
        private readonly GoalService $goals,
        private readonly PendingActionService $pending,
    ) {}

    public function name(): string
    {
        return 'propose_priority_change';
    }

    public function description(): string
    {
        return 'Change the priority of a goal or task. Goal priority changes and committed-task priority changes require button confirmation; other task priority tweaks apply directly.';
    }

    public function parameters(): array
    {
        return $this->object([
            'target' => $this->enum(['task', 'goal'], 'What to reprioritize'),
            'id' => $this->integer('Task or goal id'),
            'priority' => $this->enum(['low', 'normal', 'high', 'critical'], 'New priority'),
            'reason' => $this->string('Reason', true),
        ]);
    }

    public function rules(): array
    {
        return [
            'target' => ['required', 'in:task,goal'],
            'id' => ['required', 'integer', 'min:1'],
            'priority' => ['required', Rule::enum(Priority::class)],
            'reason' => ['nullable', 'string', 'max:500'],
        ];
    }

    public function handle(ToolContext $context, array $args): ToolResult
    {
        $user = $context->user;
        $priority = Priority::from($args['priority']);

        if ($args['target'] === 'goal') {
            $goal = $this->goals->findForUser($user, $args['id']);
            if ($goal->priority === $priority) {
                return ToolResult::ok(['applied' => false, 'note' => 'Priority already set.']);
            }
            $proposal = $this->pending->propose($user, PendingActionType::GoalPriorityChange, 'goal', $goal->id,
                ['priority' => $priority->value, 'reason' => $args['reason']],
                "Change goal \"{$goal->title}\" priority {$goal->priority->value} → {$priority->value}", $context->sourceMessageId());
        } else {
            $task = $this->tasks->findForUser($user, $args['id']);
            if ($task->priority === $priority) {
                return ToolResult::ok(['applied' => false, 'note' => 'Priority already set.']);
            }
            if ($task->commitment !== Commitment::Commitment) {
                $task = $this->tasks->applyPriorityChange($user, $task, $priority, Actor::Agent, $context->sourceMessageId());

                return ToolResult::ok(['applied' => true, 'task' => $this->tasks->describe($task)]);
            }
            $proposal = $this->pending->propose($user, PendingActionType::PriorityChange, 'task', $task->id,
                ['priority' => $priority->value, 'reason' => $args['reason']],
                "Change \"{$task->title}\" priority {$task->priority->value} → {$priority->value}", $context->sourceMessageId());
        }
        $context->addKeyboardRow(Keyboards::confirmationRow($proposal));

        return ToolResult::ok(['applied' => false, 'awaiting_confirmation' => true, 'pending_action_id' => $proposal->id, 'summary' => $proposal->summary]);
    }
}
