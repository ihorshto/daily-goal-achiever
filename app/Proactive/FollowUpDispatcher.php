<?php

declare(strict_types=1);

namespace App\Proactive;

use App\Agent\ConversationService;
use App\Domain\CheckpointService;
use App\Enums\CheckpointState;
use App\Enums\MessageSource;
use App\Models\Checkpoint;
use App\Telegram\Keyboards;
use App\Telegram\OutboundMessenger;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;

/**
 * Processes one claimed checkpoint: policy → compose → deliver (idempotent) → mark sent.
 * The claim token ensures only the worker that claimed the row can complete it.
 */
final class FollowUpDispatcher
{
    public function __construct(
        private readonly CheckpointService $checkpoints,
        private readonly NotificationPolicy $policy,
        private readonly ProactivePlanner $planner,
        private readonly OutboundMessenger $messenger,
        private readonly ConversationService $conversations,
    ) {}

    /** @return string outcome code */
    public function dispatch(int $checkpointId, string $token, ?CarbonImmutable $now = null): string
    {
        $now ??= CarbonImmutable::now();
        $cp = Checkpoint::query()->with('user')->find($checkpointId);
        if ($cp === null || $cp->state !== CheckpointState::Claimed || $cp->claimed_by !== $token) {
            return 'not_owner';
        }
        $user = $cp->user;

        $decision = $this->policy->evaluate($user, $cp, $now);
        Log::info('proactive.decision', ['checkpoint_id' => $cp->id, 'kind' => $cp->kind->value, 'action' => $decision->action, 'reason' => $decision->reason]);
        switch ($decision->action) {
            case 'defer':
                $this->checkpoints->defer($cp, $token, $decision->until, (string) $decision->reason);

                return 'deferred:'.$decision->reason;
            case 'suppress':
                $this->checkpoints->transition($cp, CheckpointState::Claimed, CheckpointState::Suppressed, $decision->reason, $token);

                return 'suppressed:'.$decision->reason;
            case 'expire':
                $this->checkpoints->transition($cp, CheckpointState::Claimed, CheckpointState::Expired, $decision->reason, $token);

                return 'expired:'.$decision->reason;
        }

        $draft = $this->planner->compose($user, $cp, $now);
        if (! $draft->send) {
            $this->checkpoints->transition($cp, CheckpointState::Claimed, CheckpointState::Suppressed, $draft->reason, $token);

            return 'suppressed:planner';
        }

        // Re-check ownership right before the side effect (claim may have expired during LLM call).
        if (Checkpoint::query()->whereKey($cp->id)->where('state', CheckpointState::Claimed->value)->where('claimed_by', $token)->doesntExist()) {
            return 'lost_claim';
        }

        $markup = Keyboards::markup($draft->keyboard);
        $kind = $cp->kind->isRitual() ? 'proactive_ritual' : 'proactive_adaptive';
        $outcome = $this->messenger->deliver($user, $draft->text, $markup, $kind, 'checkpoint:'.$cp->id, $cp->id);

        if ($outcome->delivered()) {
            $this->checkpoints->transition($cp, CheckpointState::Claimed, CheckpointState::Sent, $draft->reason, $token, ['sent_at' => now()]);
            $message = $this->conversations->recordAssistant($user, 'checkpoint:'.$cp->id, $draft->text, MessageSource::Proactive, $markup, $cp->id);
            $message->forceFill(['sent_at' => now(), 'telegram_message_id' => $outcome->telegramMessageId])->save();
            $user->forceFill(['last_proactive_at' => now()])->save();

            return 'sent';
        }
        if ($outcome->status === 'duplicate') {
            // Already delivered by an earlier attempt: finalize state without resending.
            $this->checkpoints->transition($cp, CheckpointState::Claimed, CheckpointState::Sent, 'duplicate_delivery_prevented', $token, ['sent_at' => now()]);

            return 'duplicate';
        }
        if ($outcome->shouldRetry()) {
            $this->checkpoints->defer($cp, $token, $now->addSeconds(max(30, $outcome->retryAfter ?? 60)), 'telegram_'.$outcome->status);

            return 'retry_later';
        }
        $this->checkpoints->transition($cp, CheckpointState::Claimed, CheckpointState::Failed, 'telegram_'.$outcome->errorCode, $token);

        return 'failed';
    }
}
