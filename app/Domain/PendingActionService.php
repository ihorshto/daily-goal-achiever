<?php

declare(strict_types=1);

namespace App\Domain;

use App\Domain\Data\DomainResult;
use App\Enums\Actor;
use App\Enums\PendingActionStatus;
use App\Enums\PendingActionType;
use App\Enums\Priority;
use App\Memory\PersonalDataEraser;
use App\Models\PendingActionConfirmation;
use App\Models\User;
use App\Support\LocalTime;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * Material changes (moving committed deadlines, reprioritizing, canceling, erasing data) are
 * stored as proposals and only applied after the user explicitly confirms. Application is
 * exactly-once: a guarded status transition inside the same transaction as the change.
 */
final class PendingActionService
{
    public function __construct(
        private readonly TaskService $tasks,
        private readonly GoalService $goals,
        private readonly ActivityLogger $activity,
        private readonly PersonalDataEraser $eraser,
    ) {}

    /** @param array<string, mixed> $changes */
    public function propose(User $user, PendingActionType $type, ?string $targetType, ?int $targetId, array $changes, string $summary, ?int $sourceMessageId): PendingActionConfirmation
    {
        // Supersede older open proposals of the same kind for the same target.
        $user->pendingActions()
            ->where('status', PendingActionStatus::Pending->value)
            ->where('action', $type->value)
            ->where('target_type', $targetType)
            ->where('target_id', $targetId)
            ->update(['status' => PendingActionStatus::Expired->value, 'failure_reason' => 'superseded', 'updated_at' => now()]);

        $pending = $user->pendingActions()->create([
            'action' => $type,
            'target_type' => $targetType,
            'target_id' => $targetId,
            'proposed_changes' => $changes,
            'summary' => mb_substr($summary, 0, 500),
            'nonce' => Str::random(20),
            'status' => PendingActionStatus::Pending,
            'expires_at' => now()->addHours((int) config('companion.confirmations.ttl_hours')),
            'source_message_id' => $sourceMessageId,
        ]);
        $this->activity->record($user, 'confirmation', $pending->id, 'proposed', Actor::Agent, $sourceMessageId, null, [
            'action' => $type->value, 'target' => $targetType ? "$targetType#$targetId" : null, 'changes' => $changes,
        ]);

        return $pending;
    }

    /**
     * Decide a pending action. Ownership is enforced by scoping the lookup to $user.
     * Safe to call repeatedly (double taps, Telegram retries): only the first decision applies.
     */
    public function decide(User $user, string $nonce, bool $approve, string $via, ?int $sourceMessageId = null): DomainResult
    {
        $pending = $user->pendingActions()->where('nonce', $nonce)->first();
        if ($pending === null) {
            return DomainResult::fail('That confirmation is not available.');
        }
        if ($pending->status !== PendingActionStatus::Pending) {
            return DomainResult::ok("Already handled ({$pending->status->value}).", ['status' => $pending->status->value, 'already_decided' => true]);
        }
        if ($pending->isExpired()) {
            $this->guardedUpdate($pending, PendingActionStatus::Expired, $via);

            return DomainResult::fail('That confirmation expired. Ask me again if you still want this change.');
        }

        if (! $approve) {
            $won = $this->guardedUpdate($pending, PendingActionStatus::Rejected, $via);
            if ($won) {
                $this->activity->record($user, 'confirmation', $pending->id, 'rejected', Actor::User, $sourceMessageId);
            }

            return DomainResult::ok('Okay, nothing changed.', ['status' => 'rejected']);
        }

        if ($pending->action === PendingActionType::DataDeletion) {
            // External side effects (Mem0) cannot be part of a DB transaction: claim first, then erase.
            if (! $this->guardedUpdate($pending, PendingActionStatus::Applied, $via)) {
                return DomainResult::ok('Already handled.', ['already_decided' => true]);
            }
            $this->activity->record($user, 'confirmation', $pending->id, 'applied', Actor::User, $sourceMessageId, null, ['action' => 'data_deletion']);
            $report = $this->eraser->eraseAll($user, (string) ($pending->proposed_changes['scope'] ?? 'all'));

            return DomainResult::ok('Your data deletion has been processed.', $report);
        }

        try {
            return DB::transaction(function () use ($user, $pending, $via, $sourceMessageId) {
                if (! $this->guardedUpdate($pending, PendingActionStatus::Applied, $via)) {
                    return DomainResult::ok('Already handled.', ['already_decided' => true]);
                }
                $message = $this->apply($user, $pending, $sourceMessageId);
                $this->activity->record($user, 'confirmation', $pending->id, 'applied', Actor::User, $sourceMessageId, null, ['action' => $pending->action->value]);

                return DomainResult::ok($message, ['status' => 'applied']);
            });
        } catch (DomainException $e) {
            $pending->update(['status' => PendingActionStatus::Failed, 'failure_reason' => mb_substr($e->getMessage(), 0, 255), 'decided_at' => now(), 'decided_via' => $via]);

            return DomainResult::fail('Could not apply: '.$e->getMessage());
        } catch (Throwable $e) {
            report($e);
            $pending->update(['status' => PendingActionStatus::Failed, 'failure_reason' => 'internal_error', 'decided_at' => now(), 'decided_via' => $via]);

            return DomainResult::fail('Something went wrong applying that change; nothing was modified.');
        }
    }

