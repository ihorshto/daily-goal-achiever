<?php

declare(strict_types=1);

namespace App\Agent\Tools;

use App\Agent\ToolContext;
use App\Agent\ToolResult;
use App\Domain\CheckpointService;
use App\Domain\SettingsService;
use App\Support\LocalTime;

final class GetUserScheduleTool extends BaseTool
{
    public function __construct(private readonly SettingsService $settings, private readonly CheckpointService $checkpoints) {}

    public function name(): string
    {
        return 'get_user_schedule';
    }

    public function description(): string
    {
        return 'Current notification preferences and upcoming scheduled check-ins (with ids for rescheduling).';
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
        $tz = $context->user->timezone;

        return ToolResult::ok([
            'settings' => $this->settings->describe($context->user),
            'upcoming_checkins' => $this->checkpoints->upcoming($context->user)->map(fn ($c) => [
                'checkin_id' => $c->id,
                'kind' => $c->kind->value,
                'at' => LocalTime::human($c->earliest_at, $tz),
                'task' => $c->task?->title,
                'reason' => $c->reason,
            ])->all(),
        ]);
    }
}
