<?php

declare(strict_types=1);

namespace App\Agent;

use App\Domain\CheckpointService;
use App\Domain\GoalService;
use App\Domain\SettingsService;
use App\Domain\TaskService;
use App\Enums\MessageRole;
use App\Enums\PendingActionStatus;
use App\Memory\DailySummaryService;
use App\Memory\MemoryRetriever;
use App\Memory\RetrievedMemory;
use App\Models\Message;
use App\Models\User;
use App\Support\LocalTime;
use Carbon\CarbonImmutable;

/**
 * Builds the model context from three layers, highest priority first:
 *   1. structured truth (settings, goals, tasks, yesterday/today/tomorrow, pending confirmations)
 *   2. long-term semantic memories relevant to the current message (deduped, ranked, dated)
 *   3. recent conversation window (newest messages kept first when trimming)
 * Each layer has a share of a configurable token budget.
 */
final class ContextBuilder
{
    public function __construct(
        private readonly PromptManager $prompts,
        private readonly TaskService $tasks,
        private readonly GoalService $goals,
        private readonly SettingsService $settings,
        private readonly CheckpointService $checkpoints,
        private readonly DailySummaryService $summaries,
        private readonly MemoryRetriever $memories,
        private readonly ConversationService $conversations,
        private readonly TokenEstimator $tokens,
    ) {}

    /** @return list<array<string, mixed>> OpenAI chat messages */
    public function build(User $user, Message $current, ?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::now();
        $tz = $user->timezone;
        $budget = (int) config('companion.agent.context_token_budget');
        $nowLocal = $now->setTimezone($tz)->format('l Y-m-d H:i');
        $settings = $user->settingsOrDefault();

        $messages = [['role' => 'system', 'content' => $this->prompts->system($user->displayName(), $tz, $nowLocal, $settings->reply_language, $settings->tone)]];

        // Task titles are user-originated text: fit first, then mark the whole block as data.
        $structured = PromptManager::untrusted($this->tokens->fit($this->structured($user, $now), (int) ($budget * 0.4)));
        $messages[] = ['role' => 'system', 'content' => "STRUCTURED RECORDS (authoritative, from the database):\n".$structured];

        $memoryBlock = $this->memoryBlock($user, (string) $current->text, (int) ($budget * 0.2));
        if ($memoryBlock !== null) {
            $messages[] = ['role' => 'system', 'content' => "RELEVANT LONG-TERM MEMORIES (may be outdated; prefer structured records when they conflict):\n".PromptManager::untrusted($memoryBlock)];
        }

        $used = $this->tokens->estimate(implode("\n", array_column($messages, 'content')));
        $historyBudget = max(500, $budget - $used - $this->tokens->estimate((string) $current->text));
        foreach ($this->history($user, $current, $historyBudget) as $m) {
            $messages[] = $m;
        }

        $messages[] = ['role' => 'user', 'content' => $this->truncateUserText((string) $current->text, $current)];

        return $messages;
    }

    public function structured(User $user, CarbonImmutable $now): string
    {
        $tz = $user->timezone;
        $today = $now->setTimezone($tz)->toDateString();
        $yesterday = $now->setTimezone($tz)->subDay()->toDateString();
        $tomorrow = $now->setTimezone($tz)->addDay()->toDateString();
        $describe = fn ($t) => $this->tasks->describe($t);

        $todayTasks = $this->tasks->forLocalDate($user, $today);
        $tomorrowTasks = $this->tasks->forLocalDate($user, $tomorrow)
            ->filter(fn ($t) => $t->isOpen() && ! $todayTasks->contains('id', $t->id));
        $overdue = $this->tasks->overdueAsOf($user, $today);
        $otherOpen = $this->tasks->open($user, 25)
            ->reject(fn ($t) => $todayTasks->contains('id', $t->id) || $tomorrowTasks->contains('id', $t->id) || $overdue->contains('id', $t->id));

        $y = $this->summaries->compute($user, $yesterday);
        $yesterdayView = [
            'confirmed_completed' => array_map(fn ($c) => $c['title'], $y['confirmed_completed']),
            'progress' => array_map(fn ($p) => $p['title'].($p['note'] ? " ({$p['note']})" : ''), $y['progress_updates']),
            'blockers' => array_map(fn ($b) => $b['title'].': '.$b['blocker'].($b['still_blocked'] ? ' [still blocked]' : ''), $y['blockers']),
            'planned_but_not_confirmed' => array_map(fn ($p) => $p['title'], array_filter($y['planned'], fn ($p) => ! $p['confirmed_done'])),
        ];

        $pending = $user->pendingActions()->where('status', PendingActionStatus::Pending->value)->where('expires_at', '>', $now)
            ->latest('id')->limit(5)->get()->map(fn ($p) => ['pending_action_id' => $p->id, 'summary' => $p->summary])->all();

        $data = [
            'dates' => ['yesterday' => $yesterday, 'today' => $today, 'tomorrow' => $tomorrow],
            'notification_settings' => $this->settings->describe($user),
            'active_goals' => $this->goals->active($user)->map(fn ($g) => ['id' => $g->id, 'title' => $g->title, 'priority' => $g->priority->value, 'target_date' => $g->target_date?->toDateString()])->all(),
            'today_tasks' => $todayTasks->map($describe)->values()->all(),
            'overdue_from_earlier_days' => $overdue->map($describe)->values()->all(),
            'tomorrow_tasks' => $tomorrowTasks->map($describe)->values()->all(),
            'other_open_tasks' => $otherOpen->map($describe)->values()->all(),
            'yesterday' => $yesterdayView,
            'pending_confirmations' => $pending,
            'upcoming_checkins' => $this->checkpoints->upcoming($user, 5)->map(fn ($c) => [
                'checkin_id' => $c->id, 'kind' => $c->kind->value, 'at' => LocalTime::human($c->earliest_at, $tz), 'task_id' => $c->task_id,
            ])->all(),
        ];

        return (string) json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    }

    private function memoryBlock(User $user, string $query, int $budget): ?string
    {
        $hits = $this->memories->retrieve($user, $query, (int) config('companion.agent.memory_results'));
        if ($hits === []) {
            return null;
        }
        $lines = array_map(fn (RetrievedMemory $m) => "- [ref {$m->localId}, {$m->category}, recorded {$m->recordedAt}, {$m->verification}] {$m->content}", $hits);

        return $this->tokens->fit(implode("\n", $lines), $budget);
    }

    /** @return list<array{role: string, content: string}> */
    private function history(User $user, Message $current, int $budget): array
    {
        $recent = $this->conversations->recent($user, (int) config('companion.agent.recent_message_limit'), $current->id);
        $out = [];
        $used = 0;
        foreach ($recent->reverse() as $m) { // newest first so trimming drops the oldest
            $role = $m->role === MessageRole::User ? 'user' : 'assistant';
            $stamp = $m->created_at->setTimezone($user->timezone)->format('D H:i');
            $content = "[{$stamp}] ".mb_substr((string) $m->text, 0, 1500);
            $cost = $this->tokens->estimate($content);
            if ($used + $cost > $budget) {
                break;
            }
            $used += $cost;
            array_unshift($out, ['role' => $role, 'content' => $content]);
        }

        return $out;
    }

    private function truncateUserText(string $text, Message $current): string
    {
        $max = (int) config('companion.agent.max_user_message_chars');
        $prefix = $current->source->value === 'telegram_voice' ? '[voice note transcript] ' : '';

        return $prefix.(mb_strlen($text) > $max ? mb_substr($text, 0, $max).' …[truncated]' : $text);
    }
}
