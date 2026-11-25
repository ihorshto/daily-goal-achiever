<?php

declare(strict_types=1);

namespace App\Proactive;

use App\Domain\TaskService;
use App\Memory\DailySummaryService;
use App\Models\User;
use Carbon\CarbonImmutable;

/** Deterministic facts for the morning planning message (09:00 local by default). */
final class DailyPlanningService
{
    public function __construct(private readonly TaskService $tasks, private readonly DailySummaryService $summaries) {}

    /** @return array<string, mixed> */
    public function facts(User $user, string $localDate): array
    {
        $yesterday = CarbonImmutable::parse($localDate)->subDay()->toDateString();
        $y = $this->summaries->compute($user, $yesterday);
        $today = $this->tasks->forLocalDate($user, $localDate)->filter->isOpen();
        $overdue = $this->tasks->overdueAsOf($user, $localDate);

        return [
            'ritual' => 'morning_planning',
            'date' => $localDate,
            'yesterday_confirmed_done' => array_column($y['confirmed_completed'], 'title'),
            'yesterday_still_blocked' => array_column(array_filter($y['blockers'], fn ($b) => $b['still_blocked']), 'title'),
            'today' => $today->map(fn ($t) => $this->tasks->describe($t))->values()->all(),
            'committed_today' => $today->filter(fn ($t) => $t->commitment->isCommitted())->pluck('title')->values()->all(),
            'overdue_from_earlier_days' => $overdue->map(fn ($t) => $this->tasks->describe($t))->values()->all(),
        ];
    }

    /** @param array<string, mixed> $facts */
    public function fallbackText(User $user, array $facts): string
    {
        $lines = ["Good morning, {$user->displayName()}!"];
        if ($facts['yesterday_confirmed_done'] !== []) {
            $lines[] = 'Yesterday you finished: '.implode(', ', $facts['yesterday_confirmed_done']).'.';
        }
        if ($facts['today'] !== []) {
            $lines[] = "On today's list:\n".implode("\n", array_map(fn ($t) => '- '.$t['title'].(isset($t['due']) ? " (due {$t['due']})" : ''), array_slice($facts['today'], 0, 6)));
        }
        if ($facts['overdue_from_earlier_days'] !== []) {
            $lines[] = "Still open from earlier days:\n".implode("\n", array_map(fn ($t) => '- '.$t['title'].(isset($t['due']) ? " (was due {$t['due']})" : ''), array_slice($facts['overdue_from_earlier_days'], 0, 4)));
        }
        $lines[] = "What's your top priority today?";

        return implode("\n\n", $lines);
    }
}
