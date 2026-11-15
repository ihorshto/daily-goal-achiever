<?php

declare(strict_types=1);

namespace App\Enums;

enum PendingActionType: string
{
    case DeadlineChange = 'deadline_change';
    case PriorityChange = 'priority_change';
    case TaskCancellation = 'task_cancellation';
    case GoalPriorityChange = 'goal_priority_change';
    case DataDeletion = 'data_deletion';
}
