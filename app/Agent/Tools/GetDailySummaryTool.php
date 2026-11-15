<?php

declare(strict_types=1);

namespace App\Agent\Tools;

use App\Agent\ToolContext;
use App\Agent\ToolResult;
use App\Memory\DailySummaryService;
use App\Support\LocalTime;

final class GetDailySummaryTool extends BaseTool
{
    public function __construct(private readonly DailySummaryService $summaries) {}

    public function name(): string
    {
        return 'get_daily_summary';
    }

    public function description(): string
    {
        return 'Source-backed record of a local day: confirmed completions, progress updates, blockers, what was planned, and open loops. Use for "what did I do yesterday/on <date>?". Keep planned vs confirmed distinct in your answer.';
    }

    public function mutates(): bool
    {
        return false;
    }

    public function parameters(): array
    {
        return $this->object(['date' => $this->string('Local date YYYY-MM-DD')]);
    }

    public function rules(): array
    {
        return ['date' => ['required', 'date_format:Y-m-d']];
    }

    public function handle(ToolContext $context, array $args): ToolResult
    {
        $user = $context->user;
        $data = $this->summaries->compute($user, $args['date']);
        $stored = $user->dailySummaries()->whereDate('local_date', $args['date'])->first();
        unset($data['source_message_ids']);
        $data['source_event_count'] = count($data['source_event_ids']);
        unset($data['source_event_ids']);
        if ($stored?->narrative) {
            $data['narrative'] = $stored->narrative;
        }
        $data['today'] = LocalTime::today($user->timezone);
        if (! $data['records_complete']) {
            $data['note'] = 'No recorded activity or messages for this date. Say so rather than guessing.';
        }

        return ToolResult::ok($data);
    }
}
