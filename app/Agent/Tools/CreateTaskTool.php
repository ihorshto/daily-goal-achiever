<?php

declare(strict_types=1);

namespace App\Agent\Tools;

use App\Agent\ToolContext;
use App\Agent\ToolResult;
use App\Domain\Data\TaskDraft;
use App\Domain\TaskService;
use App\Enums\Actor;
use App\Enums\Commitment;
use App\Enums\Priority;
use App\Support\LocalTime;
use Illuminate\Validation\Rule;

final class CreateTaskTool extends BaseTool
{
    public function __construct(private readonly TaskService $tasks) {}

    public function name(): string
    {
        return 'create_task';
    }

    public function description(): string
    {
        return 'Record a task the user mentioned. commitment: "idea" for tentative/maybe items (never chased), "intention" for planned work, "commitment" for explicit promises/deadlines. due = deadline (local "YYYY-MM-DDTHH:MM" or date-only "YYYY-MM-DD"); planned_for_date = the day they intend to work on it.';
    }

    public function parameters(): array
    {
        return $this->object([
            'title' => $this->string('Short actionable title'),
            'commitment' => $this->enum(['idea', 'intention', 'commitment'], 'Strength of commitment'),
            'priority' => $this->enum(['low', 'normal', 'high', 'critical'], 'Priority if the user indicated one', true),
            'due' => $this->string('Deadline in local time, or null', true),
            'planned_for_date' => $this->string('Local date YYYY-MM-DD the user plans to work on it, or null', true),
            'notes' => $this->string('Extra context', true),
            'goal_id' => $this->integer('Existing goal id from context, or null', true),
            'project' => $this->string('Project name, or null', true),
        ]);
    }

    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'min:2', 'max:200'],
            'commitment' => ['required', Rule::enum(Commitment::class)],
            'priority' => ['nullable', Rule::enum(Priority::class)],
            'due' => ['nullable', 'string', 'max:40'],
            'planned_for_date' => ['nullable', 'date_format:Y-m-d'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'goal_id' => ['nullable', 'integer', 'min:1'],
            'project' => ['nullable', 'string', 'max:160'],
        ];
    }

    public function handle(ToolContext $context, array $args): ToolResult
    {
        $tz = $context->user->timezone;
        [$dueAt, $dueDate] = $this->parseWhen($args['due'], $tz);
        if ($dueAt !== null && $dueAt->lessThan($context->now->subMinutes(5))) {
            return ToolResult::error('That deadline is in the past. Ask the user for the intended time.');
        }
        $commitment = Commitment::from($args['commitment']);
        $draft = new TaskDraft(
            title: $args['title'],
            commitment: $commitment,
            priority: $args['priority'] ? Priority::from($args['priority']) : Priority::Normal,
            notes: $args['notes'],
            dueAt: $dueAt,
            dueDate: $dueDate,
            plannedForDate: $args['planned_for_date'],
            goalId: $args['goal_id'],
            projectName: $args['project'],
        );
        $key = $context->sourceMessageId() !== null
            ? 'm'.$context->sourceMessageId().':'.substr(hash('sha256', mb_strtolower(trim($args['title']))), 0, 32)
            : null;
        [$task, $created] = $this->tasks->create($context->user, $draft, Actor::Agent, $context->sourceMessageId(), $key);
        $upcoming = $task->checkpoints()->where('state', 'pending')->orderBy('earliest_at')->get()
            ->map(fn ($c) => ['kind' => $c->kind->value, 'at' => LocalTime::human($c->earliest_at, $tz)])->all();

        return ToolResult::ok([
            'created' => $created,
            'note' => $created ? null : 'An identical open task already existed; no duplicate was created.',
            'task' => $this->tasks->describe($task),
            'scheduled_checkins' => $upcoming,
        ]);
    }
}
