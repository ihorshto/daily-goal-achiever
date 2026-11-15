# Architecture

A Telegram-based personal companion (assistant + accountability partner + coach). Laravel is the
orchestrator and system of record. OpenAI supplies language understanding. Mem0 provides semantic
long-term memory.

## 1. Decisions & assumptions

| Area | Decision | Why / tradeoff |
|---|---|---|
| Runtime | Laravel 13.42, PHP 8.5 (FrankenPHP 1.13.5 image) | These were the current stable versions at build time. FrankenPHP is a single binary serving HTTP. |
| DB | PostgreSQL 17 + pgvector 0.8.7 (`pgvector/pgvector:0.8.7-pg17`) | One engine for the app data and for Mem0's vectors. Mem0 gets a separate database and role. |
| Queue/cache/locks | Redis 7.4 + `queue:work` (no Horizon) | Single-user scale. Plain workers are simpler. Horizon adds a dashboard we don't expose. |
| LLM | OpenAI Chat Completions with `strict` function tools and `json_schema` outputs | Widely supported structured tool calling. Model IDs come only from env (`OPENAI_CHAT_MODEL`, `OPENAI_UTILITY_MODEL`, `OPENAI_TRANSCRIPTION_MODEL`); none are hard-coded. |
| Memory | `mem0ai==2.2.1` OSS SDK (Apache-2.0) in a FastAPI sidecar, using the pgvector store | **Verified from the package source**: `Memory.from_config`, `add(messages, user_id, metadata, infer)`, `search(query, filters={user_id}, top_k, threshold)`, `get_all(filters, top_k)`, `get`, `update`, `delete`, `delete_all`. The pip package ships no REST server, so the HTTP contract in `docker/mem0/app.py` is **ours**, not a Mem0-published API. |
| Memory writes | `infer=False` (verbatim, 1:1 id mapping) | Laravel's `MemoryExtractor` decides what to save under explicit policy. With `infer=True`, Mem0 runs its own LLM and may rewrite or delete *other* memories, which would break the local ledger, auditable deletion and tombstones. Cost: we do our own extraction and dedupe (`MemoryExtractor`, `MemoryConsolidationService`). |
| Mem0 history | Sidecar purges Mem0's SQLite `history` rows on update/delete | Verified: Mem0 writes the deleted text into `history.prev_value`. Without the purge, "delete" would not really delete. |
| Telemetry | `MEM0_TELEMETRY=False` | Mem0 sends usage telemetry by default (verified in `mem0/memory/telemetry.py`). |
| Proxy | Separate Caddy 2.11 container (automatic Let's Encrypt) | Only `/api/telegram/webhook` and `/up` are routed. Everything else returns 404. |
| Tests | PHPUnit 12, SQLite in-memory + fakes. Optional live Mem0 contract test | The suite makes no paid API calls. |

**Uncertainties, isolated behind adapters:**
- *OpenAI model capabilities and prices.* Operators set the model IDs and prices in env. Cost figures are estimates. If the chosen model rejects `temperature`, leave `OPENAI_TEMPERATURE` empty.
- *Mem0 OSS evolution.* The SDK is pinned, and all access goes through `MemoryProvider` and the sidecar. To swap engines, implement `MemoryProvider`.

## 2. Component diagram

```
Telegram ──HTTPS──▶ Caddy (TLS) ──▶ app (FrankenPHP/Laravel)
                                        │ TelegramWebhookController (secret check, fail-closed)
                                        │ TelegramUpdateRouter (dedupe, private-chat, allowlist, rate limit)
                                        ▼
                                   Redis queues ──▶ worker(s)
   ┌────────────────────────────────────┴─────────────────────────────────────────────┐
   │ ProcessInboundMessage → AgentOrchestrator ⇄ OpenAI (tools)                         │
   │        ContextBuilder = structured truth + Mem0 memories + recent window          │
   │        ToolExecutor = validate → ActionAuthorizationPolicy → idempotent execute   │
   │        Domain: GoalService TaskService ProgressService CheckpointService          │
   │                PendingActionService SettingsService ActivityLogger                │
   │ TranscribeVoiceMessage → OpenAI STT → ProcessInboundMessage                       │
   │ HandleCallbackQuery → CallbackQueryHandler (buttons, ownership-scoped)            │
   │ ExtractMemories → MemoryExtractor → MemoryLedger → SyncMemoryToProvider ─▶ mem0   │
   │ SendTelegramMessage / SendCheckpoint → OutboundMessenger (idempotent) ─▶ Telegram │
   └───────────────────────────────────────────────────────────────────────────────────┘
scheduler: dispatch-checkpoints (1m) · plan-rituals (5m) · daily-summaries (hourly)
           memory retry-failed (10m) · memory consolidate (daily) · prune (daily)
postgres (companion DB + mem0 DB w/ pgvector) · redis · mem0 sidecar — internal network only
```

Code map: `app/Telegram`, `app/Agent` (+`Tools/`, `OpenAI/`), `app/Domain`, `app/Memory`,
`app/Proactive`, `app/Jobs`, `app/Console/Commands`.

## 3. Request flow (inbound text)

1. Telegram POSTs to `/api/telegram/webhook`. The `X-Telegram-Bot-Api-Secret-Token` header is compared with `hash_equals`. A missing secret returns 503 (fail closed).
2. The router inserts `telegram_updates(update_id UNIQUE)`, so a duplicate delivery is a no-op. Group chats are ignored, and non-allowlisted users are rejected silently.
3. The message is stored with `dedup_key = tg:{chat}:{message_id}` (unique), and `ProcessInboundMessage` is queued. The webhook returns 200 immediately.
4. The job runs under `WithoutOverlapping(agent-user:{id})`, so each user's turns are serialized. It builds context and runs a bounded loop: at most N iterations and N tool calls, a runtime cap, `max_completion_tokens`, and the daily budget. On the last iteration tools are withheld, which forces a text answer.
5. Each tool call is JSON-decoded, checked with Laravel `Validator` rules (the authoritative check; the strict schema is advisory), passed through the policy, and executed. Mutating calls are logged in `tool_executions` with key `m{message}:{tool}:{hash(args)}`. A queue retry of the same turn *replays* the result instead of applying it twice.
6. The reply is persisted (`dedup reply:{id}`) and sent by `SendTelegramMessage`, which uses notification-log key `msg:{id}`. Memory extraction is then queued.

## 4. Memory & context

Three layers, in priority order. `ContextBuilder` gives each a share of `AGENT_CONTEXT_TOKEN_BUDGET`: 40% structured, 20% memories, the rest history.

1. **Structured truth** (PostgreSQL): settings; active goals; today's, tomorrow's and other open tasks with commitment level; yesterday's *confirmed* completions vs *planned but unconfirmed* work; pending confirmations; upcoming check-ins.
2. **Semantic memory** (Mem0): the top-k hits for the current message, deduped by content hash and ranked by relevance, a 30-day recency decay and verification level. Each hit carries `ref`, category, recorded date and verification.
3. **Recent conversation**: a bounded window, keeping the newest messages when trimming.

**Ledger pattern.** The `memories` table is authoritative. Mem0 is a derived index.
- *Write:* a local row (`pending_sync`) is created first, then `SyncMemoryToProvider` stores it in Mem0 and attaches `mem0_id` with a **guarded update** (`WHERE status IN (pending_sync, sync_failed)`). If the row was deleted while the provider call was in flight, the job deletes the copy it just created.
- *Retrieve:* a Mem0 hit counts only if it maps to a **live local row owned by this user**. This guards against tombstones and tenant leaks even if the provider misbehaves.
- *Degraded mode:* if Mem0 is down, writes stay `sync_failed`, with the error and attempt count. Jobs retry with backoff, and `companion:memory retry-failed` re-queues them every 10 minutes. Retrieval falls back to local keyword search, and the search tool tells the model results may be incomplete. Chat and tasks are unaffected.
- *Corrections:* the extractor may set `supersedes_memory_ref`, but only to refs it was actually shown. The old row becomes `superseded` (kept locally for history) and is removed from Mem0.
- *Consolidation (nightly):* near-duplicates (similarity ≥ `MEMORY_DUPLICATE_SIMILARITY`, same category) are merged so the newer one supersedes the older. Orphaned provider rows older than one hour are removed.
- *Daily summaries:* each night (03:00 local) a deterministic, source-referenced `daily_summaries` row is written. Its optional LLM narrative is also indexed as an `episode` memory, which makes "what happened a month ago?" retrievable.
- *Deletion:* the local row is tombstoned first (content wiped, hash kept, `DataDeletionRequest` audit row), then the provider delete runs and retries until confirmed. Re-extraction of a deleted hash is refused. A full erasure stamps `users.data_purged_at`, and every job checks it and drops pre-erasure work.

## 5. Temporal semantics

- Instants are stored as `timestamptz` in UTC. `StoresUtc` normalizes every Eloquent datetime, and the pgsql session timezone is pinned to UTC. Local calendar concepts (`planned_for_date`, `due_date`, ritual `local_date`) are `DATE` columns interpreted in the user's IANA zone.
- `LocalTime::parseLocal` handles DST edge cases. A nonexistent time (spring-forward gap) moves to the first valid instant after the gap. An ambiguous time (fall-back) resolves to the earlier occurrence. Explicit offsets are honored.
- A deadline with no time is stored as `due_date` and means end of local day. Committed date-only deadlines get a 16:00 local check.
- Quiet hours are overnight-aware (`22:00–08:00`), and deferral computes the next local end time in a DST-safe way.
- Commitment levels: `idea` (tentative, never chased), `intention` (planned), `commitment` (explicit promise or deadline; changes need confirmation). A **confirmed outcome** is `completion_confirmed_at` plus the evidence message id. Only a user message in the current turn or a button press counts as evidence.
- `activity_events` is append-only (model guards throw on update or delete). The sole exception is user-requested erasure.

## 6. Proactive engine

Deterministic scheduling, with the LLM used only for phrasing:
- **Checkpoints** are concrete rows with an idempotency key, created by rituals (`ritual:{kind}:{user}:{date}:{HH:MM}`), deadline planning (pre-deadline check and post-deadline "how did it go"), blocker follow-ups, explicit `schedule_checkin` calls and snoozes.
- **Claiming:** `companion:dispatch-checkpoints` runs every minute (`onOneServer` + `withoutOverlapping`). It claims rows with `UPDATE … WHERE id=? AND state='pending'`, so only one claimer can win, and gives each claim a token with a TTL. Stale claims return to pending, and fail after max attempts.
- **NotificationPolicy** runs on *fresh* state right before sending. It checks the global and user pause, window expiry, task closed/idea/unblocked/recently updated, recent follow-up on the same task, quiet hours, silence backoff (no repeat after an unanswered adaptive message; 2 unanswered means only morning planning; 4 means stop), active-conversation deferral, minimum gap and the daily cap for adaptive check-ins. It returns `send`, `defer(until)`, `suppress` or `expire`.
- **ProactivePlanner** composes the message from current DB facts plus recent conversation. The model may return `send=false` for stale adaptive check-ins, but rituals always go out. On an LLM error or exhausted budget it falls back to a deterministic template.
- **Delivery** goes through `OutboundMessenger` with key `checkpoint:{id}`. The unique notification-log row is claimed *before* calling Telegram, so retries and concurrent workers cannot double-send (at-most-once on crash; rows stuck in `sending` become `unknown` and are never auto-resent). A 429 defers by `retry_after`, and permanent 4xx errors fail without retry.

## 7. Security model

- Identity comes only from the Telegram `from.id` via the allowlist, through `UserResolver` to a local user. The model never sees or provides user IDs. Every tool resolves records through `$user->tasks()` and similar scoped relations.
- Callback buttons (`t:/c:/s:`) are re-resolved inside the presser's scope. Confirmation nonces are 20 random characters. Old buttons expire (`TELEGRAM_CALLBACK_TTL_HOURS`), and confirmations expire (`CONFIRMATION_TTL_HOURS`).
- `ActionAuthorizationPolicy`: mutating tools are blocked in proactive turns. `complete_task`, `confirm_pending_action`, `save_memory`, `forget_memory` and `request_data_deletion` require an explicit user message in the current turn. `confirm_pending_action` refuses a proposal made in the same turn, and data deletion can be confirmed only by button.
- Prompt injection: retrieved memories, task titles and structured data are wrapped in `<untrusted>` tags, and embedded closing tags are neutralized. The system prompt forbids following instructions found inside. Regardless of what the model says, enforcement stays in code.
- Logs contain IDs, lengths, statuses and token counts, never message text, transcripts or tokens. Voice files go to a 0700 temp dir and are deleted in `finally`. Magic bytes, size, duration and MIME type are validated.
- Network: PostgreSQL, Redis and Mem0 sit on an `internal: true` network with no published ports. Containers are read-only where feasible, run as non-root with `no-new-privileges` and drop capabilities.

## 8. Observability & cost

- A correlation ID is added via Laravel `Context` at the webhook (and propagated into queued jobs), along with `telegram_update_id` and `user_id`. Logs are structured JSON on stderr in Docker.
- `agent_runs` stores the trigger, model, iterations, tool calls, prompt and completion tokens, estimated USD and latency. `tool_executions` stores per-tool status and latency. `notification_log` stores delivery outcomes.
- `companion:stats` reports usage, cost, deliveries and checkpoint states. `GET /api/health` (bearer `HEALTH_TOKEN`, not exposed publicly) reports DB, cache and Mem0 status, queue sizes, failed jobs, pending memory syncs and failed deliveries. `/up` is the liveness probe.
- Budgets: `AGENT_DAILY_BUDGET_USD` per user. When exceeded, chat returns a deterministic reply and proactive messages use templates.

## 9. Verification status (2026-11-14)

- PHPUnit (85 tests): green on SQLite **and on PostgreSQL 17 + pgvector** (the compose `postgres` service).
- Live Mem0 contract test: green against the **containerized sidecar on pgvector 0.8.7** (real `mem0ai` 2.2.1; deterministic test embeddings, so no OpenAI key needed). It covers add, search, list, scope isolation, refused foreign deletes, delete and delete-all. A separate check confirmed history text is purged on delete.
- Docker stack: images build; `migrate` exits 0; app, worker, scheduler, postgres, redis and mem0 are healthy. A signed webhook goes through Redis to the worker and the DB, and is delivered (or fails permanently with 401 when no token is set); replays are deduplicated, and unsigned requests get 401.
- Lessons that shaped the code: (1) in PostgreSQL a unique-violation aborts the surrounding transaction, so every conflict-tolerant insert runs in its own transaction or savepoint; (2) Telegram misconfiguration returns a permanent failure result instead of throwing, so a cosmetic call (the typing indicator) can never fail a turn; (3) Redis returns numeric cache values as strings.
- Not verified here: real OpenAI chat/STT calls, the real Telegram API, public TLS (needs a domain), and the Caddy `proxy` service (dev overlay disables it).

## 10. Future SaaS (not implemented)

See [saas-roadmap.md](saas-roadmap.md).
