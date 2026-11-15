<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/** Retention policy: removes old operational logs and (optionally) old transcripts. */
class PruneData extends Command
{
    protected $signature = 'companion:prune';

    protected $description = 'Apply retention policy (RETENTION_* env vars)';

    public function handle(): int
    {
        $r = config('companion.retention');
        $out = [
            'tool_executions' => DB::table('tool_executions')->where('created_at', '<', now()->subDays($r['agent_runs_days']))->delete(),
            'agent_runs' => DB::table('agent_runs')->where('created_at', '<', now()->subDays($r['agent_runs_days']))->delete(),
            'notification_log' => DB::table('notification_log')->where('created_at', '<', now()->subDays($r['notification_log_days']))->delete(),
            'telegram_updates' => DB::table('telegram_updates')->where('created_at', '<', now()->subDays(30))->delete(),
            'messages' => DB::table('messages')->where('created_at', '<', now()->subDays($r['messages_days']))->delete(),
        ];
        $this->line(json_encode($out));

        return self::SUCCESS;
    }
}
