<?php

declare(strict_types=1);

namespace App\Telegram;

use App\Models\Checkpoint;
use App\Models\PendingActionConfirmation;
use App\Models\Task;

/**
 * Inline keyboard builders. callback_data formats (≤ 64 bytes), all ownership-checked on use:
 *   t:{taskId}:{d|p|b|z}     task done / in progress / blocked / postpone
 *   c:{nonce}:{y|n}          pending-action confirmation (nonce is unguessable)
 *   s:{checkpointId}:{mins}  snooze a check-in
 */
final class Keyboards
{
    /** @return list<array{text: string, callback_data: string}> */
    public static function taskRow(Task $task): array
    {
        return [
            ['text' => '✅ Done', 'callback_data' => "t:{$task->id}:d"],
            ['text' => '⏳ In progress', 'callback_data' => "t:{$task->id}:p"],
            ['text' => '🧱 Blocked', 'callback_data' => "t:{$task->id}:b"],
            ['text' => '📅 Postpone', 'callback_data' => "t:{$task->id}:z"],
        ];
    }

    /** @return list<array{text: string, callback_data: string}> */
    public static function confirmationRow(PendingActionConfirmation $pending): array
    {
        return [
            ['text' => '✔️ Confirm', 'callback_data' => "c:{$pending->nonce}:y"],
            ['text' => '✖️ Cancel', 'callback_data' => "c:{$pending->nonce}:n"],
        ];
    }

    /** @return list<array{text: string, callback_data: string}> */
    public static function snoozeRow(Checkpoint $checkpoint): array
    {
        return [
            ['text' => '⏰ In 1h', 'callback_data' => "s:{$checkpoint->id}:60"],
            ['text' => '🌙 Tomorrow', 'callback_data' => "s:{$checkpoint->id}:1440"],
        ];
    }

    /** @param list<list<array{text: string, callback_data: string}>> $rows */
    public static function markup(array $rows): ?array
    {
        return $rows === [] ? null : ['inline_keyboard' => $rows];
    }
}
