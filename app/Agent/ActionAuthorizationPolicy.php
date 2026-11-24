<?php

declare(strict_types=1);

namespace App\Agent;

use App\Agent\Tools\Tool;

/**
 * Server-side authorization for model-proposed tool calls. The model cannot widen its own
 * permissions: these rules depend only on the authenticated context and the tool identity.
 */
final class ActionAuthorizationPolicy
{
    /** Tools that require the current turn to be an explicit user message (evidence/consent). */
    private const USER_TURN_ONLY = ['complete_task', 'confirm_pending_action', 'request_data_deletion', 'forget_memory', 'save_memory', 'create_calendar_event', 'send_sticker'];

    /** Returns a denial reason, or null when allowed. */
    public function deny(Tool $tool, ToolContext $context): ?string
    {
        if (! $context->user->is_enabled) {
            return 'User account is disabled.';
        }
        if ($tool->mutates() && $context->trigger === 'proactive') {
            return 'Mutating tools are not available during proactive messages.';
        }
        if (in_array($tool->name(), self::USER_TURN_ONLY, true) && ! $context->isUserTurn()) {
            return 'This action requires an explicit message from the user in this turn.';
        }

        return null;
    }
}
