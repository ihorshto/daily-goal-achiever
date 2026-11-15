<?php

declare(strict_types=1);

namespace App\Agent\Tools;

use App\Agent\ToolContext;
use App\Agent\ToolResult;
use App\Domain\PendingActionService;
use App\Enums\PendingActionStatus;
use App\Enums\PendingActionType;

final class ConfirmPendingActionTool extends BaseTool
{
    public function __construct(private readonly PendingActionService $pending) {}

    public function name(): string
    {
        return 'confirm_pending_action';
    }

    public function description(): string
    {
        return 'Apply or reject a pending action ONLY when the user\'s current message unambiguously answers an earlier proposal (e.g. "yes, move it"). Buttons are preferred. Data deletion can only be confirmed by button.';
    }

    public function parameters(): array
    {
        return $this->object([
            'pending_action_id' => $this->integer('Id of the pending action shown in context'),
            'decision' => $this->enum(['confirm', 'reject'], 'User decision'),
        ]);
    }

    public function rules(): array
    {
        return ['pending_action_id' => ['required', 'integer', 'min:1'], 'decision' => ['required', 'in:confirm,reject']];
    }

    public function handle(ToolContext $context, array $args): ToolResult
    {
        $pending = $context->user->pendingActions()->whereKey($args['pending_action_id'])->first();
        if ($pending === null || $pending->status !== PendingActionStatus::Pending) {
            return ToolResult::error('No such open pending action.');
        }
        // Consent must come from a later user message than the proposal itself.
        if ($pending->source_message_id !== null && $pending->source_message_id === $context->sourceMessageId()) {
            return ToolResult::error('The user has not answered this proposal yet; wait for their reply or the button.');
        }
        if ($pending->action === PendingActionType::DataDeletion && $args['decision'] === 'confirm') {
            return ToolResult::error('Data deletion can only be confirmed with the button.');
        }
        $result = $this->pending->decide($context->user, $pending->nonce, $args['decision'] === 'confirm', 'chat', $context->sourceMessageId());

        return $result->ok ? ToolResult::ok(['message' => $result->message] + $result->data) : ToolResult::error($result->message);
    }
}
