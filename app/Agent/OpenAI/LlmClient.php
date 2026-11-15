<?php

declare(strict_types=1);

namespace App\Agent\OpenAI;

/** Provider-agnostic LLM port. The OpenAI adapter is the production implementation. */
interface LlmClient
{
    /** @throws LlmException */
    public function chat(ChatRequest $request): ChatResponse;

    /** @throws LlmException */
    public function transcribe(string $path, string $filename, string $mimeType): Transcription;
}
