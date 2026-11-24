<?php

declare(strict_types=1);

namespace App\Proactive;

use App\Agent\BudgetGuard;
use App\Agent\ConversationService;
use App\Agent\OpenAI\ChatRequest;
use App\Agent\OpenAI\LlmClient;
use App\Agent\PromptManager;
use App\Agent\UsageTracker;
use App\Domain\TaskService;
use App\Enums\CheckpointKind;
use App\Models\Checkpoint;
use App\Models\User;
use App\Support\LocalTime;
use App\Telegram\Keyboards;
use Carbon\CarbonImmutable;
use Throwable;

/**
 * Composes the content of a due checkpoint from freshly loaded state. The model may recommend
 * skipping (send=false) a stale check-in; it can never override NotificationPolicy.
 * Falls back to deterministic templates if the LLM is unavailable or over budget.
 */
final class ProactivePlanner
{
    public function __construct(
        private readonly LlmClient $llm,
        private readonly PromptManager $prompts,
        private readonly UsageTracker $usage,
        private readonly BudgetGuard $budget,
        private readonly TaskService $tasks,
        private readonly ConversationService $conversations,
        private readonly DailyPlanningService $morning,
        private readonly EveningReflectionService $evening,
    ) {}

    public function compose(User $user, Checkpoint $checkpoint, ?CarbonImmutable $now = null): ProactiveDraft
    {
        $now ??= CarbonImmutable::now();
        $tz = $user->timezone;
        $localDate = $checkpoint->local_date?->toDateString() ?? LocalTime::localDate($now, $tz);
        $task = $checkpoint->task_id !== null ? $checkpoint->task()->first() : null;

        $facts = match ($checkpoint->kind) {
            CheckpointKind::MorningPlanning => $this->morning->facts($user, $localDate),
            CheckpointKind::EveningReflection => $this->evening->facts($user, $localDate),
            default => [
                'checkpoint' => $checkpoint->kind->value,
                'reason' => $checkpoint->reason,
                'task' => $task !== null ? $this->tasks->describe($task) : null,
                'now' => LocalTime::human($now, $tz),
            ],
        };
        $keyboard = [];
        if ($task !== null && $task->isOpen()) {
            $keyboard[] = Keyboards::taskRow($task);
        }
        if (! $checkpoint->kind->isRitual()) {
            $keyboard[] = Keyboards::snoozeRow($checkpoint);
        }

        $fallback = match ($checkpoint->kind) {
            CheckpointKind::MorningPlanning => $this->morning->fallbackText($user, $facts),
            CheckpointKind::EveningReflection => $this->evening->fallbackText($user, $facts),
            CheckpointKind::BlockerFollowUp => "Checking in on \"{$task?->title}\" — is the blocker still in the way? Happy to think through a next step.",
            CheckpointKind::DeadlineCheck => "Quick check on \"{$task?->title}\" (due {$facts['task']['due']}): how's it going?",
            default => $task !== null ? "How's \"{$task->title}\" going?" : ('Checking in: '.($checkpoint->reason ?? 'how are things going?')),
        };

        $model = (string) config('companion.openai.utility_model');
        if ($model === '' || $this->budget->exceeded($user)) {
            return new ProactiveDraft(true, $fallback, $keyboard, 'template', false);
        }

        $recent = $this->conversations->recent($user, 8)->map(fn ($m) => [
            'role' => $m->role->value,
            'at' => $m->created_at->setTimezone($tz)->format('D H:i'),
            'text' => mb_substr((string) $m->text, 0, 400),
        ])->all();

        $run = $this->usage->start($user, 'proactive', null, $model);
        try {
            $response = $this->llm->chat(new ChatRequest(
                model: $model,
                messages: [
                    ['role' => 'system', 'content' => $this->prompts->proactive()."\n".PromptManager::languageRule($user->settingsOrDefault()->reply_language)."\n".PromptManager::toneRule($user->settingsOrDefault()->tone)],
                    ['role' => 'user', 'content' => "User name: {$user->displayName()}\nLocal time: ".LocalTime::human($now, $tz)
                        ."\n\nCHECKPOINT FACTS (current database state):\n".PromptManager::untrusted((string) json_encode($facts, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT))
                        ."\n\nRECENT CONVERSATION:\n".PromptManager::untrusted((string) json_encode($recent, JSON_UNESCAPED_UNICODE))],
                ],
                jsonSchema: ['name' => 'proactive_message', 'schema' => [
                    'type' => 'object',
                    'additionalProperties' => false,
                    'required' => ['send', 'message', 'reason'],
                    'properties' => [
                        'send' => ['type' => 'boolean'],
                        'message' => ['type' => 'string'],
                        'reason' => ['type' => 'string'],
                    ],
                ]],
                maxCompletionTokens: 500,
                purpose: 'proactive',
            ));
            $this->usage->record($run, $response);
            $data = $response->json();
            $this->usage->finish($run, 'completed');
        } catch (Throwable $e) {
            $this->usage->finish($run, 'failed', $e->getMessage());

            return new ProactiveDraft(true, $fallback, $keyboard, 'template_after_error', false);
        }

        if (! is_array($data) || ! is_bool($data['send'] ?? null) || ! is_string($data['message'] ?? null)) {
            return new ProactiveDraft(true, $fallback, $keyboard, 'template_after_malformed', false);
        }
        // Rituals always go out (they are the user's chosen routine); adaptive ones may be skipped as stale.
        if ($data['send'] === false && ! $checkpoint->kind->isRitual()) {
            return new ProactiveDraft(false, '', [], 'llm_skip: '.mb_substr((string) ($data['reason'] ?? ''), 0, 200), true);
        }
        $text = trim($data['message']) !== '' ? mb_substr(trim($data['message']), 0, 1500) : $fallback;

        return new ProactiveDraft(true, $text, $keyboard, null, true);
    }
}
