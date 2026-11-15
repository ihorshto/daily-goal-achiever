<?php

declare(strict_types=1);

namespace App\Agent\Tools;

use App\Agent\ToolContext;
use App\Agent\ToolResult;
use App\Domain\PendingActionService;
use App\Enums\PendingActionType;
use App\Telegram\Keyboards;

final class RequestDataDeletionTool extends BaseTool
{
    public function __construct(private readonly PendingActionService $pending) {}

    public function name(): string
    {
        return 'request_data_deletion';
    }

    public function description(): string
    {
        return 'User wants to erase their data. scope "memories" erases all saved memories; "all" also erases messages, tasks, goals, history and summaries. Always sends a confirmation button.';
    }

    public function parameters(): array
    {
        return $this->object(['scope' => $this->enum(['memories', 'all'], 'What to erase')]);
    }

    public function rules(): array
    {
        return ['scope' => ['required', 'in:memories,all']];
    }

    public function handle(ToolContext $context, array $args): ToolResult
    {
        $summary = $args['scope'] === 'all'
            ? 'Erase ALL your data (messages, tasks, goals, history, summaries, memories)'
            : 'Erase all your saved memories';
        $proposal = $this->pending->propose($context->user, PendingActionType::DataDeletion, 'user', $context->user->id, ['scope' => $args['scope']], $summary, $context->sourceMessageId());
        $context->addKeyboardRow(Keyboards::confirmationRow($proposal));

        return ToolResult::ok(['awaiting_confirmation' => true, 'summary' => $summary, 'instruction' => 'Tell the user this is irreversible and to tap Confirm to proceed.']);
    }
}
