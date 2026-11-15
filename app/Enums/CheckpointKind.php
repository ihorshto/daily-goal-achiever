<?php

declare(strict_types=1);

namespace App\Enums;

enum CheckpointKind: string
{
    case MorningPlanning = 'morning_planning';
    case EveningReflection = 'evening_reflection';
    case TaskFollowUp = 'task_followup';
    case DeadlineCheck = 'deadline_check';
    case BlockerFollowUp = 'blocker_followup';
    case Promised = 'promised';

    public function isRitual(): bool
    {
        return in_array($this, [self::MorningPlanning, self::EveningReflection], true);
    }
}
