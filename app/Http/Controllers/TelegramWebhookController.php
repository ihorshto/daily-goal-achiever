<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Telegram\TelegramUpdateRouter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

final class TelegramWebhookController
{
    public function __invoke(Request $request, TelegramUpdateRouter $router): JsonResponse
    {
        $expected = (string) config('companion.telegram.webhook_secret');
        if ($expected === '') {
            Log::error('telegram.webhook_secret_missing');

            return response()->json(['ok' => false], 503); // fail closed
        }
        $given = (string) $request->header('X-Telegram-Bot-Api-Secret-Token', '');
        if (! hash_equals($expected, $given)) {
            Log::warning('telegram.webhook_bad_secret', ['ip' => $request->ip()]);

            return response()->json(['ok' => false], 401);
        }

        $update = $request->json()->all();
        Context::add('correlation_id', (string) Str::uuid());
        Context::add('telegram_update_id', $update['update_id'] ?? null);

        $outcome = $router->route($update);

        // Always 200 once authenticated & persisted so Telegram doesn't retry handled updates.
        return response()->json(['ok' => true, 'outcome' => $outcome]);
    }
}
