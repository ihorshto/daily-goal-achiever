# Personal AI Companion (Laravel + Telegram + OpenAI + Mem0)

A proactive companion that lives in a private Telegram chat. You talk about goals, plans, blockers and progress, by text or voice. It:

- turns what you say into tasks and goals, and tells **ideas** ("maybe Stripe") apart from **intentions** and **commitments** ("deploy by 18:00");
- tracks explicit progress, and never marks anything done without your confirmation (your words or the ✅ button);
- remembers yesterday, today and tomorrow precisely (PostgreSQL event history), and recalls things from months ago (Mem0 semantic memory);
- checks in on its own: morning planning at 09:00, evening reflection at 20:00, plus deadline, blocker and promised follow-ups. It respects quiet hours (22:00–08:00, Europe/Paris, DST-safe), daily caps, minimum gaps, your silence and pauses;
- adds events to your calendar on request ("add a meeting with Oleh tomorrow at 15:00 to my calendar"): it sends an `.ics` file you open to add the event to Apple, Google or Outlook Calendar. It needs no calendar login, so every user gets events in their own calendar;
- asks for button confirmation before moving committed deadlines, reprioritizing, canceling, or erasing data.

## Quick start (development)
```bash
composer install
cp .env.example .env && php artisan key:generate
php artisan test                 # 85 tests; no network, no paid APIs (also green on PostgreSQL 17)
```
To chat with a dev bot: fill `TELEGRAM_*` and `OPENAI_*` in `.env`, start Postgres, Redis and Mem0 with `docker compose -f compose.yaml -f compose.dev.yaml up -d postgres redis mem0`, then run `php artisan migrate`, `php artisan queue:work`, `php artisan schedule:work` and `php artisan telegram:poll`.

## At home (Raspberry Pi, no domain needed)
See **[docs/raspberry-pi.md](docs/raspberry-pi.md)**: `docker compose -f compose.yaml -f compose.home.yaml --profile polling up -d` with `TELEGRAM_MODE=polling`. Outbound-only long polling: no TLS, no port forwarding.

## Production (single VPS)
See **[docs/deployment.md](docs/deployment.md)**: `docker compose up -d`, then `php artisan telegram:webhook set`. Bot registration is in **[docs/telegram-setup.md](docs/telegram-setup.md)**.

Services: `proxy` (Caddy, automatic TLS) → `app` (FrankenPHP/Laravel) · `worker` · `scheduler` · `migrate` (one-shot) · `postgres` (17 + pgvector) · `redis` · `mem0` (FastAPI sidecar around the official `mem0ai` SDK). Only the proxy publishes ports.

## Docs
- [Architecture & decisions](docs/architecture.md): flow, memory layers, temporal semantics, proactive engine, security, observability
- [Data model](docs/data-model.md)
- [Deployment, backups, runbooks](docs/deployment.md)
- [Telegram setup](docs/telegram-setup.md)
- [SaaS roadmap](docs/saas-roadmap.md)

## Talking to it
Natural language is the main interface. Some examples: "Tomorrow I want to fix auth and maybe start Stripe", "Docker's done, Postgres is blocked", "Move my deadline to tomorrow", "What did I do yesterday?", "Why was the deployment delayed last month?", "Message me at 08:30; don't disturb after 21:00", "What do you remember about me?", "Forget memory #12", "Always answer in Ukrainian". By default it replies in the language you write in.
Commands: `/start` `/help` `/settings` `/language [uk|en|auto]` `/pause [hours]` `/resume` `/memories` `/deletemydata`.

## Operations cheatsheet
```bash
php artisan companion:stats --days=1           # tokens, est. cost, deliveries, checkpoint states
php artisan companion:memory status            # Mem0 sync health
php artisan companion:memory retry-failed      # re-queue failed memory syncs
php artisan queue:failed                       # failed jobs (dead letters)
curl -H "Authorization: Bearer $HEALTH_TOKEN" http://app:8080/api/health   # inside the network
```

## Configuration
Everything is in [.env.example](.env.example), with comments. Model IDs are **not** hard-coded: set `OPENAI_CHAT_MODEL`, `OPENAI_UTILITY_MODEL` and `OPENAI_TRANSCRIPTION_MODEL` to models your account supports. Set the price variables to match your models so the cost and budget estimates are meaningful.
