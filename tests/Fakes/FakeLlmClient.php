<?php

namespace Tests\Fakes;

use App\Agent\OpenAI\ChatRequest;
use App\Agent\OpenAI\ChatResponse;
use App\Agent\OpenAI\LlmClient;
use App\Agent\OpenAI\LlmException;
use App\Agent\OpenAI\ToolCall;
use App\Agent\OpenAI\Transcription;
use Closure;

/** Scripted LLM: queue responses per purpose; records every request. No network, no cost. */
class FakeLlmClient implements LlmClient
{
    /** @var array<string, list<ChatResponse|Closure|LlmException>> */
    public array $queues = [];

    /** @var list<ChatRequest> */
    public array $requests = [];

    public string $transcript = 'Transcribed voice text';

    public int $transcribeCalls = 0;

    public ?string $lastTranscribedPath = null;

    public function push(string $purpose, ChatResponse|Closure|LlmException ...$responses): static
    {
        foreach ($responses as $r) {
            $this->queues[$purpose][] = $r;
        }

        return $this;
    }

    public function chat(ChatRequest $request): ChatResponse
    {
        $this->requests[] = $request;
        $this->queues[$request->purpose] ??= [];
        $next = array_shift($this->queues[$request->purpose]);
        if ($next === null) {
            return match ($request->purpose) {
                'memory_extraction' => self::json(['memories' => []]),
                'proactive' => self::json(['send' => true, 'message' => 'Default proactive message', 'reason' => 'default']),
                default => self::text('OK.'),
            };
        }
        if ($next instanceof LlmException) {
            throw $next;
        }

        return $next instanceof Closure ? $next($request) : $next;
    }

    public function transcribe(string $path, string $filename, string $mimeType): Transcription
    {
        $this->transcribeCalls++;
        $this->lastTranscribedPath = $path;

        return new Transcription($this->transcript, 'fake-transcribe');
    }

    public static function text(string $content): ChatResponse
    {
        return new ChatResponse($content, [], 'stop', 100, 20, 'fake-model');
    }

    public static function json(array $data): ChatResponse
    {
        return new ChatResponse(json_encode($data), [], 'stop', 50, 20, 'fake-model');
    }

    /** @param array<string, mixed> ...$calls  each: ['name' => ..., 'args' => [...]] */
    public static function tools(array ...$calls): ChatResponse
    {
        $toolCalls = [];
        foreach ($calls as $i => $c) {
            $toolCalls[] = new ToolCall('call_'.$i.'_'.$c['name'], $c['name'], json_encode($c['args']));
        }

        return new ChatResponse(null, $toolCalls, 'tool_calls', 120, 30, 'fake-model');
    }

    /** @return list<ChatRequest> */
    public function requestsFor(string $purpose): array
    {
        return array_values(array_filter($this->requests, fn (ChatRequest $r) => $r->purpose === $purpose));
    }
}
