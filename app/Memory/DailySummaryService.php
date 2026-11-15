<?php

declare(strict_types=1);

namespace App\Memory;

use App\Agent\BudgetGuard;
use App\Agent\OpenAI\ChatRequest;
use App\Agent\OpenAI\LlmClient;
use App\Agent\PromptManager;
use App\Agent\UsageTracker;
use App\Domain\TaskService;
use App\Enums\MemoryCategory;
use App\Enums\MemoryVerification;
use App\Enums\TaskStatus;
use App\Models\ActivityEvent;
use App\Models\DailySummary;
use App\Models\Task;
use App\Models\User;
use App\Support\LocalTime;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Builds a per-local-day record of planned vs confirmed work from the append-only event log.
 * The structured part is deterministic and source-referenced; the narrative is optional LLM prose.
 */
final class DailySummaryService
{
    public function __construct(
        private readonly TaskService $tasks,
        private readonly LlmClient $llm,
        private readonly PromptManager $prompts,
        private readonly UsageTracker $usage,
        private readonly BudgetGuard $budget,
        private readonly MemoryLedger $ledger,
    ) {}

    /**
     * Live (not persisted) structured view of a local day — used for "what did I do yesterday?".
     *
     * @return array<string, mixed>
     */
    public function compute(User $user, string $localDate): array
    {
        $tz = $user->timezone;
        $start = LocalTime::startOfLocalDay($localDate, $tz);
        $end = LocalTime::endOfLocalDay($localDate, $tz);

        $events = ActivityEvent::query()
            ->where('user_id', $user->id)
            ->where('subject_type', 'task')
            ->whereBetween('occurred_at', [$start, $end])
            ->orderBy('occurred_at')
            ->get();
        $taskIds = $events->pluck('subject_id')->filter()->unique();
        $taskMap = $user->tasks()->whereIn('id', $taskIds)->get()->keyBy('id');

        $confirmed = [];
        $progress = [];
        $blockers = [];
        foreach ($events as $e) {
            $title = $taskMap[$e->subject_id]->title ?? "task #{$e->subject_id}";
            $at = $e->occurred_at->setTimezone($tz)->format('H:i');
            match ($e->event_type) {
                'completed' => $confirmed[] = ['task_id' => $e->subject_id, 'title' => $title, 'at' => $at, 'evidence' => $e->note, 'event_id' => $e->id],
                'progress', 'unblocked' => $progress[] = ['task_id' => $e->subject_id, 'title' => $title, 'at' => $at, 'note' => $e->note, 'event_id' => $e->id],
                'blocked' => $blockers[] = ['task_id' => $e->subject_id, 'title' => $title, 'at' => $at, 'blocker' => $e->new_values['blocker'] ?? $e->note,
                    'still_blocked' => ($taskMap[$e->subject_id] ?? null)?->status === TaskStatus::Blocked, 'event_id' => $e->id],
                default => null,
            };
        }

        $planned = $this->tasks->forLocalDate($user, $localDate)
            ->filter(fn (Task $t) => $t->planned_for_date?->toDateString() === $localDate
                || $t->due_date?->toDateString() === $localDate
                || ($t->due_at !== null && $t->due_at->betweenIncluded($start, $end)))
            ->map(fn (Task $t) => [
                'task_id' => $t->id,
                'title' => $t->title,
                'commitment' => $t->commitment->value,
                'current_status' => $t->status->value,
                'confirmed_done' => $t->completion_confirmed_at !== null,
            ])->values()->all();

        $openLoops = array_values(array_filter($planned, fn ($p) => in_array($p['current_status'], TaskStatus::openValues(), true)));

        $messageIds = $user->messages()->whereBetween('created_at', [$start, $end])->where('role', 'user')->pluck('id')->all();

        return [
            'local_date' => $localDate,
            'timezone' => $tz,
            'confirmed_completed' => $confirmed,
            'progress_updates' => $progress,
            'blockers' => $blockers,
            'planned' => $planned,
            'open_loops' => $openLoops,
            'source_event_ids' => $events->pluck('id')->all(),
            'source_message_ids' => $messageIds,
            'records_complete' => $events->isNotEmpty() || $messageIds !== [],
        ];
    }

    /** Persist (idempotently) the summary for a finished local day and index it as an episode memory. */
    public function generate(User $user, string $localDate, bool $withNarrative = true): DailySummary
    {
        $data = $this->compute($user, $localDate);
        $narrative = null;
        if ($withNarrative && $data['records_complete'] && ! $this->budget->exceeded($user)) {
            $narrative = $this->narrate($user, $data);
        }

        $summary = DailySummary::query()->updateOrCreate(
            ['user_id' => $user->id, 'local_date' => $localDate],
            [
                'timezone' => $data['timezone'],
                'planned' => $data['planned'],
                'confirmed' => array_merge($data['confirmed_completed'], array_map(fn ($p) => $p + ['type' => 'progress'], $data['progress_updates'])),
                'blockers' => $data['blockers'],
                'open_loops' => $data['open_loops'],
                'narrative' => $narrative,
                'source_event_ids' => $data['source_event_ids'],
                'source_message_ids' => $data['source_message_ids'],
                'generated_at' => now(),
            ],
        );

        // Episode memories make "what happened a month ago" retrievable semantically.
        if ($narrative !== null) {
            $this->ledger->record($user, "On {$localDate}: {$narrative}", MemoryCategory::Episode, 0.9, MemoryVerification::Confirmed, null, true);
        }

        return $summary;
    }

    /** @param array<string, mixed> $data */
    private function narrate(User $user, array $data): ?string
    {
        $model = (string) config('companion.openai.utility_model');
        $run = $this->usage->start($user, 'summary', null, $model);
        try {
            $records = $data;
            unset($records['source_event_ids'], $records['source_message_ids']);
            $response = $this->llm->chat(new ChatRequest(
                model: $model,
                messages: [
                    ['role' => 'system', 'content' => $this->prompts->dailyNarrative()],
                    ['role' => 'user', 'content' => PromptManager::untrusted(json_encode($records, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT))],
                ],
                maxCompletionTokens: 400,
                purpose: 'daily_summary',
            ));
            $this->usage->record($run, $response);
            $this->usage->finish($run, 'completed');
            $text = trim((string) $response->content);

            return $text !== '' ? mb_substr($text, 0, 1200) : null;
        } catch (Throwable $e) {
            $this->usage->finish($run, 'failed', $e->getMessage());
            Log::warning('summary.narrative_failed', ['user_id' => $user->id]);

            return null;
        }
    }
}
