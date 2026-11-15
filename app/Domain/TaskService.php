<?php

declare(strict_types=1);

namespace App\Domain;

use App\Domain\Data\TaskDraft;
use App\Enums\Actor;
use App\Enums\Commitment;
use App\Enums\Priority;
use App\Enums\TaskStatus;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\Support\LocalTime;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/** Task creation, edits and material changes. All queries are scoped to the owning user. */
final class TaskService
{
    public function __construct(
        private readonly ActivityLogger $activity,
        private readonly CheckpointService $checkpoints,
    ) {}

    /** Resolve a task id strictly within the user's scope. */
    public function findForUser(User $user, int $taskId): Task
    {
        $task = $user->tasks()->whereKey($taskId)->first();
        if ($task === null) {
            throw new DomainException("Task #$taskId not found.");
        }

        return $task;
    }

    /**
     * Create a task idempotently. Returns [task, created?].
     *
     * @return array{0: Task, 1: bool}
     */
    public function create(User $user, TaskDraft $draft, Actor $actor, ?int $sourceMessageId, ?string $idempotencyKey): array
    {
        if ($idempotencyKey !== null && ($existing = Task::query()->where('idempotency_key', $idempotencyKey)->first())) {
            return [$existing, false];
        }

        // Semantic dedupe: same open title created recently (LLM re-proposing the same task).
        $normalized = mb_strtolower(trim($draft->title));
        $recent = $user->tasks()->open()
            ->where('created_at', '>=', now()->subMinutes(30))
            ->get()
            ->first(fn (Task $t) => mb_strtolower(trim($t->title)) === $normalized);
        if ($recent !== null) {
            return [$recent, false];
        }

        if ($draft->goalId !== null && ! $user->goals()->whereKey($draft->goalId)->exists()) {
            throw new DomainException("Goal #{$draft->goalId} not found.");
        }
        if ($draft->parentTaskId !== null) {
            $this->findForUser($user, $draft->parentTaskId);
        }

        try {
            $task = DB::transaction(function () use ($user, $draft, $actor, $sourceMessageId, $idempotencyKey) {
                $projectId = null;
                if ($draft->projectName !== null && trim($draft->projectName) !== '') {
                    $projectId = Project::query()->firstOrCreate(
                        ['user_id' => $user->id, 'name' => mb_substr(trim($draft->projectName), 0, 160)]
                    )->id;
                }
                $task = $user->tasks()->create([
                    'title' => mb_substr(trim($draft->title), 0, 200),
                    'notes' => $draft->notes,
                    'status' => TaskStatus::Todo,
                    'commitment' => $draft->commitment,
                    'priority' => $draft->priority,
                    'due_at' => $draft->dueAt,
                    'due_date' => $draft->dueAt === null ? $draft->dueDate : null,
                    'planned_for_date' => $draft->plannedForDate,
                    'goal_id' => $draft->goalId,
                    'parent_task_id' => $draft->parentTaskId,
                    'project_id' => $projectId,
                    'created_from_message_id' => $sourceMessageId,
                    'idempotency_key' => $idempotencyKey,
                ]);
                $this->activity->record($user, 'task', $task->id, 'created', $actor, $sourceMessageId, null, [
                    'title' => $task->title,
                    'commitment' => $task->commitment->value,
                    'priority' => $task->priority->value,
                    'due_at' => $task->due_at?->toIso8601String(),
                    'due_date' => $task->due_date?->toDateString(),
                    'planned_for_date' => $task->planned_for_date?->toDateString(),
                ]);

                return $task;
            });
        } catch (QueryException $e) {
            if ($idempotencyKey !== null && ($existing = Task::query()->where('idempotency_key', $idempotencyKey)->first())) {
                return [$existing, false];
            }
            throw $e;
        }

        $this->checkpoints->planForTask($task);

        return [$task, true];
    }

    /**
     * Non-material edits (title, notes, planned date, goal link). Never touches committed deadlines.
     *
     * @param  array{title?: string|null, notes?: string|null, planned_for_date?: string|null, goal_id?: int|null}  $changes
     */
    public function updateDetails(User $user, Task $task, array $changes, Actor $actor, ?int $sourceMessageId): Task
    {
        $this->assertOwned($user, $task);
        $changes = array_filter($changes, fn ($v) => $v !== null);
        if (isset($changes['goal_id']) && ! $user->goals()->whereKey($changes['goal_id'])->exists()) {
            throw new DomainException("Goal #{$changes['goal_id']} not found.");
        }
        if ($changes === []) {
            return $task;
        }
        $old = array_intersect_key($task->only(array_keys($changes)), $changes);
        $task->fill($changes)->save();
        $this->activity->record($user, 'task', $task->id, 'details_changed', $actor, $sourceMessageId, $this->serialize($old), $this->serialize($changes));

        return $task->refresh();
    }

    /** Is a direct (unconfirmed) deadline change allowed for this task? */
    public function deadlineChangeNeedsConfirmation(Task $task): bool
    {
        return $task->commitment->isCommitted() && $task->hasDeadline();
    }

