<?php

declare(strict_types=1);

namespace App\Domain;

use App\Enums\Actor;
use App\Enums\TaskStatus;
use App\Models\Task;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Tracks explicit progress. Completion requires evidence: a user statement in the current
 * conversation turn or a button press. The agent can never mark work done on its own inference.
 */
final class ProgressService
{
    public function __construct(
        private readonly ActivityLogger $activity,
        private readonly CheckpointService $checkpoints,
    ) {}

    public function updateProgress(User $user, Task $task, TaskStatus $status, ?string $note, ?int $percent, ?string $blocker, Actor $actor, ?int $sourceMessageId): Task
    {
        $this->assertOwned($user, $task);
        if (in_array($status, [TaskStatus::Done, TaskStatus::Canceled], true)) {
            throw new DomainException('Use complete_task for completion and propose_task_cancellation for cancellation.');
        }
        if (! $task->isOpen()) {
            throw new DomainException("Task #{$task->id} is already {$task->status->value}.");
        }
        if ($status === TaskStatus::Blocked && ($blocker === null || trim($blocker) === '')) {
            $blocker = $note ?: 'Unspecified blocker';
        }

        $wasBlocked = $task->status === TaskStatus::Blocked;

        DB::transaction(function () use ($user, $task, $status, $note, $percent, $blocker, $actor, $sourceMessageId) {
            $old = ['status' => $task->status->value, 'progress_percent' => $task->progress_percent, 'blocker' => $task->blocker];
            $task->update([
                'status' => $status,
                'progress_percent' => $percent !== null ? max(0, min(99, $percent)) : $task->progress_percent,
                'blocker' => $status === TaskStatus::Blocked ? mb_substr((string) $blocker, 0, 1000) : null,
                'last_progress_at' => now(),
            ]);
            $this->activity->record(
                $user, 'task', $task->id,
                $status === TaskStatus::Blocked ? 'blocked' : ($old['status'] === TaskStatus::Blocked->value ? 'unblocked' : 'progress'),
                $actor, $sourceMessageId, $old,
                ['status' => $status->value, 'progress_percent' => $task->progress_percent, 'blocker' => $task->blocker],
                $note,
            );
        });

        if ($status === TaskStatus::Blocked && ! $wasBlocked) {
            $this->checkpoints->scheduleBlockerFollowUp($task, $sourceMessageId);
        }

        return $task->refresh();
    }

    /**
     * @param  string  $evidence  short quote/description of how the user confirmed completion
     */
    public function complete(User $user, Task $task, Actor $actor, ?int $sourceMessageId, string $evidence): Task
    {
        $this->assertOwned($user, $task);
        if ($actor === Actor::Agent && $sourceMessageId === null) {
            throw new DomainException('Completion needs an explicit user statement.');
        }
        if ($task->status === TaskStatus::Done) {
            return $task; // idempotent
        }
        if ($task->status === TaskStatus::Canceled) {
            throw new DomainException("Task #{$task->id} was canceled.");
        }

        DB::transaction(function () use ($user, $task, $actor, $sourceMessageId, $evidence) {
            $old = $task->status->value;
            $task->update([
                'status' => TaskStatus::Done,
                'progress_percent' => 100,
                'blocker' => null,
                'completed_at' => now(),
                'completion_confirmed_at' => now(),
                'completion_source_message_id' => $sourceMessageId,
                'last_progress_at' => now(),
            ]);
            $this->activity->record($user, 'task', $task->id, 'completed', $actor, $sourceMessageId, ['status' => $old], ['status' => 'done'], $evidence);
        });

        $this->checkpoints->cancelForTask($task, 'task_completed');

        return $task->refresh();
    }

    private function assertOwned(User $user, Task $task): void
    {
        if ($task->user_id !== $user->id) {
            throw new DomainException('Task not found.');
        }
    }
}
