<?php

declare(strict_types=1);

namespace App\Agent;

use App\Models\AgentRun;
use App\Models\User;

/** Per-user daily spend cap (estimated). When exceeded, the bot degrades to deterministic replies. */
final class BudgetGuard
{
    public function spentToday(User $user): float
    {
        return (float) AgentRun::query()
            ->where('user_id', $user->id)
            ->where('created_at', '>=', now()->startOfDay())
            ->sum('estimated_cost_usd');
    }

    public function exceeded(User $user): bool
    {
        $budget = (float) config('companion.agent.daily_budget_usd');

        return $budget > 0 && $this->spentToday($user) >= $budget;
    }
}
