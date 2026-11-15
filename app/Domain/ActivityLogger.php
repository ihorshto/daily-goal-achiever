<?php

declare(strict_types=1);

namespace App\Domain;

use App\Enums\Actor;
use App\Models\ActivityEvent;
use App\Models\User;

/** Writes the append-only event history that backs "what did I do yesterday?" answers. */
final class ActivityLogger
{
    /**
     * @param  array<string, mixed>|null  $old
     * @param  array<string, mixed>|null  $new
     */
    public function record(
        User $user,
        string $subjectType,
        ?int $subjectId,
        string $eventType,
        Actor $actor,
        ?int $sourceMessageId = null,
        ?array $old = null,
        ?array $new = null,
        ?string $note = null,
    ): ActivityEvent {
        return ActivityEvent::create([
            'user_id' => $user->id,
            'subject_type' => $subjectType,
            'subject_id' => $subjectId,
            'event_type' => $eventType,
            'actor' => $actor,
            'source_message_id' => $sourceMessageId,
            'old_values' => $old,
            'new_values' => $new,
            'note' => $note !== null ? mb_substr($note, 0, 2000) : null,
            'occurred_at' => now(),
        ]);
    }
}