    /** Apply a deadline change. Callers MUST have obtained confirmation for committed deadlines. */
    public function applyDeadlineChange(User $user, Task $task, ?CarbonImmutable $dueAt, ?string $dueDate, Actor $actor, ?int $sourceMessageId, string $note = ''): Task
    {
        $this->assertOwned($user, $task);
        if (! $task->isOpen()) {
            throw new DomainException('Cannot change the deadline of a closed task.');
        }
        $old = ['due_at' => $task->due_at?->toIso8601String(), 'due_date' => $task->due_date?->toDateString()];
        $task->update(['due_at' => $dueAt, 'due_date' => $dueAt === null ? $dueDate : null]);
        $task->refresh();
        $this->activity->record($user, 'task', $task->id, 'deadline_changed', $actor, $sourceMessageId, $old, [
            'due_at' => $task->due_at?->toIso8601String(),
            'due_date' => $task->due_date?->toDateString(),
        ], $note ?: null);
        $this->checkpoints->replanForTask($task);

        return $task;
    }

    public function applyPriorityChange(User $user, Task $task, Priority $priority, Actor $actor, ?int $sourceMessageId): Task
    {
        $this->assertOwned($user, $task);
        $old = $task->priority->value;
        $task->update(['priority' => $priority]);
        $this->activity->record($user, 'task', $task->id, 'priority_changed', $actor, $sourceMessageId, ['priority' => $old], ['priority' => $priority->value]);

        return $task->refresh();
    }

    public function applyCancellation(User $user, Task $task, Actor $actor, ?int $sourceMessageId, ?string $reason): Task
    {
        $this->assertOwned($user, $task);
        if (! $task->isOpen()) {
            return $task;
        }
        $old = $task->status->value;
        $task->update(['status' => TaskStatus::Canceled]);
        $this->activity->record($user, 'task', $task->id, 'canceled', $actor, $sourceMessageId, ['status' => $old], ['status' => 'canceled'], $reason);
        $this->checkpoints->cancelForTask($task, 'task_canceled');

        return $task->refresh();
    }

    /** Promote an idea/intention to a firm commitment (explicit user statement only). */
    public function setCommitment(User $user, Task $task, Commitment $commitment, Actor $actor, ?int $sourceMessageId): Task
    {
        $this->assertOwned($user, $task);
        if ($task->commitment === $commitment) {
            return $task;
        }
        $old = $task->commitment->value;
        $task->update(['commitment' => $commitment]);
        $this->activity->record($user, 'task', $task->id, 'commitment_changed', $actor, $sourceMessageId, ['commitment' => $old], ['commitment' => $commitment->value]);
        $this->checkpoints->planForTask($task->refresh());

        return $task;
    }

    /**
     * Tasks relevant to a local date: planned for it, due on it, or open & overdue.
     *
     * @return Collection<int, Task>
     */
    public function forLocalDate(User $user, string $localDate): Collection
    {
        $start = LocalTime::startOfLocalDay($localDate, $user->timezone);
        $end = LocalTime::endOfLocalDay($localDate, $user->timezone);

        return $user->tasks()
            ->where(function ($q) use ($localDate, $start, $end) {
                $q->whereDate('planned_for_date', $localDate)
                    ->orWhereDate('due_date', $localDate)
                    ->orWhereBetween('due_at', [$start, $end])
                    ->orWhere(fn ($q) => $q->whereIn('status', TaskStatus::openValues())
                        ->where(fn ($q) => $q->where('due_at', '<', $start)->orWhereDate('due_date', '<', $localDate)));
            })
            ->orderByRaw("case status when 'in_progress' then 0 when 'blocked' then 1 when 'todo' then 2 else 3 end")
            ->orderBy('due_at')
            ->get();
    }

    /** @return Collection<int, Task> */
    public function open(User $user, int $limit = 30): Collection
    {
        return $user->tasks()->open()->orderBy('due_at')->orderByDesc('updated_at')->limit($limit)->get();
    }

    public function describe(Task $task): array
    {
        $tz = $task->user->timezone;

        return array_filter([
            'id' => $task->id,
            'title' => $task->title,
            'status' => $task->status->value,
            'commitment' => $task->commitment->value,
            'priority' => $task->priority->value,
            'due' => $task->due_at !== null ? LocalTime::human($task->due_at, $tz) : $task->due_date?->toDateString(),
            'planned_for' => $task->planned_for_date?->toDateString(),
            'blocker' => $task->status === TaskStatus::Blocked ? $task->blocker : null,
            'progress_percent' => $task->progress_percent,
            'goal_id' => $task->goal_id,
            'completed_confirmed_at' => LocalTime::human($task->completion_confirmed_at, $tz),
            'last_progress' => LocalTime::human($task->last_progress_at, $tz),
        ], fn ($v) => $v !== null);
    }

    private function assertOwned(User $user, Task $task): void
    {
        if ($task->user_id !== $user->id) {
            throw new DomainException('Task not found.');
        }
    }

    /** @param array<string, mixed> $values */
    private function serialize(array $values): array
    {
        return array_map(fn ($v) => $v instanceof \DateTimeInterface ? $v->format(DATE_ATOM) : ($v instanceof \BackedEnum ? $v->value : $v), $values);
    }
}
