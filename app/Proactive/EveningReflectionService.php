<?php

declare(strict_types=1);

namespace App\Proactive;

use App\Domain\TaskService;
use App\Memory\DailySummaryService;
use App\Models\User;
use Carbon\CarbonImmutable;

/** Deterministic facts for the evening reflection (20:00 local by default): planned vs confirmed. */
final class EveningReflectionService
{
    public function __construct(private readonly TaskService $tasks, private readonly DailySummaryService $summaries) {}

    /** @return array<string, mixed> */
    public function facts(User $user, string $localDate): array
    {
        $d = $this->summaries->compute($user, $localDate);
        $tomorrow = CarbonImmutable::parse($localDate)->addDay()->toDateString();

        return [
            'ritual' => 'evening_reflection',
            'date' => $localDate,
            'confirmed_done_today' => array_column($d['confirmed_completed'], 'title'),
            'progress_today' => array_column($d['progress_updates'], 'title'),
            'blockers_today' => array_map(fn ($b) => ['task' => $b['title'], 'blocker' => $b['blocker'], 'still_blocked' => $b['still_blocked']], $d['blockers']),
            'planned_not_confirmed' => array_column($d['open_loops'], 'title'),
            'tomorrow' => $this->tasks->forLocalDate($user, $tomorrow)->filter->isOpen()->map(fn ($t) => $this->tasks->describe($t))->values()->all(),
            'note' => 'Suggest adjustments for tomorrow, but do not claim any deadline was changed.',
        ];
    }

    /** @param array<string, mixed> $facts */
    public function fallbackText(User $user, array $facts): string
    {
        $lines = ['Evening check-in 🌙'];
        $lines[] = $facts['confirmed_done_today'] !== []
            ? 'Confirmed done today: '.implode(', ', $facts['confirmed_done_today']).'.'
            : "I don't have anything confirmed as done today.";
        if ($facts['planned_not_confirmed'] !== []) {
            $lines[] = 'Still open from today: '.implode(', ', array_slice($facts['planned_not_confirmed'], 0, 5)).'.';
        }
        $lines[] = 'How did it go — anything blocking you, or anything to adjust for tomorrow?';

        return implode("\n\n", $lines);
    }
}
