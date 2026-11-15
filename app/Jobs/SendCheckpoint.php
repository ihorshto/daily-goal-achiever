<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Proactive\FollowUpDispatcher;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/** Processes one atomically claimed checkpoint (claim token = ownership). */
class SendCheckpoint implements ShouldQueue
{
    use Queueable;

    /** A failed attempt is recovered by recoverStaleClaims(), not by blind job retries. */
    public int $tries = 1;

    public int $timeout = 120;

    public function __construct(public int $checkpointId, public string $claimToken)
    {
        $this->onQueue('default');
    }

    public function handle(FollowUpDispatcher $dispatcher): void
    {
        $outcome = $dispatcher->dispatch($this->checkpointId, $this->claimToken);
        Log::info('proactive.dispatched', ['checkpoint_id' => $this->checkpointId, 'outcome' => $outcome]);
    }
}
