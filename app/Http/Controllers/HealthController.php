<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\MemoryStatus;
use App\Memory\MemoryProvider;
use App\Models\Memory;
use App\Models\NotificationLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Throwable;

/** Detailed readiness for operators (token-protected). Liveness stays at /up. */
final class HealthController
{
    public function __invoke(Request $request, MemoryProvider $memory): JsonResponse
    {
        $token = (string) config('companion.health.token');
        if ($token === '' || ! hash_equals($token, (string) $request->bearerToken())) {
            return response()->json(['ok' => false], 404);
        }

        $checks = [];
        $checks['database'] = $this->probe(fn () => DB::select('select 1') !== []);
        $checks['cache'] = $this->probe(function () {
            Cache::put('health:probe', 'ok', 10);

            return Cache::get('health:probe') === 'ok'; // strings: Redis returns numerics as strings
        });
        $checks['memory_provider'] = $memory->healthy();
        $checks['openai_configured'] = config('companion.openai.api_key') !== null && config('companion.openai.chat_model') !== null;
        $checks['telegram_configured'] = config('companion.telegram.bot_token') !== null && config('companion.telegram.webhook_secret') !== null;

        $metrics = $this->probe(fn () => [
            'queue_sizes' => [
                'high' => Queue::size('high'),
                'default' => Queue::size('default'),
                'memory' => Queue::size('memory'),
            ],
            'failed_jobs' => DB::table('failed_jobs')->count(),
            'memories_pending_sync' => Memory::query()->whereIn('status', [MemoryStatus::PendingSync->value, MemoryStatus::SyncFailed->value])->count(),
            'notifications_failed_24h' => NotificationLog::query()->whereIn('status', ['failed', 'unknown'])->where('updated_at', '>=', now()->subDay())->count(),
        ]);

        $ok = $checks['database'] === true && $checks['cache'] === true;

        return response()->json(['ok' => $ok, 'degraded' => $ok && ! $checks['memory_provider'], 'checks' => $checks, 'metrics' => $metrics], $ok ? 200 : 503);
    }

    private function probe(callable $fn): mixed
    {
        try {
            return $fn();
        } catch (Throwable) {
            return false;
        }
    }
}
