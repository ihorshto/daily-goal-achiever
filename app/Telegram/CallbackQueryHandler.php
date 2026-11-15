<?php

declare(strict_types=1);

namespace App\Telegram;

use App\Agent\ConversationService;
use App\Domain\CheckpointService;
use App\Domain\DomainException;
use App\Domain\PendingActionService;
use App\Domain\ProgressService;
use App\Domain\TaskService;
use App\Enums\Actor;
use App\Enums\CheckpointState;
use App\Enums\MessageSource;
use App\Enums\PendingActionType;
use App\Enums\ProcessingState;
use App\Enums\TaskStatus;
use App\Jobs\SendTelegramMessage;
use App\Models\Message;
use App\Models\Task;
use App\Models\User;
use App\Support\LocalTime;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;

/**
 * Handles inline-button presses. Every lookup is scoped to the authenticated user, so a
 * forged/foreign callback_data can never act on another user's records. Button presses are
 * explicit user evidence (e.g. for completion) and are recorded in the transcript.
 */
final class CallbackQueryHandler
{
    public function __construct(
        private readonly TelegramApiClient $telegram,
        private readonly TaskService $tasks,
        private readonly ProgressService $progress,
        private readonly PendingActionService $pending,
        private readonly CheckpointService $checkpoints,
        private readonly ConversationService $conversations,
    ) {}

    /** @param array{id: string, data: string, chat_id: int, message_id: int|null, message_date: int|null, update_id: int} $cb */
    public function handle(User $user, array $cb): void
    {
        $data = $cb['data'];
        $ttlHours = (int) config('companion.telegram.callback_ttl_hours');
        $isStale = $cb['message_date'] !== null && $cb['message_date'] < time() - $ttlHours * 3600;

        try {
            [$ack, $reply] = match (true) {
                (bool) preg_match('/^t:(\d{1,12}):([dpbz])$/', $data, $m) => $isStale ? ['This button has expired.', null] : $this->task($user, (int) $m[1], $m[2], $cb),
                (bool) preg_match('/^c:([A-Za-z0-9]{20}):([yn])$/', $data, $m) => $this->confirm($user, $m[1], $m[2] === 'y', $cb),
                (bool) preg_match('/^s:(\d{1,12}):(60|1440)$/', $data, $m) => $isStale ? ['This button has expired.', null] : $this->snooze($user, (int) $m[1], (int) $m[2], $cb),
                default => ['Unknown action.', null],
            };
        } catch (DomainException $e) {
            [$ack, $reply] = [mb_substr($e->getMessage(), 0, 180), null];
        }

        $this->telegram->answerCallbackQuery($cb['id'], $ack);
        if ($reply !== null) {
            $msg = $this->conversations->recordAssistant($user, 'cb-reply:'.$cb['update_id'], (string) $reply, MessageSource::AgentReply,
                $reply instanceof CallbackReply ? $reply->markup : null);
            SendTelegramMessage::dispatch($msg->id);
        }
    }

    /** @return array{0: string, 1: string|CallbackReply|null} */
    private function task(User $user, int $taskId, string $verb, array $cb): array
    {
        $task = $user->tasks()->whereKey($taskId)->first();
        if ($task === null) {
            return ['That task is not available.', null];
        }
        $evidence = $this->recordPress($user, $cb, match ($verb) {
            'd' => "Done: {$task->title}", 'p' => "In progress: {$task->title}", 'b' => "Blocked: {$task->title}", default => "Postpone: {$task->title}",
        });

        if (! $task->isOpen()) {
            return ["Already {$task->status->value}.", null];
        }

        return match ($verb) {
            'd' => (function () use ($user, $task, $evidence) {
                $this->progress->complete($user, $task, Actor::User, $evidence->id, 'Confirmed via Done button');

                return ['Marked done ✅', "Nice — \"{$task->title}\" is done. I've cleared its reminders."];
            })(),
            'p' => (function () use ($user, $task, $evidence) {
                $this->progress->updateProgress($user, $task, TaskStatus::InProgress, 'Via button', null, null, Actor::User, $evidence->id);

                return ['Noted: in progress', null];
            })(),
            'b' => (function () use ($user, $task, $evidence) {
                $this->progress->updateProgress($user, $task, TaskStatus::Blocked, 'Reported via button', null, 'Reported via button (details pending)', Actor::User, $evidence->id);

                return ['Noted: blocked', "What's blocking \"{$task->title}\"? Tell me and I'll help figure out a next step."];
            })(),
            default => $this->postpone($user, $task, $evidence),
        };
    }

