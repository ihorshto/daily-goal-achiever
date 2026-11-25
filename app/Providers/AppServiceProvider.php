<?php

declare(strict_types=1);

namespace App\Providers;

use App\Agent\OpenAI\LlmClient;
use App\Agent\OpenAI\OpenAIClient;
use App\Agent\ToolRegistry;
use App\Agent\Tools;
use App\Memory\Mem0MemoryProvider;
use App\Memory\MemoryProvider;
use App\Memory\NullMemoryProvider;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /** @var list<class-string<Tools\Tool>> */
    public const TOOLS = [
        Tools\CreateGoalTool::class,
        Tools\CreateTaskTool::class,
        Tools\CreateCalendarEventTool::class,
        Tools\UpdateTaskTool::class,
        Tools\UpdateTaskProgressTool::class,
        Tools\CompleteTaskTool::class,
        Tools\GetActiveGoalsTool::class,
        Tools\GetTodayTasksTool::class,
        Tools\GetTaskHistoryTool::class,
        Tools\ScheduleCheckinTool::class,
        Tools\RescheduleCheckinTool::class,
        Tools\GetUserScheduleTool::class,
        Tools\UpdateNotificationPreferencesTool::class,
        Tools\SearchMemoryTool::class,
        Tools\SaveMemoryTool::class,
        Tools\ListMemoriesTool::class,
        Tools\ForgetMemoryTool::class,
        Tools\GetDailySummaryTool::class,
        Tools\ProposeDeadlineChangeTool::class,
        Tools\ProposePriorityChangeTool::class,
        Tools\ProposeTaskCancellationTool::class,
        Tools\ConfirmPendingActionTool::class,
        Tools\RequestDataDeletionTool::class,
        Tools\SendStickerTool::class,
    ];

    public function register(): void
    {
        $this->app->bind(LlmClient::class, OpenAIClient::class);

        $this->app->singleton(MemoryProvider::class, fn () => match (config('companion.memory.driver')) {
            'null' => new NullMemoryProvider,
            default => new Mem0MemoryProvider,
        });

        $this->app->tag(self::TOOLS, 'agent.tools');
        $this->app->bind(ToolRegistry::class, fn ($app) => new ToolRegistry($app->tagged('agent.tools')));
    }

    public function boot(): void
    {
        RateLimiter::for('telegram', fn (Request $request) => Limit::perMinute(120)->by($request->ip()));
    }
}
