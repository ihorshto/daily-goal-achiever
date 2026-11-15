<?php

declare(strict_types=1);

namespace App\Proactive;

use App\Enums\CheckpointKind;
use App\Enums\Commitment;
use App\Enums\TaskStatus;
use App\Models\Checkpoint;
use App\Models\Message;
use App\Models\NotificationLog;
use App\Models\User;
use App\Support\LocalTime;
use Carbon\CarbonImmutable;

/**
 * Deterministic delivery rules. The LLM never decides *whether a message may be sent* — only
 * this policy does. Evaluated right before sending, against freshly loaded state.
 */
final class NotificationPolicy
{
    public function evaluate(User $user, Checkpoint $checkpoint, ?CarbonImmutable $now = null): PolicyDecision
    {
        $now ??= CarbonImmutable::now();
        $settings = $user->settingsOrDefault();
        $tz = $user->timezone;
        $ritual = $checkpoint->kind->isRitual();
        $latest = $checkpoint->latest_at;

        if (! config('companion.proactive.enabled')) {
            return PolicyDecision::suppress('globally_disabled');
        }
        if (! $user->is_enabled || $user->telegram_chat_id === null) {
            return PolicyDecision::suppress('user_disabled');
        }
        if (! $settings->proactive_enabled) {
            return PolicyDecision::suppress('user_paused');
        }
        if ($checkpoint->kind === CheckpointKind::MorningPlanning && ! $settings->morning_enabled
            || $checkpoint->kind === CheckpointKind::EveningReflection && ! $settings->evening_enabled) {
            return PolicyDecision::suppress('ritual_disabled');
        }
        if ($latest !== null && $now->greaterThan($latest)) {
            return PolicyDecision::expire('window_passed');
        }

        // Task-linked checkpoints: re-validate against current task state (prevents stale questions).
        if ($checkpoint->task_id !== null) {
            $task = $checkpoint->task()->first();
            if ($task === null || ! $task->isOpen()) {
                return PolicyDecision::suppress('task_closed');
            }
            if ($task->commitment === Commitment::Idea) {
                return PolicyDecision::suppress('tentative_idea');
            }
            if ($checkpoint->kind === CheckpointKind::BlockerFollowUp && $task->status !== TaskStatus::Blocked) {
                return PolicyDecision::suppress('no_longer_blocked');
            }
            if (in_array($checkpoint->kind, [CheckpointKind::DeadlineCheck, CheckpointKind::BlockerFollowUp], true)
                && $task->last_progress_at !== null && $task->last_progress_at->greaterThan($now->subMinutes(60))
                && $task->last_progress_at->greaterThan($checkpoint->created_at)) {
                return PolicyDecision::suppress('recent_update');
            }
            $recentlyAsked = Message::query()
                ->where('user_id', $user->id)
                ->where('source', 'proactive')
                ->where('created_at', '>=', $now->subHours(3))
                ->whereIn('checkpoint_id', $task->checkpoints()->select('id'))
                ->exists();
            if ($recentlyAsked) {
                return PolicyDecision::suppress('recently_followed_up');
            }
        }

        // Pause window.
        if ($settings->paused_until !== null && $settings->paused_until->greaterThan($now)) {
            return $this->deferOrExpire($settings->paused_until, $latest, 'paused');
        }

        // Quiet hours (overnight-aware, DST-safe).
        if (LocalTime::inQuietHours($now, $tz, $settings->quiet_start, $settings->quiet_end)) {
            return $this->deferOrExpire(LocalTime::quietHoursEnd($now, $tz, $settings->quiet_start, $settings->quiet_end), $latest, 'quiet_hours');
        }

        // Silence handling: never pile up messages on someone who isn't answering.
        $unanswered = $this->consecutiveUnanswered($user);
        if ($unanswered >= 4) {
            return PolicyDecision::suppress('user_silent');
        }
        if ($unanswered >= 2 && $checkpoint->kind !== CheckpointKind::MorningPlanning) {
            return PolicyDecision::suppress('awaiting_reply');
        }
        if (! $ritual && $unanswered >= 1 && $this->lastProactiveWasAdaptive($user)) {
            return PolicyDecision::suppress('awaiting_reply_to_previous');
        }

        // Don't interrupt an active conversation.
        $activeWindow = $ritual ? 5 : (int) config('companion.proactive.active_conversation_minutes');
        if ($user->last_user_message_at !== null && $user->last_user_message_at->greaterThan($now->subMinutes($activeWindow))) {
            return $this->deferOrExpire($user->last_user_message_at->addMinutes($activeWindow + 1), $latest, 'active_conversation');
        }

        // Minimum gap between proactive messages.
        $gap = $ritual ? 15 : $settings->min_gap_minutes;
        if ($user->last_proactive_at !== null && $user->last_proactive_at->greaterThan($now->subMinutes($gap))) {
            return $this->deferOrExpire($user->last_proactive_at->addMinutes($gap), $latest, 'min_gap');
        }

        // Daily cap for adaptive check-ins (rituals have their own once-per-day key).
        if (! $ritual) {
            $sentToday = NotificationLog::query()
                ->where('user_id', $user->id)
                ->where('kind', 'proactive_adaptive')
                ->where('status', 'sent')
                ->where('sent_at', '>=', LocalTime::startOfLocalDay(LocalTime::localDate($now, $tz), $tz))
                ->count();
            if ($sentToday >= $settings->max_checkins_per_day) {
                return PolicyDecision::suppress('daily_cap');
            }
        }

        return PolicyDecision::send();
    }

    private function deferOrExpire(CarbonImmutable $until, ?CarbonImmutable $latest, string $reason): PolicyDecision
    {
        if ($latest !== null && $until->greaterThan($latest)) {
            return PolicyDecision::expire($reason.'_beyond_window');
        }

        return PolicyDecision::defer($until, $reason);
    }

    /** Proactive messages sent since the user's last own message. */
    private function consecutiveUnanswered(User $user): int
    {
        $q = Message::query()->where('user_id', $user->id)->where('source', 'proactive')->whereNotNull('sent_at');
        if ($user->last_user_message_at !== null) {
            $q->where('created_at', '>', $user->last_user_message_at);
        }

        return $q->count();
    }

    private function lastProactiveWasAdaptive(User $user): bool
    {
        $last = Message::query()->where('user_id', $user->id)->where('source', 'proactive')->whereNotNull('sent_at')->latest('id')->first();
        if ($last === null || $last->checkpoint_id === null) {
            return false;
        }
        $kind = Checkpoint::query()->whereKey($last->checkpoint_id)->first()?->kind;

        return $kind !== null && ! $kind->isRitual();
    }
}
