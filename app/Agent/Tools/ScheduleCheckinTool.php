<?php

declare(strict_types=1);

namespace App\Agent\Tools;

use App\Agent\ToolContext;
use App\Agent\ToolResult;
use App\Domain\CheckpointService;
use App\Domain\TaskService;
use App\Support\LocalTime;

final class ScheduleCheckinTool extends BaseTool
{
    public function __construct(private readonly CheckpointService $checkpoints, private readonly TaskService $tasks) {}

    public function name(): string
    {
        return 'schedule_checkin';
    }

    public function description(): string
    {
        return 'Schedule a concrete follow-up message at a local time (e.g. when you promise "I\'ll check in at 15:00" or the user asks to be reminded). Delivery still respects quiet hours and caps.';
    }

    public function parameters(): array
    {
        return $this->object([
            'when' => $this->string('Local "YYYY-MM-DDTHH:MM"'),
            'reason' => $this->string('What to follow up about'),
            'task_id' => $this->integer('Related task id', true),
        ]);
    }

    public function rules(): array
    {
        return ['when' => ['required', 'string', 'max:40'], 'reason' => ['required', 'string', 'min:3', 'max:500'], 'task_id' => ['nullable', 'integer', 'min:1']];
    }

    public function handle(ToolContext $context, array $args): ToolResult
    {
        $user = $context->user;
        [$at] = $this->parseWhen($args['when'], $user->timezone);
        if ($at === null) {
            return ToolResult::error('A specific time is required (YYYY-MM-DDTHH:MM).');
        }
        if ($at->lessThan($context->now->addMinutes(2)) || $at->greaterThan($context->now->addDays(60))) {
            return ToolResult::error('Check-ins must be between 2 minutes and 60 days from now.');
        }
        $task = $args['task_id'] !== null ? $this->tasks->findForUser($user, $args['task_id']) : null;
        $settings = $user->settingsOrDefault();
        $quiet = LocalTime::inQuietHours($at, $user->timezone, $settings->quiet_start, $settings->quiet_end);
        $cp = $this->checkpoints->schedulePromised($user, $at, $args['reason'], $task, $context->sourceMessageId(), $at->toIso8601String().'|'.$args['reason'].'|'.($task?->id));

        return ToolResult::ok([
            'checkin_id' => $cp->id,
            'at' => LocalTime::human($cp->earliest_at, $user->timezone),
            'note' => $quiet ? 'That time is inside quiet hours; it will be delivered when quiet hours end.' : null,
        ]);
    }
}
