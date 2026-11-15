<?php

declare(strict_types=1);

namespace App\Agent\Tools;

use App\Agent\ToolContext;
use App\Agent\ToolResult;
use App\Domain\TaskService;
use App\Models\ActivityEvent;
use App\Support\LocalTime;

final class GetTaskHistoryTool extends BaseTool
{
    public function __construct(private readonly TaskService $tasks) {}

    public function name(): string
    {
        return 'get_task_history';
    }

    public function description(): string
    {
        return 'Source-backed event history (created, progress, blocked, completed, deadline changes) for one task, or for tasks whose title matches a query within the last N days (max 400). Use to answer "why was X delayed?" or "what happened with Y?".';
    }

    public function mutates(): bool
    {
        return false;
    }

    public function parameters(): array
    {
        return $this->object([
            'task_id' => $this->integer('Task id, or null', true),
            'query' => $this->string('Keyword(s) matched against task titles, or null', true),
            'days' => $this->integer('Look-back window in days (default 45)', true),
        ]);
    }

    public function rules(): array
    {
        return [
            'task_id' => ['nullable', 'integer', 'min:1'],
            'query' => ['nullable', 'string', 'max:200'],
            'days' => ['nullable', 'integer', 'min:1', 'max:400'],
        ];
    }

    public function handle(ToolContext $context, array $args): ToolResult
    {
        $user = $context->user;
        $tz = $user->timezone;
        $taskQuery = $user->tasks();
        if ($args['task_id'] !== null) {
            $taskQuery->whereKey($args['task_id']);
        } elseif ($args['query'] !== null) {
            $terms = array_filter(preg_split('/\s+/', mb_strtolower($args['query'])) ?: [], fn ($t) => mb_strlen($t) >= 3);
            $taskQuery->where(function ($q) use ($terms) {
                foreach ($terms as $t) {
                    $q->orWhereRaw('LOWER(title) LIKE ?', ['%'.addcslashes($t, '%_\\').'%']);
                }
            });
        } else {
            return ToolResult::error('Provide task_id or query.');
        }
        $since = $context->now->subDays($args['days'] ?? 45);
        $tasks = $taskQuery->limit(10)->get()->keyBy('id');
        $events = ActivityEvent::query()->where('user_id', $user->id)->where('subject_type', 'task')
            ->whereIn('subject_id', $tasks->keys())->where('occurred_at', '>=', $since)
            ->orderBy('occurred_at')->limit(80)->get();

        return ToolResult::ok([
            'tasks' => $tasks->map(fn ($t) => $this->tasks->describe($t))->values()->all(),
            'events' => $events->map(fn (ActivityEvent $e) => array_filter([
                'task_id' => $e->subject_id,
                'event' => $e->event_type,
                'at' => LocalTime::human($e->occurred_at, $tz),
                'by' => $e->actor->value,
                'from' => $e->old_values,
                'to' => $e->new_values,
                'note' => $e->note,
            ]))->all(),
            'records_found' => $events->isNotEmpty(),
        ]);
    }
}
