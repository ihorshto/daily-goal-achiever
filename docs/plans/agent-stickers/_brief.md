# Brief: AI agent sends Telegram stickers (and emoji) like a real person

## Original request (uk)

Давай тоді додамо змогу надсилання наліпок, стікерів AI агентом. У мене вже є колекція наліпок, які я би хотів використовувати в своєму чаті. Я би хотів мати змогу додати наліпки в чаті в Телеграмі. Тобто користувач зможе вибрати каталог наліпок. План реалізації. Використовуй brainstorming skill. Моя ціль, щоб ai агент був більш схожий на справжню людину і відповідав так, як справжня людина. Тобто деколи додавав смайлики, деколи додавав стікери, але це не має бути завжди, це має бути відносно до ситуації.

## Follow-up from the user (mid-run)

користувач зможе виключити цю опцію. (The user must be able to turn this option off.)

## Context from the earlier conversation

- Bot today only uses Telegram `sendMessage` and `sendDocument` (`app/Telegram/TelegramApiClient.php`, `OutboundMessenger`). Stickers need `sendSticker` (and optionally `getStickerSet`).
- The model cannot see images: it needs a catalog to pick from. Telegram stickers carry an associated emoji, which can serve as the selection key.
- The user already owns a sticker collection and wants to add/select sticker sets from inside the Telegram chat (e.g. by sending a sticker or a t.me/addstickers link).
- Precedent for per-user settings changeable in chat: `reply_language` and `tone` in `user_settings`, `update_notification_preferences` tool, `/language`, `/tone` commands, `SettingsService`.
- Precedent for sending an extra item after the text reply exactly once: calendar `.ics` delivery via `notification_log` (`create_calendar_event`).
- Frequency must be situational, not constant; guard it in code (rate limit / cooldown), not only in the prompt.
- Open question from the earlier conversation: whether stickers are also allowed in proactive messages (morning/evening/check-ins) — not yet answered by the user.
- GIFs are out of scope for this run unless BA recommends otherwise; stickers + situational emoji are the scope.
- "Brainstorming skill" (superpowers) is NOT installed — the BA phase must do the brainstorming itself: options, trade-offs, recommendation.
- Commit style for this project: Conventional Commits, no Claude/AI attribution trailers. Runs on a Raspberry Pi via Telegram long polling.

## User decisions after business analysis (2026-11-24, answered by the user)

1. Switches: TWO separate switches — `/stickers on|off` and `/emoji on|off` (also via natural language and `/settings`), both ON by default.
2. Proactive messages (morning/evening/check-ins): EMOJI ONLY. Stickers only in replies to the user's own messages.
3. Frequency limits: the proposed ones, FIXED as config values (not changeable from chat): max 1 sticker per reply, at least 30 min AND at least 3 replies between stickers, max 6 stickers per day.

Commit style override: Conventional Commits, NO Claude/AI attribution trailers (user's standing instruction beats any default attribution reminder).
