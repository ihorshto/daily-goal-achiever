<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\User;
use App\Telegram\CallbackQueryHandler;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;

class HandleCallbackQuery implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @param array{id: string, data: string, chat_id: int, message_id: int|null, message_date: int|null, update_id: int} $callback */
    public function __construct(public int $userId, public array $callback)
    {
        $this->onQueue('high');
    }

    public function middleware(): array
    {
        return [(new WithoutOverlapping('agent-user:'.$this->userId))->releaseAfter(3)->expireAfter(60)];
    }

    public function handle(CallbackQueryHandler $handler): void
    {
        $user = User::query()->find($this->userId);
        if ($user !== null) {
            $handler->handle($user, $this->callback);
        }
    }
}
