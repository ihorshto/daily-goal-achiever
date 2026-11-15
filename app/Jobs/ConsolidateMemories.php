<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Memory\MemoryConsolidationService;
use App\Models\User;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class ConsolidateMemories implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public function __construct(public int $userId)
    {
        $this->onQueue('low');
    }

    public function uniqueId(): string
    {
        return (string) $this->userId;
    }

    public function handle(MemoryConsolidationService $service): void
    {
        $user = User::query()->find($this->userId);
        if ($user !== null) {
            Log::info('memory.consolidated', ['user_id' => $user->id] + $service->consolidate($user));
        }
    }
}
