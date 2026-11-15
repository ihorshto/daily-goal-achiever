<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Memory\DailySummaryService;
use App\Models\User;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class GenerateDailySummary implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(public int $userId, public string $localDate)
    {
        $this->onQueue('low');
    }

    public function uniqueId(): string
    {
        return $this->userId.':'.$this->localDate;
    }

    public function handle(DailySummaryService $summaries): void
    {
        $user = User::query()->find($this->userId);
        if ($user !== null && $user->is_enabled) {
            $summaries->generate($user, $this->localDate);
        }
    }
}
