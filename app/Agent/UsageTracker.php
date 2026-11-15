<?php

declare(strict_types=1);

namespace App\Agent;

use App\Agent\OpenAI\ChatResponse;
use App\Agent\OpenAI\CostEstimator;
use App\Models\AgentRun;
use App\Models\User;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/** Records every LLM interaction (tokens, estimated cost, latency) in agent_runs. */
final class UsageTracker
{
    public function __construct(private readonly CostEstimator $cost) {}

    public function start(User $user, string $trigger, ?int $messageId = null, ?string $model = null): AgentRun
    {
        return AgentRun::create([
            'user_id' => $user->id,
            'message_id' => $messageId,
            'trigger' => $trigger,
            'correlation_id' => (string) (Context::get('correlation_id') ?? Str::uuid()),
            'model' => $model,
            'status' => 'running',
        ]);
    }

    public function record(AgentRun $run, ChatResponse $response): void
    {
        $run->iterations++;
        $run->prompt_tokens += $response->promptTokens;
        $run->completion_tokens += $response->completionTokens;
        $run->estimated_cost_usd = $this->cost->chat($run->prompt_tokens, $run->completion_tokens);
        $run->model = $response->model;
        $run->save();
    }

    public function addCost(AgentRun $run, float $usd): void
    {
        $run->estimated_cost_usd += $usd;
        $run->save();
    }

    public function finish(AgentRun $run, string $status, ?string $error = null): void
    {
        $run->status = $status;
        $run->error = $error !== null ? mb_substr($error, 0, 255) : null;
        $run->latency_ms = (int) $run->created_at->diffInMilliseconds(now());
        $run->save();
        Log::info('agent.run', [
            'run_id' => $run->id,
            'trigger' => $run->trigger,
            'status' => $status,
            'iterations' => $run->iterations,
            'tool_calls' => $run->tool_call_count,
            'prompt_tokens' => $run->prompt_tokens,
            'completion_tokens' => $run->completion_tokens,
            'cost_usd' => $run->estimated_cost_usd,
            'latency_ms' => $run->latency_ms,
        ]);
    }
}
