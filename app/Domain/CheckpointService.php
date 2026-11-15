<?php

declare(strict_types=1);

namespace App\Domain;

use App\Enums\CheckpointKind;
use App\Enums\CheckpointState;
use App\Enums\Commitment;
use App\Models\Checkpoint;
use App\Models\Task;
use App\Models\User;
use App\Support\LocalTime;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Owns the lifecycle of scheduled check-ins. Checkpoints are concrete, idempotent rows;
 * we never poll the LLM to decide *when* to talk — only *what* to say once a checkpoint is due.
 */
final class CheckpointService
{
    /** Kinds that are tied to a task's deadline and replanned when the deadline moves. */
    private const DEADLINE_KINDS = [CheckpointKind::DeadlineCheck, CheckpointKind::TaskFollowUp];

    /**
     * Idempotent insert keyed by idempotency_key. Returns the existing row on conflict.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function upsertPending(User $user, string $key, array $attributes): Checkpoint
    {
        $existing = Checkpoint::query()->where('idempotency_key', $key)->first();
        if ($existing !== null) {
            return $existing;
        }

        try {
            return DB::transaction(fn () => Checkpoint::create(array_merge([
                'user_id' => $user->id,
                'state' => CheckpointState::Pending,
                'idempotency_key' => $key,
            ], $attributes)));
        } catch (QueryException $e) {
            // Concurrent creator won the race on the unique key.
            $row = Checkpoint::query()->where('idempotency_key', $key)->first();
            if ($row === null) {
                throw $e;
            }

            return $row;
        }
    }

    /** Ensure morning planning + evening reflection checkpoints exist for a local date. */
    public function ensureDailyRituals(User $user, string $localDate, ?CarbonImmutable $now = null): void
    {
        $now ??= CarbonImmutable::now();
        $settings = $user->settingsOrDefault();
        $window = (int) config('companion.proactive.ritual_window_minutes');

        $rituals = [
            [CheckpointKind::MorningPlanning, $settings->morning_time, $settings->morning_enabled, 'Morning planning'],
            [CheckpointKind::EveningReflection, $settings->evening_time, $settings->evening_enabled, 'Evening reflection'],
        ];

        foreach ($rituals as [$kind, $clock, $enabled, $reason]) {
            if (! $enabled) {
                continue;
            }
            $at = LocalTime::at($localDate, $clock, $user->timezone);
            if ($at->addMinutes($window)->lessThan($now)) {
                continue; // window already passed; don't create a stale ritual
            }
            // Key includes the clock so a settings change produces a fresh checkpoint.
            $key = sprintf('ritual:%s:%d:%s:%s', $kind->value, $user->id, $localDate, $clock);
            $this->upsertPending($user, $key, [
                'kind' => $kind,
                'reason' => $reason,
                'local_date' => $localDate,
                'earliest_at' => $at,
                'latest_at' => $at->addMinutes($window),
            ]);
        }
    }

    /** Cancel not-yet-sent rituals whose clock no longer matches settings (after a preference change). */
    public function resyncRituals(User $user): int
    {
        $settings = $user->settingsOrDefault();
        $count = 0;
        $pending = $user->checkpoints()
            ->where('state', CheckpointState::Pending->value)
            ->whereIn('kind', [CheckpointKind::MorningPlanning->value, CheckpointKind::EveningReflection->value])
            ->get();

        foreach ($pending as $cp) {
            $clock = $cp->kind === CheckpointKind::MorningPlanning ? $settings->morning_time : $settings->evening_time;
            $enabled = $cp->kind === CheckpointKind::MorningPlanning ? $settings->morning_enabled : $settings->evening_enabled;
            if (! $enabled || ! str_ends_with($cp->idempotency_key, ':'.$clock)) {
                $count += $this->transition($cp, CheckpointState::Pending, CheckpointState::Canceled, 'settings_changed');
            }
        }

        $this->ensureDailyRituals($user, LocalTime::today($user->timezone));

        return $count;
    }

