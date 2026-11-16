<?php

namespace Tests\Unit;

use App\Telegram\TelegramApiClient;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class TelegramSendDocumentTest extends TestCase
{
    public function test_send_document_posts_a_multipart_upload(): void
    {
        Http::fake(['*/sendDocument' => Http::response(['ok' => true, 'result' => ['message_id' => 42]])]);

        $result = (new TelegramApiClient)->sendDocument(111, 'demo.ics', "BEGIN:VCALENDAR\r\n", 'text/calendar', 'Caption');

        $this->assertTrue($result->ok);
        $this->assertSame(42, $result->messageId());
        Http::assertSent(function (Request $request) {
            return str_ends_with($request->url(), '/bottest-token/sendDocument')
                && $request->isMultipart()
                && str_starts_with($request->header('Content-Type')[0] ?? '', 'multipart/form-data')
                && str_contains($request->body(), 'filename="demo.ics"')
                && str_contains($request->body(), 'Content-Type: text/calendar')
                && str_contains($request->body(), "BEGIN:VCALENDAR\r\n");
        });
    }
}
