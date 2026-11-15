<?php

declare(strict_types=1);

namespace App\Agent\Tools;

use App\Agent\ToolContext;
use App\Agent\ToolResult;
use App\Domain\SettingsService;
use App\Enums\Actor;

final class UpdateNotificationPreferencesTool extends BaseTool
{
    public function __construct(private readonly SettingsService $settings) {}

    public function name(): string
    {
        return 'update_notification_preferences';
    }

    public function description(): string
    {
        return 'Change when/how the companion contacts the user, and the reply language. "Always answer in Ukrainian" → reply_language "Ukrainian"; "answer in whatever language I write" → reply_language "auto". Times are local HH:MM 24h. "Don\'t disturb me after 21:00" → quiet_start 21:00. "Message me at 08:30" → morning_time 08:30. pause_hours pauses proactive messages (0 resumes). Only pass fields the user changed.';
    }

    public function parameters(): array
    {
        return $this->object([
            'morning_time' => $this->string('HH:MM', true),
            'evening_time' => $this->string('HH:MM', true),
            'quiet_start' => $this->string('HH:MM', true),
            'quiet_end' => $this->string('HH:MM', true),
            'max_checkins_per_day' => $this->integer('0-12 adaptive check-ins per day', true),
            'min_gap_minutes' => $this->integer('Minimum minutes between proactive messages (15-720)', true),
            'timezone' => $this->string('IANA timezone e.g. Europe/Paris', true),
            'morning_enabled' => $this->boolean('Morning planning on/off', true),
            'evening_enabled' => $this->boolean('Evening reflection on/off', true),
            'proactive_enabled' => $this->boolean('All proactive messages on/off', true),
            'pause_hours' => $this->integer('Pause proactive messages for N hours (0 = resume)', true),
            'reply_language' => $this->string('Language for all replies as an English name ("Ukrainian", "English"), or "auto" to mirror the user\'s language', true),
        ]);
    }

    public function rules(): array
    {
        return [
            'morning_time' => ['nullable', 'date_format:H:i'],
            'evening_time' => ['nullable', 'date_format:H:i'],
            'quiet_start' => ['nullable', 'date_format:H:i'],
            'quiet_end' => ['nullable', 'date_format:H:i'],
            'max_checkins_per_day' => ['nullable', 'integer', 'min:0', 'max:12'],
            'min_gap_minutes' => ['nullable', 'integer', 'min:15', 'max:720'],
            'timezone' => ['nullable', 'string', 'max:64'],
            'morning_enabled' => ['nullable', 'boolean'],
            'evening_enabled' => ['nullable', 'boolean'],
            'proactive_enabled' => ['nullable', 'boolean'],
            'pause_hours' => ['nullable', 'integer', 'min:0', 'max:720'],
            'reply_language' => ['nullable', 'string', 'max:40'],
        ];
    }

    public function handle(ToolContext $context, array $args): ToolResult
    {
        if (array_filter($args, fn ($v) => $v !== null) === []) {
            return ToolResult::error('No preference changes given.');
        }

        return ToolResult::ok(['settings' => $this->settings->update($context->user, $args, Actor::User, $context->sourceMessageId())]);
    }
}
