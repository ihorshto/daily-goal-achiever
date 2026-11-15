<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\GenerateDailySummary;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

class QueueDailySummaries extends Command
{
    protected $signature = 'companion:daily-summaries {--date= : Force a local date (YYYY-MM-DD) for all users}';

    protected $description = 'Generate yesterday\'s summary for users whose local time is between 03:00 and 03:59';

    public function handle(): int
    {
        $queued = 0;
        User::query()->where('is_enabled', true)->each(function (User $user) use (&$queued) {
            $local = CarbonImmutable::now($user->timezone);
            if ($this->option('date') === null && $local->hour !== 3) {
                return;
            }
            GenerateDailySummary::dispatch($user->id, $this->option('date') ?? $local->subDay()->toDateString());
            $queued++;
        });
        $this->info("queued={$queued}");

        return self::SUCCESS;
    }
}
