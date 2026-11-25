<?php

declare(strict_types=1);

namespace App\Agent\Tools;

use App\Agent\ToolContext;
use App\Agent\ToolResult;
use App\Models\StickerSend;
use App\Stickers\StickerCatalog;
use App\Stickers\StickerLimiter;
use App\Stickers\StickerPicker;
use App\Support\EmojiKey;
use Illuminate\Database\QueryException;

/**
 * Queues one of the user's own stickers to be sent right after the reply. The model names an
 * emoji only; the sticker (file_id) is chosen server-side from the current user's catalog, and
 * limits are enforced by StickerLimiter. Only offered during user-initiated turns.
 */
final class SendStickerTool extends BaseTool
{
    public function __construct(
        private readonly StickerCatalog $catalog,
        private readonly StickerLimiter $limiter,
        private readonly StickerPicker $picker,
    ) {}

    public function name(): string
    {
        return 'send_sticker';
    }

    public function description(): string
    {
        return 'Send one of the user\'s own stickers right after your reply, to react to a light or warm moment (a joke, a win, a thank-you, a sticker the user sent). Pass a single emoji taken from the STICKER CATALOG; the sticker itself is picked for you. At most one per reply and rarely; never in sensitive moments. If it returns an error, just answer without a sticker and do not mention it.';
    }

    public function parameters(): array
    {
        return $this->object([
            'emoji' => $this->string('One emoji from the STICKER CATALOG that matches the mood'),
        ]);
    }

    public function rules(): array
    {
        return [
            'emoji' => ['required', 'string', 'max:32'],
        ];
    }

    public function availableFor(ToolContext $context): bool
    {
        return $context->isUserTurn()
            && $context->user->settingsOrDefault()->stickers_enabled
            && $this->catalog->hasUsable($context->user);
    }

    public function handle(ToolContext $context, array $args): ToolResult
    {
        $source = $context->sourceMessage;
        if ($source === null) {
            return ToolResult::error('Stickers can only be sent in reply to a user message.');
        }
        if (($reason = $this->limiter->denialReason($context->user, $source, $context->now)) !== null) {
            return ToolResult::error($reason);
        }

        $key = EmojiKey::normalize((string) $args['emoji']);
        $sticker = $key !== null ? $this->picker->pick($context->user, $key) : null;
        if ($sticker === null) {
            return ToolResult::error('No sticker for that emoji; available: '.implode(', ', $this->picker->availableEmojis($context->user)).'.');
        }

        try {
            StickerSend::query()->create([
                'user_id' => $context->user->id,
                'source_message_id' => $source->id,
                'sticker_id' => $sticker->id,
                'emoji' => $key,
                'file_id' => $sticker->file_id,
            ]);
        } catch (QueryException) {
            return ToolResult::error('Only one sticker per reply.');
        }

        return ToolResult::ok(['queued' => true, 'emoji' => $key, 'note' => 'The sticker is sent right after your reply. Do not mention or announce it.']);
    }
}
