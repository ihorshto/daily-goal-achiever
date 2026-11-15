<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\CheckpointService;
use App\Jobs\SendCheckpoint;
use App\Telegram\OutboundMessenger;
use Illuminate\Console\Command;

class DispatchCheckpoints extends Command
{
    protected $signature = 'companion:dispatch-checkpoints {--limit=50}';

    protected $description = 'Recover stale claims, atomically claim due checkpoints and queue them for delivery';

    public function handle(CheckpointService $checkpoints): int
    {
        $recovered = $checkpoints->recoverStaleClaims();
        OutboundMessenger::markStaleAsUnknown();
        $claimed = $checkpoints->claimDue((int) $this->option('limit'));
        foreach ($claimed as $claim) {
            SendCheckpoint::dispatch($claim['id'], $claim['token']);
        }
        $this->info("claimed={$claimed->count()} recovered={$recovered}");

        return self::SUCCESS;
    }
}
