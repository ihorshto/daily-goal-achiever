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
use App\Jobs\SendTelegramMessage;
use App\Memory\MemoryRetriever;
use App\Models\User;
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
            'help' => "Just talk to me naturally — text or voice. Some things you can say:\n- \"Tomorrow I want to fix auth\"\n- \"Deploy by 18:00\"\n- \"Docker is done, Postgres is blocked\"\n- \"What did I do yesterday?\"\n- \"Message me at 08:30; don't disturb after 21:00\"\n- \"What do you remember about me?\" / \"Forget that\"\n\nCommands: /settings, /language [uk|en|auto], /tone [description|default], /pause [hours], /resume, /memories, /deletemydata",
            'settings' => $this->formatSettings($user),
            'pause' => $this->pause($user, $arg, $message->id),
            'resume' => $this->resume($user, $message->id),
            'memories' => $this->listMemories($user),
            'language', 'lang' => $this->language($user, $arg, $message->id),
            'tone' => $this->tone($user, $arg, $message->id),
            'deletemydata' => (function () use ($user, $message, &$markup) {
                $p = $this->pending->propose($user, PendingActionType::DataDeletion, 'user', $user->id, ['scope' => 'all'], 'Erase ALL your data', $message->id);
                $markup = Keyboards::markup([Keyboards::confirmationRow($p)]);

                return 'This permanently erases your messages, tasks, goals, history, summaries and memories. Confirm?';
            })(),
            default => 'Unknown command. Send /help.',
        };

        $msg = $this->conversations->recordAssistant($user, 'reply:'.$message->id, $reply, MessageSource::AgentReply, $markup);
        SendTelegramMessage::dispatch($msg->id);
    }

    private function formatSettings(User $user): string
    {
        $s = $this->settings->describe($user);

        return "Your settings:\n- Timezone: {$s['timezone']}\n- Morning planning: {$s['morning_time']}\n- Evening reflection: {$s['evening_time']}\n- Quiet hours: {$s['quiet_hours']}\n- Max adaptive check-ins/day: {$s['max_checkins_per_day']}\n- Min gap between check-ins: {$s['min_gap_minutes']} min\n- Proactive messages: ".($s['proactive_enabled'] ? 'on' : 'off')
            ."\n- Reply language: {$s['reply_language']}"
            ."\n- Tone: {$s['tone']}"
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
