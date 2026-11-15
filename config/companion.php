<?php

/*
|--------------------------------------------------------------------------
| Personal AI Companion configuration
|--------------------------------------------------------------------------
| Every value here is environment-overridable. Secrets are never defaulted.
*/

return [

    'telegram' => [
        'bot_token' => env('TELEGRAM_BOT_TOKEN'),
        'webhook_secret' => env('TELEGRAM_WEBHOOK_SECRET'),
        'api_base' => env('TELEGRAM_API_BASE', 'https://api.telegram.org'),
        // Comma-separated numeric Telegram user IDs allowed to use the bot.
        'allowed_user_ids' => array_values(array_filter(array_map(
            'trim',
            explode(',', (string) env('TELEGRAM_ALLOWED_USER_IDS', ''))
        ))),
        'max_voice_bytes' => (int) env('TELEGRAM_MAX_VOICE_BYTES', 10 * 1024 * 1024),
        'max_voice_seconds' => (int) env('TELEGRAM_MAX_VOICE_SECONDS', 600),
        'callback_ttl_hours' => (int) env('TELEGRAM_CALLBACK_TTL_HOURS', 72),
        'timeout_seconds' => (int) env('TELEGRAM_TIMEOUT_SECONDS', 15),
    ],

    'openai' => [
        'api_key' => env('OPENAI_API_KEY'),
        'base_url' => env('OPENAI_BASE_URL', 'https://api.openai.com/v1'),
        // No model is hard-coded: operators must set a currently supported model ID.
        'chat_model' => env('OPENAI_CHAT_MODEL'),
        'utility_model' => env('OPENAI_UTILITY_MODEL', env('OPENAI_CHAT_MODEL')),
        'transcription_model' => env('OPENAI_TRANSCRIPTION_MODEL'),
        'max_completion_tokens' => (int) env('OPENAI_MAX_COMPLETION_TOKENS', 1200),
        // Some reasoning models reject a temperature parameter; leave empty to omit it.
        'temperature' => env('OPENAI_TEMPERATURE') === null || env('OPENAI_TEMPERATURE') === '' ? null : (float) env('OPENAI_TEMPERATURE'),
        'timeout_seconds' => (int) env('OPENAI_TIMEOUT_SECONDS', 60),
        // Prices in USD per 1M tokens, used only for cost estimation/budgets.
        'price_input_per_million' => (float) env('OPENAI_PRICE_INPUT_PER_MILLION', 0.25),
        'price_output_per_million' => (float) env('OPENAI_PRICE_OUTPUT_PER_MILLION', 2.00),
        'price_transcription_per_minute' => (float) env('OPENAI_PRICE_TRANSCRIPTION_PER_MINUTE', 0.006),
    ],

    'agent' => [
        'max_tool_iterations' => (int) env('AGENT_MAX_TOOL_ITERATIONS', 6),
        'max_tool_calls_per_run' => (int) env('AGENT_MAX_TOOL_CALLS_PER_RUN', 12),
        'max_runtime_seconds' => (int) env('AGENT_MAX_RUNTIME_SECONDS', 90),
        'context_token_budget' => (int) env('AGENT_CONTEXT_TOKEN_BUDGET', 6000),
        'recent_message_limit' => (int) env('AGENT_RECENT_MESSAGE_LIMIT', 30),
        'memory_results' => (int) env('AGENT_MEMORY_RESULTS', 8),
        'daily_budget_usd' => (float) env('AGENT_DAILY_BUDGET_USD', 2.00),
        'max_user_message_chars' => (int) env('AGENT_MAX_USER_MESSAGE_CHARS', 6000),
        'inbound_rate_per_minute' => (int) env('AGENT_INBOUND_RATE_PER_MINUTE', 20),
    ],

    'memory' => [
        'driver' => env('MEMORY_DRIVER', 'mem0'), // mem0 | null
        'mem0' => [
            'base_url' => env('MEM0_BASE_URL', 'http://mem0:8000'),
            'api_key' => env('MEM0_API_KEY'),
            'timeout_seconds' => (int) env('MEM0_TIMEOUT_SECONDS', 10),
            // Namespace prefix so a shared Mem0 store can never collide across deployments/tenants.
            'user_prefix' => env('MEM0_USER_PREFIX', 'companion'),
            'search_threshold' => (float) env('MEM0_SEARCH_THRESHOLD', 0.25),
        ],
        'extraction_min_chars' => (int) env('MEMORY_EXTRACTION_MIN_CHARS', 12),
        'min_confidence' => (float) env('MEMORY_MIN_CONFIDENCE', 0.6),
        'max_sync_attempts' => (int) env('MEMORY_MAX_SYNC_ATTEMPTS', 8),
        'duplicate_similarity' => (float) env('MEMORY_DUPLICATE_SIMILARITY', 0.9),
    ],

    'defaults' => [
        'timezone' => env('COMPANION_DEFAULT_TIMEZONE', 'Europe/Paris'),
        'locale' => env('COMPANION_DEFAULT_LOCALE', 'en'),
        'morning_time' => env('COMPANION_MORNING_TIME', '09:00'),
        'evening_time' => env('COMPANION_EVENING_TIME', '20:00'),
        'quiet_start' => env('COMPANION_QUIET_START', '22:00'),
        'quiet_end' => env('COMPANION_QUIET_END', '08:00'),
        'max_checkins_per_day' => (int) env('COMPANION_MAX_CHECKINS_PER_DAY', 4),
        'min_gap_minutes' => (int) env('COMPANION_MIN_GAP_MINUTES', 90),
    ],

    'proactive' => [
        'enabled' => (bool) env('PROACTIVE_ENABLED', true),
        // How long a claimed checkpoint is reserved for one worker.
        'claim_ttl_seconds' => (int) env('PROACTIVE_CLAIM_TTL_SECONDS', 300),
        'max_attempts' => (int) env('PROACTIVE_MAX_ATTEMPTS', 5),
        // Defer adaptive check-ins while the user is actively chatting.
        'active_conversation_minutes' => (int) env('PROACTIVE_ACTIVE_CONVERSATION_MINUTES', 20),
        // Window after the scheduled time during which a daily ritual is still worth sending.
        'ritual_window_minutes' => (int) env('PROACTIVE_RITUAL_WINDOW_MINUTES', 120),
        'blocked_followup_hours' => (int) env('PROACTIVE_BLOCKED_FOLLOWUP_HOURS', 3),
        'pre_deadline_minutes' => (int) env('PROACTIVE_PRE_DEADLINE_MINUTES', 120),
        'post_deadline_minutes' => (int) env('PROACTIVE_POST_DEADLINE_MINUTES', 20),
    ],

    'confirmations' => [
        'ttl_hours' => (int) env('CONFIRMATION_TTL_HOURS', 24),
    ],

    'health' => [
        'token' => env('HEALTH_TOKEN'),
    ],

    'retention' => [
        'messages_days' => (int) env('RETENTION_MESSAGES_DAYS', 365),
        'agent_runs_days' => (int) env('RETENTION_AGENT_RUNS_DAYS', 90),
        'notification_log_days' => (int) env('RETENTION_NOTIFICATION_LOG_DAYS', 90),
    ],
];