    /** Adaptive plan derived from the task's deadline. Ideas never get proactive follow-ups. */
    public function planForTask(Task $task, ?CarbonImmutable $now = null): void
    {
        $now ??= CarbonImmutable::now();
        if (! $task->isOpen() || $task->commitment === Commitment::Idea) {
            return;
        }
        $user = $task->user;
        $pre = (int) config('companion.proactive.pre_deadline_minutes');
        $post = (int) config('companion.proactive.post_deadline_minutes');

        if ($task->due_at !== null) {
            $due = $task->due_at;
            $stamp = $due->format('YmdHi');
            $checkAt = $due->subMinutes($pre);
            if ($checkAt->greaterThan($now->addMinutes(15))) {
                $this->upsertPending($user, "deadline:{$task->id}:pre:{$stamp}", [
                    'kind' => CheckpointKind::DeadlineCheck,
                    'task_id' => $task->id,
                    'reason' => 'Deadline approaching: check progress and offer help',
                    'earliest_at' => $checkAt,
                    'latest_at' => $due,
                ]);
            }
            if ($due->addMinutes($post)->greaterThan($now)) {
                $this->upsertPending($user, "deadline:{$task->id}:post:{$stamp}", [
                    'kind' => CheckpointKind::TaskFollowUp,
                    'task_id' => $task->id,
                    'reason' => 'Deadline passed: ask how it went (do not assume completion)',
                    'earliest_at' => $due->addMinutes($post),
                    'latest_at' => $due->addHours(12),
                ]);
            }
        } elseif ($task->due_date !== null && $task->commitment->isCommitted()) {
            $date = $task->due_date->toDateString();
            $at = LocalTime::at($date, '16:00', $user->timezone);
            if ($at->greaterThan($now)) {
                $this->upsertPending($user, "deadline:{$task->id}:day:{$date}", [
                    'kind' => CheckpointKind::DeadlineCheck,
                    'task_id' => $task->id,
                    'reason' => 'Due today (no explicit time): check whether it is on track',
                    'earliest_at' => $at,
                    'latest_at' => LocalTime::at($date, '21:00', $user->timezone),
                ]);
            }
        }
    }

    public function scheduleBlockerFollowUp(Task $task, ?int $sourceMessageId = null): Checkpoint
    {
        $hours = (int) config('companion.proactive.blocked_followup_hours');
        $at = CarbonImmutable::now()->addHours($hours);

        return $this->upsertPending($task->user, "blocker:{$task->id}:".CarbonImmutable::now()->format('YmdH'), [
            'kind' => CheckpointKind::BlockerFollowUp,
            'task_id' => $task->id,
            'reason' => 'Task was blocked: ask whether the blocker is resolved and offer a next step',
            'earliest_at' => $at,
            'latest_at' => $at->addHours(8),
            'source_message_id' => $sourceMessageId,
        ]);
    }

    /** Explicit follow-up promised in conversation ("check on me at 15:00"). */
    public function schedulePromised(User $user, CarbonImmutable $at, string $reason, ?Task $task, ?int $sourceMessageId, string $idempotencySeed): Checkpoint
    {
        return $this->upsertPending($user, 'promised:'.$user->id.':'.substr(hash('sha256', $idempotencySeed), 0, 32), [
            'kind' => CheckpointKind::Promised,
            'task_id' => $task?->id,
            'reason' => mb_substr($reason, 0, 500),
            'earliest_at' => $at,
            'latest_at' => $at->addHours(6),
            'created_by' => 'agent',
            'source_message_id' => $sourceMessageId,
        ]);
    }

    public function reschedule(Checkpoint $checkpoint, CarbonImmutable $at): Checkpoint
    {
        if ($checkpoint->state !== CheckpointState::Pending) {
            throw new DomainException('Only pending check-ins can be rescheduled.');
        }
        $span = $checkpoint->latest_at !== null ? $checkpoint->earliest_at->diffInMinutes($checkpoint->latest_at) : 360;
        $checkpoint->update(['earliest_at' => $at, 'latest_at' => $at->addMinutes(max(30, (int) $span))]);

        return $checkpoint->refresh();
    }

    /** Cancel every pending checkpoint for a task (completed/canceled tasks get no reminders). */
    public function cancelForTask(Task $task, string $reason): int
    {
        return Checkpoint::query()
            ->where('task_id', $task->id)
            ->where('state', CheckpointState::Pending->value)
            ->update(['state' => CheckpointState::Canceled->value, 'outcome_reason' => $reason, 'updated_at' => now()]);
    }

