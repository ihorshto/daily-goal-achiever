<?php

declare(strict_types=1);

namespace App\Domain\Data;

use App\Enums\Commitment;
use App\Enums\Priority;
use Carbon\CarbonImmutable;

/** Validated, typed input for creating a task (never raw model output). */
final readonly class TaskDraft
{
    public function __construct(
        public string $title,
        public Commitment $commitment = Commitment::Intention,
        public Priority $priority = Priority::Normal,
        public ?string $notes = null,
        public ?CarbonImmutable $dueAt = null,
        public ?string $dueDate = null,
        public ?string $plannedForDate = null,
        public ?int $goalId = null,
        public ?int $parentTaskId = null,
        public ?string $projectName = null,
    ) {}
}
