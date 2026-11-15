<?php

declare(strict_types=1);

namespace App\Agent\Tools;

use App\Agent\ToolContext;
use App\Agent\ToolResult;
use App\Domain\CheckpointService;
use App\Enums\CheckpointState;
use App\Support\LocalTime;

final class RescheduleCheckinTool extends BaseTool
{
    public function __construct(private readonly CheckpointService $checkpoints) {}

    public function name(): string
    {
        return 'reschedule_checkin';
    }

    public function description(): string
    {
        return 'Move or cancel a pending check-in by id (from get_user_schedule). when=null cancels it.';
    }

    public function parameters(): array
    {
        return $this->object([
            'checkin_id' => $this->integer('Check-in id'),
            'when' => $this->string('New local "YYYY-MM-DDTHH:MM", or null to cancel', true),
        ]);
    }

    public function rules(): array
    {
        return ['checkin_id' => ['required', 'integer', 'min:1'], 'when' => ['nullable', 'string', 'max:40']];
    }

    public function handle(ToolContext $context, array $args): ToolResult
    {
        $cp = $context->user->checkpoints()->whereKey($args['checkin_id'])->first();
        if ($cp === null || $cp->state !== CheckpointState::Pending) {
            return ToolResult::error('No pending check-in with that id.');
        }
        if ($args['when'] === null) {
            $this->checkpoints->transition($cp, CheckpointState::Pending, CheckpointState::Canceled, 'user_canceled');

            return ToolResult::ok(['canceled' => true]);
        }
        [$at] = $this->parseWhen($args['when'], $context->user->timezone);
        if ($at === null || $at->lessThan($context->now->addMinutes(2))) {
            return ToolResult::error('Provide a future local time YYYY-MM-DDTHH:MM.');
        }
        $cp = $this->checkpoints->reschedule($cp, $at);

        return ToolResult::ok(['checkin_id' => $cp->id, 'at' => LocalTime::human($cp->earliest_at, $context->user->timezone)]);
    }
}