    /** Deadline moved: drop deadline-derived checkpoints and recompute. */
    public function replanForTask(Task $task): void
    {
        Checkpoint::query()
            ->where('task_id', $task->id)
            ->where('state', CheckpointState::Pending->value)
            ->whereIn('kind', array_map(fn ($k) => $k->value, self::DEADLINE_KINDS))
            ->update(['state' => CheckpointState::Canceled->value, 'outcome_reason' => 'deadline_changed', 'updated_at' => now()]);
        $this->planForTask($task->refresh());
    }

    /**
     * Atomically claim due checkpoints. The conditional UPDATE (state = pending) guarantees that
     * concurrent schedulers/workers can never claim the same row twice.
     *
     * @return Collection<int, array{id: int, token: string}>
     */
    public function claimDue(int $limit = 50, ?CarbonImmutable $now = null): Collection
    {
        $now ??= CarbonImmutable::now();
        $ttl = (int) config('companion.proactive.claim_ttl_seconds');
        $ids = Checkpoint::query()
            ->where('state', CheckpointState::Pending->value)
            ->where('earliest_at', '<=', $now)
            ->orderBy('earliest_at')
            ->limit($limit)
            ->pluck('id');

        $claimed = collect();
        foreach ($ids as $id) {
            $token = (string) Str::uuid();
            $affected = Checkpoint::query()
                ->whereKey($id)
                ->where('state', CheckpointState::Pending->value)
                ->update([
                    'state' => CheckpointState::Claimed->value,
                    'claimed_by' => $token,
                    'claimed_until' => $now->addSeconds($ttl),
                    'last_attempt_at' => $now,
                    'attempts' => DB::raw('attempts + 1'),
                    'updated_at' => $now,
                ]);
            if ($affected === 1) {
                $claimed->push(['id' => (int) $id, 'token' => $token]);
            }
        }

        return $claimed;
    }

    /** Return expired claims to the queue (worker crashed) or fail them after max attempts. */
    public function recoverStaleClaims(?CarbonImmutable $now = null): int
    {
        $now ??= CarbonImmutable::now();
        $max = (int) config('companion.proactive.max_attempts');
        $failed = Checkpoint::query()
            ->where('state', CheckpointState::Claimed->value)
            ->where('claimed_until', '<', $now)
            ->where('attempts', '>=', $max)
            ->update(['state' => CheckpointState::Failed->value, 'outcome_reason' => 'max_attempts', 'claimed_by' => null, 'updated_at' => $now]);
        $released = Checkpoint::query()
            ->where('state', CheckpointState::Claimed->value)
            ->where('claimed_until', '<', $now)
            ->update(['state' => CheckpointState::Pending->value, 'claimed_by' => null, 'claimed_until' => null, 'updated_at' => $now]);

        return $failed + $released;
    }

    /** Guarded state transition; returns 1 if applied, 0 if the row was no longer in $from. */
    public function transition(Checkpoint $checkpoint, CheckpointState $from, CheckpointState $to, ?string $reason = null, ?string $token = null, array $extra = []): int
    {
        $query = Checkpoint::query()->whereKey($checkpoint->id)->where('state', $from->value);
        if ($token !== null) {
            $query->where('claimed_by', $token);
        }

        return $query->update(array_merge([
            'state' => $to->value,
            'outcome_reason' => $reason !== null ? mb_substr($reason, 0, 255) : null,
            'updated_at' => now(),
        ], $extra));
    }

    /** Release a claimed checkpoint back to pending at a later time (quiet hours, gaps, active chat). */
    public function defer(Checkpoint $checkpoint, string $token, CarbonImmutable $until, string $reason): int
    {
        return Checkpoint::query()
            ->whereKey($checkpoint->id)
            ->where('state', CheckpointState::Claimed->value)
            ->where('claimed_by', $token)
            ->update([
                'state' => CheckpointState::Pending->value,
                'earliest_at' => $until,
                'claimed_by' => null,
                'claimed_until' => null,
                'outcome_reason' => 'deferred:'.$reason,
                'updated_at' => now(),
            ]);
    }

    /** @return Collection<int, Checkpoint> */
    public function upcoming(User $user, int $limit = 10): Collection
    {
        return $user->checkpoints()
            ->where('state', CheckpointState::Pending->value)
            ->orderBy('earliest_at')
            ->limit($limit)
            ->with('task')
            ->get();
    }
}
