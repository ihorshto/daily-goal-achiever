<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\MemoryStatus;
use App\Jobs\ConsolidateMemories;
use App\Jobs\SyncMemoryToProvider;
use App\Models\Memory;
use App\Models\User;
use Illuminate\Console\Command;

class MemoryMaintenance extends Command
{
    protected $signature = 'companion:memory {action : retry-failed|consolidate|status}';

    protected $description = 'Memory maintenance: retry failed Mem0 syncs, consolidate duplicates, or show sync status';

    public function handle(): int
    {
        return match ($this->argument('action')) {
            'retry-failed' => $this->retry(),
            'consolidate' => $this->consolidate(),
            'status' => $this->status(),
            default => $this->invalid(),
        };
    }

    private function retry(): int
    {
        $n = 0;
        Memory::query()->whereIn('status', [MemoryStatus::PendingSync->value, MemoryStatus::SyncFailed->value])
            ->whereNull('mem0_id')
            ->where('updated_at', '<', now()->subMinutes(5))
            ->where('sync_attempts', '<', (int) config('companion.memory.max_sync_attempts'))
            ->each(function (Memory $m) use (&$n) {
                SyncMemoryToProvider::dispatch($m->id);
                $n++;
            });
        $this->info("requeued={$n}");

        return self::SUCCESS;
    }

    private function consolidate(): int
    {
        User::query()->where('is_enabled', true)->each(fn (User $u) => ConsolidateMemories::dispatch($u->id));

        return self::SUCCESS;
    }

    private function status(): int
    {
        $rows = Memory::query()->selectRaw('status, count(*) as n, max(sync_attempts) as max_attempts')->groupBy('status')->get();
        $this->table(['status', 'count', 'max attempts'], $rows->map(fn ($r) => [$r->status->value, $r->n, $r->max_attempts]));

        return self::SUCCESS;
    }

    private function invalid(): int
    {
        $this->error('Unknown action.');

        return self::INVALID;
    }
}
