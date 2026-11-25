<?php

declare(strict_types=1);

namespace App\Telegram;

use App\Agent\ConversationService;
use App\Domain\DomainException;
use App\Domain\PendingActionService;
use App\Domain\SettingsService;
use App\Enums\Actor;
use App\Enums\MessageSource;
use App\Enums\PendingActionType;
use App\Enums\ProcessingState;
use App\Jobs\ImportStickerSet;
use App\Jobs\SendTelegramMessage;
use App\Memory\MemoryRetriever;
use App\Models\User;
use App\Stickers\StickerCatalog;
use App\Stickers\StickerSetUnavailable;
use App\Support\ReplyLanguage;
use App\Support\ReplyTone;

/** Minimal operational slash commands; natural language remains the primary interface. */
final class CommandHandler
{
    public function __construct(
        private readonly SettingsService $settings,
        private readonly ConversationService $conversations,
        private readonly MemoryRetriever $memories,
        private readonly PendingActionService $pending,
        private readonly StickerCatalog $stickers,
    ) {}

    public static function isCommand(string $text): bool
    {
        return (bool) preg_match('#^/[a-z_]+(@\w+)?(\s|$)#i', $text);
    }

    public function handle(User $user, string $text, int $updateId, int $telegramMessageId): void
    {
        [$message] = $this->conversations->recordInbound($user, 'tg:'.$user->telegram_chat_id.':'.$telegramMessageId, MessageSource::Command, $text, [
            'telegram_update_id' => $updateId,
            'telegram_message_id' => $telegramMessageId,
            'processing_state' => ProcessingState::Processed,
        ]);
        preg_match('#^/([a-z_]+)(?:@\w+)?\s*(.*)$#is', trim($text), $m);
        $command = strtolower($m[1] ?? '');
        $arg = trim($m[2] ?? '');
        $markup = null;

        $reply = match ($command) {
            'start' => "Hi {$user->displayName()}! I'm your personal companion. Tell me what you're working on, what's planned for today or tomorrow, and what's blocking you — I'll keep track, follow up at sensible times, and remember what matters.\n\nBy default I check in at 09:00 for planning and 20:00 for reflection (Europe/Paris), and stay quiet 22:00–08:00. Just tell me if you'd like something different. Send /help for commands.",
            'help' => "Just talk to me naturally — text or voice. Some things you can say:\n- \"Tomorrow I want to fix auth\"\n- \"Deploy by 18:00\"\n- \"Docker is done, Postgres is blocked\"\n- \"What did I do yesterday?\"\n- \"Message me at 08:30; don't disturb after 21:00\"\n- \"What do you remember about me?\" / \"Forget that\"\n\nCommands: /settings, /language [uk|en|auto], /tone [description|default], /stickers, /emoji [on|off], /pause [hours], /resume, /memories, /deletemydata",
            'settings' => $this->formatSettings($user),
            'pause' => $this->pause($user, $arg, $message->id),
            'resume' => $this->resume($user, $message->id),
            'memories' => $this->listMemories($user),
            'language', 'lang' => $this->language($user, $arg, $message->id),
            'tone' => $this->tone($user, $arg, $message->id),
            'stickers' => $this->stickerCommand($user, $arg, $message->id),
            'emoji' => $this->emoji($user, $arg, $message->id),
            'deletemydata' => (function () use ($user, $message, &$markup) {
                $p = $this->pending->propose($user, PendingActionType::DataDeletion, 'user', $user->id, ['scope' => 'all'], 'Erase ALL your data', $message->id);
                $markup = Keyboards::markup([Keyboards::confirmationRow($p)]);

                return 'This permanently erases your messages, tasks, goals, history, summaries and memories. Confirm?';
            })(),
            default => 'Unknown command. Send /help.',
        };

        if ($reply === null) {
            return; // a queued job (ImportStickerSet) sends the reply
        }
        $msg = $this->conversations->recordAssistant($user, 'reply:'.$message->id, $reply, MessageSource::AgentReply, $markup);
        SendTelegramMessage::dispatch($msg->id);
    }

    private function formatSettings(User $user): string
    {
        $s = $this->settings->describe($user);

        return "Your settings:\n- Timezone: {$s['timezone']}\n- Morning planning: {$s['morning_time']}\n- Evening reflection: {$s['evening_time']}\n- Quiet hours: {$s['quiet_hours']}\n- Max adaptive check-ins/day: {$s['max_checkins_per_day']}\n- Min gap between check-ins: {$s['min_gap_minutes']} min\n- Proactive messages: ".($s['proactive_enabled'] ? 'on' : 'off')
            ."\n- Reply language: {$s['reply_language']}"
            ."\n- Tone: {$s['tone']}"
            ."\n- Stickers: {$s['stickers']}"
            ."\n- Emoji: {$s['emoji']}"
            .($s['paused_until'] ? "\n- Paused until: {$s['paused_until']}" : '')
            ."\n\nChange anything by just telling me, e.g. \"evening check-in at 19:30\".";
    }

    private function language(User $user, string $arg, int $messageId): string
    {
        if ($arg === '') {
            $current = ReplyLanguage::describe($user->settingsOrDefault()->reply_language);

            return "Reply language: {$current}.\nSet it with e.g. /language uk, /language English, or /language auto to match whatever language you write in. You can also just tell me, e.g. \"відповідай українською\".";
        }
        try {
            $this->settings->update($user, ['reply_language' => $arg], Actor::User, $messageId);
        } catch (DomainException $e) {
            return $e->getMessage();
        }
        $language = $user->settingsOrDefault()->reply_language;

        return $language !== null
            ? "Done: I'll reply in {$language} from now on. /language auto to match your language again."
            : "Done: I'll reply in whatever language you write to me in.";
    }

