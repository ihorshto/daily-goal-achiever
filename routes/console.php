<?php

use Illuminate\Support\Facades\Schedule;

/*
| The scheduler only creates/claims concrete checkpoint rows; delivery happens in queue workers.
| onOneServer() + withoutOverlapping() use the Redis cache lock, so running a second scheduler
| (or a rolling deploy overlap) cannot double-dispatch.
*/
Schedule::command('companion:dispatch-checkpoints')->everyMinute()->withoutOverlapping(5)->onOneServer();
Schedule::command('companion:plan-rituals')->everyFiveMinutes()->withoutOverlapping()->onOneServer();
Schedule::command('companion:daily-summaries')->hourlyAt(5)->onOneServer();
Schedule::command('companion:memory retry-failed')->everyTenMinutes()->onOneServer();
Schedule::command('companion:memory consolidate')->dailyAt('04:30')->onOneServer();
Schedule::command('companion:prune')->dailyAt('05:00')->onOneServer();
Schedule::command('queue:prune-failed --hours=720')->daily();
