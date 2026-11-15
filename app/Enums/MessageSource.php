<?php

declare(strict_types=1);

namespace App\Enums;

enum MessageSource: string
{
    case TelegramText = 'telegram_text';
    case TelegramVoice = 'telegram_voice';
    case Command = 'command';
    case Callback = 'callback';
    case Proactive = 'proactive';
    case AgentReply = 'agent_reply';
}