    /** @return array{0: string, 1: string|CallbackReply|null} */
    private function postpone(User $user, Task $task, Message $evidence): array
    {
        $tz = $user->timezone;
        $tomorrow = CarbonImmutable::now($tz)->addDay()->toDateString();
        if ($this->tasks->deadlineChangeNeedsConfirmation($task)) {
            [$dueAt, $dueDate] = $task->due_at !== null
                ? [$task->due_at->addDay(), null]
                : [null, $task->due_date?->addDay()->toDateString() ?? $tomorrow];
            $label = $dueAt !== null ? LocalTime::human($dueAt, $tz) : $dueDate;
            $proposal = $this->pending->propose($user, PendingActionType::DeadlineChange, 'task', $task->id,
                ['due_at' => $dueAt?->toIso8601String(), 'due_date' => $dueDate, 'reason' => 'Postponed via button'],
                "Move committed deadline of \"{$task->title}\" to {$label}", $evidence->id);

            return ['Needs confirmation', new CallbackReply(
                "\"{$task->title}\" has a committed deadline. Move it to {$label}?",
                Keyboards::markup([Keyboards::confirmationRow($proposal)]),
            )];
        }
        $this->tasks->updateDetails($user, $task, ['planned_for_date' => $tomorrow], Actor::User, $evidence->id);
        if ($task->due_at !== null || $task->due_date !== null) {
            $this->tasks->applyDeadlineChange($user, $task, $task->due_at?->addDay(), $task->due_date?->addDay()->toDateString(), Actor::User, $evidence->id, 'Postponed via button');
        }

        return ['Moved to tomorrow 📅', null];
    }

    /** @return array{0: string, 1: string|null} */
    private function confirm(User $user, string $nonce, bool $approve, array $cb): array
    {
        $pending = $user->pendingActions()->where('nonce', $nonce)->first();
        if ($pending === null) {
            return ['That confirmation is not available.', null];
        }
        $evidence = $this->recordPress($user, $cb, ($approve ? 'Confirm: ' : 'Cancel: ').$pending->summary);
        $result = $this->pending->decide($user, $nonce, $approve, 'button', $evidence->id);
        if ($cb['message_id'] !== null) {
            $this->telegram->removeKeyboard($cb['chat_id'], $cb['message_id']);
        }
        if (($result->data['already_decided'] ?? false) === true) {
            return ['Already handled.', null];
        }

        return [$result->ok ? ($approve ? 'Done' : 'Canceled') : 'Not applied', $result->message];
    }

    /** @return array{0: string, 1: string|null} */
    private function snooze(User $user, int $checkpointId, int $minutes, array $cb): array
    {
        $cp = $user->checkpoints()->whereKey($checkpointId)->first();
        if ($cp === null || $cp->state !== CheckpointState::Sent) {
            return ['Nothing to snooze.', null];
        }
        $evidence = $this->recordPress($user, $cb, "Snooze {$minutes} min");
        $at = CarbonImmutable::now()->addMinutes($minutes);
        $this->checkpoints->schedulePromised($user, $at, 'Snoozed: '.($cp->reason ?? 'follow-up'), $cp->task, $evidence->id, "snooze:{$cp->id}:{$minutes}");

        return [$minutes === 60 ? 'Okay, in an hour.' : 'Okay, tomorrow.', null];
    }

    private function recordPress(User $user, array $cb, string $label): Message
    {
        [$message] = $this->conversations->recordInbound($user, 'cb:'.$cb['update_id'], MessageSource::Callback, '[button] '.$label, [
            'telegram_update_id' => $cb['update_id'],
            'processing_state' => ProcessingState::Processed,
        ]);
        Log::info('telegram.callback', ['kind' => substr($cb['data'], 0, 1)]);

        return $message;
    }
}
