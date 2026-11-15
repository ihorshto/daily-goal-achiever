<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\AgentRun;
use App\Models\Checkpoint;
use App\Models\NotificationLog;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class CompanionStats extends Command
{
    protected $signature = 'companion:stats {--days=1}';

    protected $description = 'Operational metrics: LLM usage/cost, delivery outcomes, checkpoint states, failures';

    public function handle(): int
    {
        $since = now()->subDays((int) $this->option('days'));
        $this->info('Agent runs since '.$since->toDateTimeString().' UTC');
        $this->table(['trigger', 'status', 'runs', 'prompt tok', 'completion tok', 'est. USD', 'avg ms'],
            AgentRun::query()->where('created_at', '>=', $since)
                ->selectRaw('"trigger", status, count(*) n, sum(prompt_tokens) p, sum(completion_tokens) c, sum(estimated_cost_usd) usd, avg(latency_ms) ms')
                ->groupBy('trigger', 'status')->get()
                ->map(fn ($r) => [$r->trigger, $r->status, $r->n, $r->p, $r->c, round((float) $r->usd, 4), (int) $r->ms]));
        $this->info('Deliveries');
        $this->table(['kind', 'status', 'count'], NotificationLog::query()->where('created_at', '>=', $since)
            ->selectRaw('kind, status, count(*) n')->groupBy('kind', 'status')->get()->map(fn ($r) => [$r->kind, $r->status->value, $r->n]));
        $this->info('Checkpoints');
        $this->table(['kind', 'state', 'count'], Checkpoint::query()->where('updated_at', '>=', $since)
            ->selectRaw('kind, state, count(*) n')->groupBy('kind', 'state')->get()->map(fn ($r) => [$r->kind->value, $r->state->value, $r->n]));
        $this->info('Failed jobs (all time): '.DB::table('failed_jobs')->count());

        return self::SUCCESS;
    }
}
