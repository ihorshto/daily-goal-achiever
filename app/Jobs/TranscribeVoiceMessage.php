<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Agent\ConversationService;
use App\Agent\OpenAI\CostEstimator;
use App\Agent\OpenAI\LlmClient;
use App\Agent\OpenAI\LlmException;
use App\Agent\UsageTracker;
use App\Enums\ProcessingState;
use App\Models\Message;
use App\Telegram\TelegramApiClient;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Securely fetches a Telegram voice note, validates it, transcribes it with OpenAI and hands
 * the transcript to the normal message pipeline. The audio never leaves a private temp dir
 * and is always deleted; transcripts are not logged.
 */
class TranscribeVoiceMessage implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 120;

    /** Telegram voice notes are OGG/Opus; audio files may use other OpenAI-supported containers. */
    private const ALLOWED_MIME = ['audio/ogg', 'audio/oga', 'audio/opus', 'audio/mpeg', 'audio/mp4', 'audio/x-m4a', 'audio/m4a', 'audio/wav', 'audio/x-wav', 'audio/webm'];

    public function __construct(public int $messageId)
    {
        $this->onQueue('high');
    }

    /** @return list<int> */
    public function backoff(): array
    {
        return [10, 45];
    }

    public function handle(TelegramApiClient $telegram, LlmClient $llm, UsageTracker $usage, CostEstimator $cost, ConversationService $conversations): void
    {
        $message = Message::query()->with('user')->find($this->messageId);
        if ($message === null || $message->processing_state !== ProcessingState::Transcribing) {
            return;
        }
        $user = $message->user;
        $maxBytes = (int) config('companion.telegram.max_voice_bytes');
        $maxSeconds = (int) config('companion.telegram.max_voice_seconds');

        $problem = match (true) {
            $message->voice_file_id === null => 'missing file',
            ($message->voice_duration_seconds ?? 0) > $maxSeconds => 'too long',
            ($message->voice_file_size ?? 0) > $maxBytes => 'too large',
            $message->voice_mime_type !== null && ! in_array(strtolower($message->voice_mime_type), self::ALLOWED_MIME, true) => 'unsupported format',
            default => null,
        };
        if ($problem !== null) {
            $this->reject($message, $conversations, $problem);

            return;
        }

        $meta = $telegram->getFile((string) $message->voice_file_id);
        $filePath = is_array($meta->result) ? ($meta->result['file_path'] ?? null) : null;
        $reportedSize = is_array($meta->result) ? (int) ($meta->result['file_size'] ?? 0) : 0;
        if (! $meta->ok || ! is_string($filePath)) {
            if (! $meta->isPermanent()) {
                throw new RuntimeException('Telegram getFile failed');
            }
            $this->reject($message, $conversations, 'file unavailable');

            return;
        }
        if ($reportedSize > $maxBytes) {
            $this->reject($message, $conversations, 'too large');

            return;
        }

        $dir = storage_path('app/private/voice');
        if (! is_dir($dir)) {
            mkdir($dir, 0700, true);
        }
        $extension = strtolower(pathinfo($filePath, PATHINFO_EXTENSION)) ?: 'ogg';
        $extension = preg_match('/^[a-z0-9]{1,5}$/', $extension) ? $extension : 'ogg';
        if ($extension === 'oga') {
            $extension = 'ogg';
        }
        $tmp = $dir.'/'.Str::uuid().'.'.$extension;

        $run = $usage->start($user, 'transcription', $message->id, (string) config('companion.openai.transcription_model'));
        try {
            $telegram->downloadFile($filePath, $tmp, $maxBytes);
            if (! $this->looksLikeAudio($tmp)) {
                $usage->finish($run, 'failed', 'invalid_audio');
                $this->reject($message, $conversations, 'not a valid audio file');

                return;
            }
            $transcript = $llm->transcribe($tmp, 'voice.'.$extension, $this->mimeFor($extension));
            $usage->addCost($run, $cost->transcription((int) $message->voice_duration_seconds));
            $usage->finish($run, 'completed');
        } catch (LlmException $e) {
            $usage->finish($run, 'failed', $e->getMessage());
            if ($e->retryable) {
                throw $e;
            }
            $this->reject($message, $conversations, 'transcription failed');

            return;
        } finally {
            if (is_file($tmp)) {
                @unlink($tmp);
            }
        }

        if (trim($transcript->text) === '') {
            $this->reject($message, $conversations, 'no speech detected');

            return;
        }
        Log::info('voice.transcribed', ['message_id' => $message->id, 'chars' => mb_strlen($transcript->text), 'seconds' => $message->voice_duration_seconds]);
        $message->update(['text' => $transcript->text, 'processing_state' => ProcessingState::Received]);
        ProcessInboundMessage::dispatch($message->id, $user->id);
    }

    private function looksLikeAudio(string $path): bool
    {
        $head = (string) file_get_contents($path, false, null, 0, 12);

        return str_starts_with($head, 'OggS')              // ogg/opus
            || str_starts_with($head, 'ID3')               // mp3
            || str_starts_with($head, "\xFF\xFB") || str_starts_with($head, "\xFF\xF3") || str_starts_with($head, "\xFF\xF2")
            || substr($head, 4, 4) === 'ftyp'              // mp4/m4a
            || (str_starts_with($head, 'RIFF') && substr($head, 8, 4) === 'WAVE')
            || str_starts_with($head, "\x1A\x45\xDF\xA3"); // webm
    }

    private function mimeFor(string $extension): string
    {
        return match ($extension) {
            'mp3' => 'audio/mpeg',
            'm4a', 'mp4' => 'audio/mp4',
            'wav' => 'audio/wav',
            'webm' => 'audio/webm',
            default => 'audio/ogg',
        };
    }

    private function reject(Message $message, ConversationService $conversations, string $reason): void
    {
        $message->update(['processing_state' => ProcessingState::Failed, 'processing_error' => 'voice: '.$reason]);
        $reply = $conversations->recordAssistant($message->user, 'reply:'.$message->id, "I couldn't process that voice note ({$reason}). Could you send it as text or a shorter recording?");
        SendTelegramMessage::dispatch($reply->id);
    }

    public function failed(\Throwable $e): void
    {
        $message = Message::query()->with('user')->find($this->messageId);
        if ($message !== null && $message->processing_state === ProcessingState::Transcribing) {
            $this->reject($message, app(ConversationService::class), 'temporary error');
        }
    }
}