    private function tone(User $user, string $arg, int $messageId): string
    {
        if ($arg === '') {
            $current = ReplyTone::describe($user->settingsOrDefault()->tone);

            return "Tone: {$current}.\nSet it with e.g. /tone direct and a bit ironic, no emojis, or /tone default to go back. You can also just tell me, e.g. \"говори зі мною прямолінійніше\".";
        }
        try {
            $this->settings->update($user, ['tone' => $arg], Actor::User, $messageId);
        } catch (DomainException $e) {
            return $e->getMessage();
        }
        $tone = $user->settingsOrDefault()->tone;

        return $tone !== null
            ? "Done: from now on I'll sound like this: {$tone}. /tone default to go back."
            : "Done: I'm back to my default tone.";
    }

    private function emoji(User $user, string $arg, int $messageId): string
    {
        $on = $this->switchValue($arg);
        if ($on === null) {
            return 'Emoji: '.($user->settingsOrDefault()->emoji_enabled ? 'on' : 'off').".\nUse /emoji on or /emoji off.";
        }
        $this->settings->update($user, ['emoji_enabled' => $on], Actor::User, $messageId);

        return $on ? "Done: I'll use emojis now and then. /emoji off to stop." : 'Done: no more emojis in my messages. /emoji on to bring them back.';
    }

    /** Returns the reply text, or null when a queued job sends it. */
    private function stickerCommand(User $user, string $arg, int $messageId): ?string
    {
        [$sub, $rest] = array_pad(preg_split('/\s+/', $arg, 2) ?: [], 2, '');
        $sub = strtolower((string) $sub);
        $rest = trim((string) $rest);

        if ($sub === '' || $sub === 'list') {
            return $this->listStickerSets($user);
        }
        if (($on = $this->switchValue($sub)) !== null) {
            $this->settings->update($user, ['stickers_enabled' => $on], Actor::User, $messageId);

            return $on ? 'Done: I may send your stickers now and then. /stickers off to stop.' : 'Done: I will not send stickers. /stickers on to allow them again.';
        }
        if ($sub === 'add') {
            try {
                $this->stickers->parseSetName($rest);
            } catch (StickerSetUnavailable $e) {
                return $e->getMessage().' Usage: /stickers add <t.me/addstickers link or set name>';
            }
            ImportStickerSet::dispatch($user->id, $rest, $messageId);

            return null;
        }
        if (in_array($sub, ['remove', 'enable', 'disable', 'sync'], true)) {
            $set = ctype_digit($rest) ? $this->stickers->setByPosition($user, (int) $rest) : null;
            if ($set === null) {
                return "No sticker set #{$rest}. Send /stickers to see the list.";
            }
            if ($sub === 'sync') {
                ImportStickerSet::dispatch($user->id, $set->name, $messageId);

                return null;
            }
            if ($sub === 'remove') {
                $this->stickers->remove($set);

                return "Removed «{$set->title}».";
            }
            $this->stickers->setEnabled($set, $sub === 'enable');

            return ($sub === 'enable' ? 'Enabled' : 'Disabled')." «{$set->title}».";
        }

        return 'Usage: /stickers [on|off|add <link>|sync N|remove N|enable N|disable N]';
    }

    private function listStickerSets(User $user): string
    {
        $sets = $this->stickers->listFor($user);
        $state = $user->settingsOrDefault()->stickers_enabled ? 'on' : 'off';
        if ($sets->isEmpty()) {
            return "Stickers: {$state}. You have no sticker sets yet. Send me a sticker from a set, or a t.me/addstickers link, or use /stickers add <link>.";
        }
        $lines = $sets->values()->map(fn ($s, $i) => ($i + 1).". {$s->title} — {$s->sticker_count} stickers (".($s->enabled ? 'on' : 'off').')')->all();

        return "Stickers: {$state}\n".implode("\n", $lines)."\n\n/stickers on|off, add <link>, sync N, remove N, enable N, disable N";
    }

    private function switchValue(string $word): ?bool
    {
        return match (strtolower(trim($word))) {
            'on' => true,
            'off' => false,
            default => null,
        };
    }

    private function pause(User $user, string $arg, int $messageId): string
    {
        if (preg_match('/^(\d{1,3})\s*h?$/i', $arg, $m)) {
            $this->settings->update($user, ['pause_hours' => max(1, (int) $m[1])], Actor::User, $messageId);

            return "Paused proactive messages for {$m[1]}h. I'll still reply when you write. /resume to undo.";
        }
        $this->settings->update($user, ['proactive_enabled' => false], Actor::User, $messageId);

        return "Proactive messages are off until you send /resume. I'll still answer whenever you write.";
    }

    private function resume(User $user, int $messageId): string
    {
        $this->settings->update($user, ['proactive_enabled' => true, 'pause_hours' => 0], Actor::User, $messageId);

        return 'Proactive messages are back on.';
    }

    private function listMemories(User $user): string
    {
        $items = $this->memories->recent($user, 15);
        if ($items === []) {
            return "I haven't saved any long-term memories yet.";
        }
        $lines = array_map(fn ($m) => "#{$m->localId} ({$m->recordedAt}, {$m->category}) {$m->content}", $items);

        return "What I remember (most recent first):\n".implode("\n", $lines)."\n\nTo delete one, say e.g. \"forget memory #12\".";
    }
}
