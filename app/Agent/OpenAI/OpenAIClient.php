<?php

declare(strict_types=1);

namespace App\Agent\OpenAI;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * OpenAI adapter using the Chat Completions API with strict function tools and
 * json_schema structured outputs, plus the audio transcription endpoint.
 * Model IDs come exclusively from configuration.
 */
final class OpenAIClient implements LlmClient
{
    public function chat(ChatRequest $request): ChatResponse
    {
        $payload = [
            'model' => $request->model,
            'messages' => $request->messages,
            'max_completion_tokens' => $request->maxCompletionTokens,
        ];
        if ($request->temperature !== null) {
            $payload['temperature'] = $request->temperature;
        }
        if ($request->tools !== []) {
            $payload['tools'] = $request->tools;
            $payload['tool_choice'] = 'auto';
            // Strict structured tool arguments are not compatible with parallel tool calls.
            $payload['parallel_tool_calls'] = false;
        }
        if ($request->jsonSchema !== null) {
            $payload['response_format'] = [
                'type' => 'json_schema',
                'json_schema' => ['name' => $request->jsonSchema['name'], 'strict' => true, 'schema' => $request->jsonSchema['schema']],
            ];
        }

        $started = microtime(true);
        try {
            $response = $this->http()->post('/chat/completions', $payload);
        } catch (ConnectionException $e) {
            throw new LlmException('OpenAI connection failed', retryable: true);
        }
        $this->guard($response, $request->purpose, $started);

        $choice = $response->json('choices.0') ?? [];
        $message = $choice['message'] ?? [];
        $calls = [];
        foreach ($message['tool_calls'] ?? [] as $call) {
            if (($call['type'] ?? '') !== 'function') {
                continue;
            }
            $calls[] = new ToolCall((string) $call['id'], (string) ($call['function']['name'] ?? ''), (string) ($call['function']['arguments'] ?? '{}'));
        }

        return new ChatResponse(
            content: isset($message['content']) && is_string($message['content']) ? $message['content'] : null,
            toolCalls: $calls,
            finishReason: (string) ($choice['finish_reason'] ?? 'unknown'),
            promptTokens: (int) $response->json('usage.prompt_tokens', 0),
            completionTokens: (int) $response->json('usage.completion_tokens', 0),
            model: (string) $response->json('model', $request->model),
            refusal: $message['refusal'] ?? null,
        );
    }

    public function transcribe(string $path, string $filename, string $mimeType): Transcription
    {
        $model = (string) config('companion.openai.transcription_model');
        if ($model === '') {
            throw new LlmException('OPENAI_TRANSCRIPTION_MODEL is not configured');
        }
        $stream = fopen($path, 'rb');
        if ($stream === false) {
            throw new LlmException('Cannot open audio file');
        }
        $started = microtime(true);
        try {
            $response = $this->http()
                ->attach('file', $stream, $filename, ['Content-Type' => $mimeType])
                ->post('/audio/transcriptions', ['model' => $model, 'response_format' => 'json']);
        } catch (ConnectionException) {
            throw new LlmException('OpenAI connection failed', retryable: true);
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }
        $this->guard($response, 'transcription', $started);

        return new Transcription(trim((string) $response->json('text', '')), $model);
    }

    private function http(): PendingRequest
    {
        $key = (string) config('companion.openai.api_key');
        if ($key === '') {
            throw new LlmException('OPENAI_API_KEY is not configured');
        }

        return Http::baseUrl(rtrim((string) config('companion.openai.base_url'), '/'))
            ->withToken($key)
            ->acceptJson()
            ->timeout((int) config('companion.openai.timeout_seconds'))
            ->connectTimeout(10);
    }

    private function guard(Response $response, string $purpose, float $started): void
    {
        $latency = (int) ((microtime(true) - $started) * 1000);
        if ($response->successful()) {
            Log::info('openai.request', ['purpose' => $purpose, 'status' => $response->status(), 'latency_ms' => $latency]);

            return;
        }
        $status = $response->status();
        // Never log request bodies (user content); the error type/code is enough to debug.
        Log::warning('openai.error', [
            'purpose' => $purpose,
            'status' => $status,
            'latency_ms' => $latency,
            'error_type' => $response->json('error.type'),
            'error_code' => $response->json('error.code'),
        ]);
        throw new LlmException(
            'OpenAI request failed ('.$status.': '.($response->json('error.code') ?? $response->json('error.type') ?? 'error').')',
            retryable: $status === 429 || $status >= 500,
            status: $status,
        );
    }
}