    public function expireOverdue(): int
    {
        return PendingActionConfirmation::query()
            ->where('status', PendingActionStatus::Pending->value)
            ->where('expires_at', '<', now())
            ->update(['status' => PendingActionStatus::Expired->value, 'updated_at' => now()]);
    }

    private function guardedUpdate(PendingActionConfirmation $pending, PendingActionStatus $to, string $via): bool
    {
        $affected = PendingActionConfirmation::query()
            ->whereKey($pending->id)
            ->where('status', PendingActionStatus::Pending->value)
            ->update(['status' => $to->value, 'decided_at' => now(), 'decided_via' => $via, 'updated_at' => now()]);

        return $affected === 1;
    }

    private function apply(User $user, PendingActionConfirmation $pending, ?int $sourceMessageId): string
    {
        $c = $pending->proposed_changes;

        return match ($pending->action) {
            PendingActionType::DeadlineChange => (function () use ($user, $pending, $c, $sourceMessageId) {
                $task = $this->tasks->findForUser($user, (int) $pending->target_id);
                $dueAt = isset($c['due_at']) ? CarbonImmutable::parse($c['due_at']) : null;
                $task = $this->tasks->applyDeadlineChange($user, $task, $dueAt, $c['due_date'] ?? null, Actor::User, $sourceMessageId, (string) ($c['reason'] ?? ''));
                $when = $task->due_at !== null ? LocalTime::human($task->due_at, $user->timezone) : ($task->due_date?->toDateString() ?? 'no deadline');

                return "Deadline for \"{$task->title}\" moved to {$when}.";
            })(),
            PendingActionType::PriorityChange => (function () use ($user, $pending, $c, $sourceMessageId) {
                $task = $this->tasks->findForUser($user, (int) $pending->target_id);
                $task = $this->tasks->applyPriorityChange($user, $task, Priority::from($c['priority']), Actor::User, $sourceMessageId);

                return "Priority of \"{$task->title}\" is now {$task->priority->value}.";
            })(),
            PendingActionType::GoalPriorityChange => (function () use ($user, $pending, $c, $sourceMessageId) {
                $goal = $this->goals->findForUser($user, (int) $pending->target_id);
                $goal = $this->goals->applyPriorityChange($user, $goal, Priority::from($c['priority']), Actor::User, $sourceMessageId);

                return "Goal \"{$goal->title}\" priority is now {$goal->priority->value}.";
            })(),
            PendingActionType::TaskCancellation => (function () use ($user, $pending, $c, $sourceMessageId) {
                $task = $this->tasks->findForUser($user, (int) $pending->target_id);
                $task = $this->tasks->applyCancellation($user, $task, Actor::User, $sourceMessageId, $c['reason'] ?? null);

                return "Canceled \"{$task->title}\".";
            })(),
            PendingActionType::DataDeletion => throw new DomainException('Handled separately.'),
        };
    }
}
