# Telegram setup

1. In Telegram, open **@BotFather**, send `/newbot`, and choose a name and a username ending in `bot`. Copy the token into `.env` as `TELEGRAM_BOT_TOKEN`. Treat it like a password.
2. Optional hardening in BotFather:
   - `/setjoingroups` → Disable (the bot only works in private chats anyway).
   - `/setprivacy` → Enable.
   - `/setcommands` and paste:
     ```
     start - Introduction
     help - What I can do
     settings - Show notification settings
     language - Reply language (e.g. /language uk, /language auto)
     pause - Pause proactive messages (optionally: /pause 3)
     resume - Resume proactive messages
     memories - Review what I remember
     deletemydata - Erase all your data
     ```
3. Find your numeric Telegram user ID (for example, message **@userinfobot**). Put it in `TELEGRAM_ALLOWED_USER_IDS`. Anyone else is ignored silently.
4. Set `TELEGRAM_WEBHOOK_SECRET` to 16–256 characters from `[A-Za-z0-9_-]` (`openssl rand -hex 32`).
5. Make sure `APP_URL=https://<APP_DOMAIN>` resolves and TLS works (`curl -I https://<APP_DOMAIN>/up` returns 200).
6. Register the webhook:
   ```bash
   docker compose exec app php artisan telegram:webhook set
   docker compose exec app php artisan telegram:webhook info   # check url, pending_update_count, last_error_message
   ```
   This calls `setWebhook` with `secret_token` and `allowed_updates=["message","callback_query"]`. Telegram then sends the secret in the `X-Telegram-Bot-Api-Secret-Token` header, and the app rejects any request without it (401). If the secret isn't configured, the app refuses all requests (503).
7. Send `/start` to your bot.

**Local development without HTTPS:** run `php artisan telegram:poll`. It deletes the webhook and long-polls `getUpdates` through the same router. Never run it against the production bot.
