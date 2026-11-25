<?php

declare(strict_types=1);

namespace App\Agent;

use App\Agent\OpenAI\ChatRequest;
use App\Agent\OpenAI\LlmClient;
use App\Agent\OpenAI\LlmException;
use App\Models\Message;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;

/**
 * Runs one bounded agent turn: context → model → (validated tool calls)* → final text.
 * Limits: iterations, tool calls, wall-clock runtime, completion tokens, daily budget.
 * Retryable LLM errors propagate so the queue retries the job (tool effects replay idempotently).
 */
final class AgentOrchestrator
{
    public function __construct(
        private readonly LlmClient $llm,
        private readonly ContextBuilder $context,
        private readonly ToolRegistry $registry,
        private readonly ToolExecutor $executor,
        private readonly UsageTracker $usage,
        private readonly BudgetGuard $budget,
    ) {}

    public function respond(User $user, Message $inbound, string $trigger = 'user_message'): AgentReply
    {
        $model = (string) config('companion.openai.chat_model');
        if ($model === '') {
            Log::error('agent.model_not_configured');

            return new AgentReply('I am not fully configured yet (no chat model set). Your message is saved.', [], null, 'not_configured');
        }
        if ($this->budget->exceeded($user)) {
            return new AgentReply(
                "I've reached today's AI usage budget, so I can't think this through right now. Your message is saved and buttons still work; I'll be back to full speed tomorrow.",
                [], null, 'budget_exceeded',
            );
        }

        $run = $this->usage->start($user, $trigger, $inbound->id, $model);
        $ctx = new ToolContext($user, $inbound, $run, $trigger, CarbonImmutable::now());
        $tools = $this->registry->definitionsFor($ctx);
        $stickersAvailable = in_array('send_sticker', array_column(array_column($tools, 'function'), 'name'), true);
        $messages = $this->context->build($user, $inbound, null, $stickersAvailable);

        $maxIterations = (int) config('companion.agent.max_tool_iterations');
        $maxCalls = (int) config('companion.agent.max_tool_calls_per_run');
        $deadline = microtime(true) + (int) config('companion.agent.max_runtime_seconds');
        $callCount = 0;
        $final = null;

        try {
            for ($i = 0; $i < $maxIterations; $i++) {
                $allowTools = $callCount < $maxCalls && microtime(true) < $deadline && $i < $maxIterations - 1;
                $response = $this->llm->chat(new ChatRequest(
                    model: $model,
                    messages: $messages,
                    tools: $allowTools ? $tools : [],
                    maxCompletionTokens: (int) config('companion.openai.max_completion_tokens'),
                    temperature: config('companion.openai.temperature'),
                    purpose: 'agent',
                ));
                $this->usage->record($run, $response);

                if ($response->refusal !== null) {
                    $final = 'I can\'t help with that one.';
                    break;
                }
                if ($response->toolCalls === []) {
                    $final = $response->content !== null ? trim($response->content) : null;
                    break;
                }

                $messages[] = $response->assistantMessage();
                foreach ($response->toolCalls as $call) {
                    $callCount++;
                    $result = $callCount > $maxCalls
                        ? ToolResult::error('Tool call limit reached for this turn; answer with what you have.')
                        : $this->executor->execute($call, $ctx);
                    $messages[] = ['role' => 'tool', 'tool_call_id' => $call->id, 'content' => $result->toJson()];
                }
                if ($this->budget->exceeded($user)) {
                    $messages[] = ['role' => 'system', 'content' => 'Budget nearly exhausted: give your final answer now without tools.'];
                }
            }
        } catch (LlmException $e) {
            $this->usage->finish($run, 'failed', $e->getMessage());
            if ($e->retryable) {
                throw $e;
            }

            return new AgentReply('Sorry, I had trouble reaching my language model. Your message is saved; please try again in a moment.', $ctx->keyboard, $run->id, 'llm_error');
        }

        if ($final === null || $final === '') {
            $this->usage->finish($run, 'failed', 'no_final_text');
            Log::warning('agent.no_final_text', ['run_id' => $run->id]);
            $final = $ctx->keyboard !== []
                ? 'Please confirm using the buttons below.'
                : 'Sorry, I could not put together a proper answer. Could you rephrase?';

            return new AgentReply($final, $ctx->keyboard, $run->id, 'fallback');
        }

        $this->usage->finish($run, 'completed');

        return new AgentReply($final, $ctx->keyboard, $run->id, 'completed');
    }
}
