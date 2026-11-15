<?php

namespace Tests\Feature;

use App\Models\Message;
use Tests\Fakes\FakeLlmClient;
use Tests\TestCase;

/** Scenario 8: voice notes are transcribed, answered by text, temp files removed. */
class VoiceMessageTest extends TestCase
{
    private function voiceUpdate(array $voice): array
    {
        $update = $this->textUpdate('x');
        unset($update['message']['text']);
        $update['message']['voice'] = $voice + ['file_id' => 'FILE123', 'file_unique_id' => 'U1', 'duration' => 12, 'mime_type' => 'audio/ogg', 'file_size' => 20480];

        return $update;
    }

    private function voiceDirFiles(): array
    {
        $dir = storage_path('app/private/voice');

        return is_dir($dir) ? array_values(array_diff(scandir($dir), ['.', '..'])) : [];
    }

    public function test_voice_note_is_transcribed_processed_and_cleaned_up(): void
    {
        $this->llm->transcript = 'Tomorrow I need to finish the API docs';
        $this->llm->push('agent', function ($req) {
            $this->assertStringContainsString('[voice note transcript] Tomorrow I need to finish the API docs', $req->messages[array_key_last($req->messages)]['content']);

            return FakeLlmClient::text('Got it — API docs tomorrow.');
        });

        $this->webhook($this->voiceUpdate([]))->assertOk()->assertJson(['outcome' => 'dispatched']);

        $this->assertSame(1, $this->llm->transcribeCalls);
        $this->assertFileDoesNotExist($this->llm->lastTranscribedPath);
        $this->assertSame([], $this->voiceDirFiles());
        $msg = Message::where('source', 'telegram_voice')->firstOrFail();
        $this->assertSame('Tomorrow I need to finish the API docs', $msg->text);
        $this->assertSame('processed', $msg->processing_state->value);
        $this->assertSame('Got it — API docs tomorrow.', $this->telegram->lastText());
    }

    public function test_oversized_or_unsupported_voice_is_rejected_without_download(): void
    {
        config(['companion.telegram.max_voice_bytes' => 1000]);
        $this->webhook($this->voiceUpdate(['file_size' => 5000]))->assertOk();
        $this->webhook($this->voiceUpdate(['file_id' => 'F2', 'mime_type' => 'application/x-msdownload', 'file_size' => 10]))->assertOk();
        $this->assertSame(0, $this->llm->transcribeCalls);
        $this->assertFalse(collect($this->telegram->calls)->contains('method', 'downloadFile'));
        $texts = array_column($this->telegram->sent(), 'text');
        $this->assertStringContainsString('too large', $texts[0]);
        $this->assertStringContainsString('unsupported format', $texts[1]);
    }

    public function test_file_that_is_not_audio_is_rejected_and_deleted(): void
    {
        $this->telegram->downloadBytes = "MZ\x90\x00 not audio";
        $this->webhook($this->voiceUpdate([]))->assertOk();
        $this->assertSame(0, $this->llm->transcribeCalls);
        $this->assertSame([], $this->voiceDirFiles());
        $this->assertStringContainsString('not a valid audio file', $this->telegram->lastText());
    }
}
