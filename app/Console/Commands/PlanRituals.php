<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\CheckpointService;
use App\Domain\PendingActionService;
use App\Models\User;
use App\Support\LocalTime;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

class PlanRituals extends Command
{
    protected $signature = 'companion:plan-rituals';

    protected $description = 'Ensure morning/evening checkpoints exist for today and tomorrow (local dates) for every enabled user';

    public function handle(CheckpointService $checkpoints, PendingActionService $pending): int
    {
        $count = 0;
        User::query()->where('is_enabled', true)->whereNotNull('telegram_chat_id')->each(function (User $user) use ($checkpoints, &$count) {
            $today = LocalTime::today($user->timezone);
            $tomorrow = CarbonImmutable::parse($today)->addDay()->toDateString();
            $checkpoints->ensureDailyRituals($user, $today);
            $checkpoints->ensureDailyRituals($user, $tomorrow);
            $count++;
        });
        $expired = $pending->expireOverdue();
        $this->info("users={$count} expired_confirmations={$expired}");

        return self::SUCCESS;
    }
}
