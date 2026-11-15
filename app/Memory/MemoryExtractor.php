<?php

declare(strict_types=1);

namespace App\Memory;

use App\Agent\BudgetGuard;
use App\Agent\OpenAI\ChatRequest;
use App\Agent\OpenAI\LlmClient;
use App\Agent\OpenAI\LlmException;
use App\Agent\PromptManager;
use App\Agent\UsageTracker;
use App\Enums\MemoryCategory;
use App\Enums\MemoryVerification;
use App\Enums\MessageRole;
use App\Models\Message;
use App\Support\LocalTime;
use Illuminate\Support\Facades\Log;

/**
 * Decides which parts of a conversation turn deserve durable memory. The LLM proposes;
 * this class validates (schema, confidence, length, policy) and the ledger persists.
 */
final class MemoryExtractor
{
    private const MAX_PER_TURN = 5;

    private const TRIVIAL = '/^(hi|hey|hello|thanks?|thank you|ok(ay)?|cool|nice|great|yes|no|yep|nope|sure|good (morning|night)|👍|🙏)[\s!.?]*$/iu';

    public function __construct(
        private readonly LlmClient $llm,
        private readonly PromptManager $prompts,
        private readonly MemoryLedger $ledger,
        private readonly MemoryRetriever $retriever,
        private readonly UsageTracker $usage,
        private readonly BudgetGuard $budget,
    ) {}

    /** @return list<string> outcome codes (for logging/tests) */
    public function extract(Message $message): array
    {
        $user = $message->user;
        $text = trim((string) $message->text);
        if ($message->role !== MessageRole::User || mb_strlen($text) < (int) config('companion.memory.extraction_min_chars') || preg_match(self::TRIVIAL, $text)) {
            return ['skipped_trivial'];
        }
        if ($user->data_purged_at !== null && $message->created_at->lessThanOrEqualTo($user->data_purged_at)) {
            return ['skipped_purged'];
        }
        if ($this->budget->exceeded($user)) {
            return ['skipped_budget'];
        }

        $reply = Message::query()
            ->where('conversation_id', $message->conversation_id)
            ->where('role', MessageRole::Assistant->value)
            ->where('id', '>', $message->id)
            ->orderBy('id')
            ->value('text');

        $existing = $this->retriever->retrieve($user, $text, 6);
        $existingBlock = $existing === []
            ? '(none)'
            : implode("\n", array_map(fn (RetrievedMemory $m) => "ref={$m->localId} [{$m->category}, {$m->recordedAt}] {$m->content}", $existing));

        $date = LocalTime::localDate($message->created_at, $user->timezone);
        $userPrompt = "Conversation date: {$date} ({$user->timezone})\n\nEXISTING MEMORIES:\n".PromptManager::untrusted($existingBlock)
            ."\n\nUSER MESSAGE:\n".PromptManager::untrusted($text)
            .($reply ? "\n\nASSISTANT REPLY:\n".PromptManager::untrusted(mb_substr((string) $reply, 0, 1500)) : '');

        $model = (string) config('companion.openai.utility_model');
        $run = $this->usage->start($user, 'memory_extraction', $message->id, $model);
        try {
            $response = $this->llm->chat(new ChatRequest(
                model: $model,
                messages: [
                    ['role' => 'system', 'content' => $this->prompts->memoryExtraction()],
                    ['role' => 'user', 'content' => $userPrompt],
                ],
                jsonSchema: ['name' => 'memory_extraction', 'schema' => self::schema()],
                maxCompletionTokens: 700,
                purpose: 'memory_extraction',
            ));
            $this->usage->record($run, $response);
        } catch (LlmException $e) {
            $this->usage->finish($run, 'failed', $e->getMessage());
            throw $e;
        }

        $data = $response->json();
        if (! is_array($data) || ! is_array($data['memories'] ?? null)) {
            $this->usage->finish($run, 'failed', 'malformed_output');
            Log::warning('memory.extraction_malformed', ['message_id' => $message->id]);

            return ['malformed'];
        }

        $outcomes = [];
        $minConfidence = (float) config('companion.memory.min_confidence');
        $allowedRefs = array_map(fn (RetrievedMemory $m) => $m->localId, $existing);
        foreach (array_slice($data['memories'], 0, self::MAX_PER_TURN) as $item) {
            if (! is_array($item) || ! is_string($item['content'] ?? null)) {
                $outcomes[] = 'invalid_item';

                continue;
            }
            $category = MemoryCategory::tryFrom((string) ($item['category'] ?? ''));
            $verification = MemoryVerification::tryFrom((string) ($item['verification'] ?? ''));
            $confidence = is_numeric($item['confidence'] ?? null) ? (float) $item['confidence'] : 0.0;
            if ($category === null || $verification === null || $verification === MemoryVerification::Confirmed) {
                $outcomes[] = 'invalid_enum';

                continue;
            }
            if ($confidence < $minConfidence) {
                $outcomes[] = 'low_confidence';

                continue;
            }
            // Only allow superseding memories we actually showed (prevents cross-row tampering).
            $supersedes = isset($item['supersedes_memory_ref']) && in_array((int) $item['supersedes_memory_ref'], $allowedRefs, true)
                ? (int) $item['supersedes_memory_ref'] : null;

            [, $outcome] = $this->ledger->record($user, $item['content'], $category, $confidence, $verification, $message->id, false, $supersedes);
            $outcomes[] = $outcome;
        }
        $this->usage->finish($run, 'completed');

        return $outcomes === [] ? ['nothing_to_save'] : $outcomes;
    }

    /** @return array<string, mixed> */
    public static function schema(): array
    {
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['memories'],
            'properties' => [
                'memories' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'additionalProperties' => false,
                        'required' => ['content', 'category', 'confidence', 'verification', 'supersedes_memory_ref'],
                        'properties' => [
                            'content' => ['type' => 'string'],
                            'category' => ['type' => 'string', 'enum' => array_values(array_diff(MemoryCategory::values(), ['episode']))],
                            'confidence' => ['type' => 'number'],
                            'verification' => ['type' => 'string', 'enum' => ['stated', 'inferred']],
                            'supersedes_memory_ref' => ['type' => ['integer', 'null']],
                        ],
                    ],
                ],
            ],
        ];
    }
}
