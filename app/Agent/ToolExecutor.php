<?php

declare(strict_types=1);

namespace App\Agent;

use App\Agent\OpenAI\ToolCall;
use App\Domain\DomainException;
use App\Models\ToolExecution;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Throwable;

/**
 * Validates, authorizes and executes model tool calls. Every execution is recorded with an
 * idempotency key derived from (source message, tool, canonical args): if a queue retry
 * re-runs the same turn, successful tool effects are replayed from the log, not re-applied.
 */
final class ToolExecutor
{
    public function __construct(
        private readonly ToolRegistry $registry,
        private readonly ActionAuthorizationPolicy $policy,
    ) {}

    public function execute(ToolCall $call, ToolContext $context): ToolResult
    {
        $started = microtime(true);
        $tool = $this->registry->get($call->name);
        if ($tool === null) {
            return ToolResult::error("Unknown tool [{$call->name}].");
        }

        $args = json_decode($call->argumentsJson, true);
        if (! is_array($args)) {
            return ToolResult::error('Arguments were not valid JSON.');
        }

        $validator = Validator::make($args, $tool->rules());
        if ($validator->fails()) {
            return ToolResult::error('Invalid arguments: '.implode(' ', $validator->errors()->all()));
        }
        $args = array_intersect_key($args, $tool->rules()) + array_fill_keys(array_keys($tool->rules()), null);

        if (($reason = $this->policy->deny($tool, $context)) !== null) {
            $this->log($context, $tool->name(), 'x:'.Str::uuid(), 'rejected', $args, ['error' => $reason], $started);

            return ToolResult::error($reason);
        }

        $key = $this->key($context, $tool->name(), $args);
        if ($tool->mutates()) {
            $previous = ToolExecution::query()->where('idempotency_key', $key)->where('status', 'ok')->first();
            if ($previous !== null) {
                Log::info('tool.replayed', ['tool' => $tool->name()]);

                return new ToolResult(true, ($previous->result ?? []) + ['replayed' => true]);
            }
        }

        try {
            $result = $tool->handle($context, $args);
        } catch (DomainException|InvalidArgumentException $e) {
            $result = ToolResult::error($e->getMessage());
        } catch (Throwable $e) {
            report($e);
            $result = ToolResult::error('Internal error while executing the tool; no further changes were made.');
        }

        $context->run->increment('tool_call_count');
        $this->log($context, $tool->name(), $result->ok && $tool->mutates() ? $key : 'x:'.Str::uuid(), $result->ok ? 'ok' : 'error', $args, $result->toArray(), $started);

        return $result;
    }

    /** @param array<string, mixed> $args */
    private function key(ToolContext $context, string $tool, array $args): string
    {
        ksort($args);
        $anchor = $context->sourceMessage !== null ? 'm'.$context->sourceMessage->id : 'r'.$context->run->id;

        return $anchor.':'.$tool.':'.substr(hash('sha256', (string) json_encode($args)), 0, 40);
    }

    /** @param array<string, mixed> $args @param array<string, mixed> $result */
    private function log(ToolContext $context, string $tool, string $key, string $status, array $args, array $result, float $started): void
    {
        try {
            DB::transaction(fn () => ToolExecution::create([
                'agent_run_id' => $context->run->id,
                'user_id' => $context->user->id,
                'tool_name' => $tool,
                'idempotency_key' => mb_substr($key, 0, 96),
                'status' => $status,
                'arguments' => $args,
                'result' => $result,
                'latency_ms' => (int) ((microtime(true) - $started) * 1000),
            ]));
        } catch (QueryException) {
            // Duplicate key: an identical successful execution was already logged.
        }
        Log::info('tool.executed', ['tool' => $tool, 'status' => $status, 'run_id' => $context->run->id]);
    }
}
