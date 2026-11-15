<?php

declare(strict_types=1);

namespace App\Domain;

use App\Enums\Actor;
use App\Enums\GoalStatus;
use App\Enums\Priority;
use App\Models\Goal;
use App\Models\Project;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

final class GoalService
{
    public function __construct(private readonly ActivityLogger $activity) {}

    public function findForUser(User $user, int $goalId): Goal
    {
        $goal = $user->goals()->whereKey($goalId)->first();
        if ($goal === null) {
            throw new DomainException("Goal #$goalId not found.");
        }

        return $goal;
    }

    /** @return array{0: Goal, 1: bool} */
    public function create(User $user, string $title, ?string $description, Priority $priority, ?string $targetDate, ?string $projectName, ?int $sourceMessageId, ?string $idempotencyKey): array
    {
        if ($idempotencyKey !== null && ($existing = Goal::query()->where('idempotency_key', $idempotencyKey)->first())) {
            return [$existing, false];
        }
        $normalized = mb_strtolower(trim($title));
        $dupe = $user->goals()->where('status', GoalStatus::Active->value)->get()
            ->first(fn (Goal $g) => mb_strtolower(trim($g->title)) === $normalized);
        if ($dupe !== null) {
            return [$dupe, false];
        }

        $projectId = $projectName ? Project::query()->firstOrCreate(['user_id' => $user->id, 'name' => mb_substr(trim($projectName), 0, 160)])->id : null;

        try {
            $goal = DB::transaction(fn () => $user->goals()->create([
                'title' => mb_substr(trim($title), 0, 200),
                'description' => $description,
                'priority' => $priority,
                'status' => GoalStatus::Active,
                'target_date' => $targetDate,
                'project_id' => $projectId,
                'created_from_message_id' => $sourceMessageId,
                'idempotency_key' => $idempotencyKey,
            ]));
        } catch (QueryException $e) {
            if ($idempotencyKey !== null && ($existing = Goal::query()->where('idempotency_key', $idempotencyKey)->first())) {
                return [$existing, false];
            }
            throw $e;
        }
        $this->activity->record($user, 'goal', $goal->id, 'created', Actor::Agent, $sourceMessageId, null, [
            'title' => $goal->title, 'priority' => $priority->value, 'target_date' => $targetDate,
        ]);

        return [$goal, true];
    }

    /** @return Collection<int, Goal> */
    public function active(User $user): Collection
    {
        return $user->goals()->where('status', GoalStatus::Active->value)
            ->withCount(['tasks as open_tasks_count' => fn ($q) => $q->open()])
            ->orderByRaw("case priority when 'critical' then 0 when 'high' then 1 when 'normal' then 2 else 3 end")
            ->get();
    }

    public function applyPriorityChange(User $user, Goal $goal, Priority $priority, Actor $actor, ?int $sourceMessageId): Goal
    {
        if ($goal->user_id !== $user->id) {
            throw new DomainException('Goal not found.');
        }
        $old = $goal->priority->value;
        $goal->update(['priority' => $priority]);
        $this->activity->record($user, 'goal', $goal->id, 'priority_changed', $actor, $sourceMessageId, ['priority' => $old], ['priority' => $priority->value]);

        return $goal->refresh();
    }
}
