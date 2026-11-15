# Data model

All tables are user-scoped (`user_id` FK, cascade on delete). Instants are `timestamptz` in UTC. Local days are `DATE` columns plus `users.timezone`.

| Table | Purpose | Key constraints |
|---|---|---|
| `users` | Telegram identity (`telegram_user_id` unique, `telegram_chat_id`), locale, IANA `timezone`, `is_enabled`, `last_user_message_at`, `last_proactive_at`, `data_purged_at` | unique telegram id |
| `user_settings` | morning/evening HH:MM, quiet start/end, max adaptive check-ins per day, min gap, proactive/morning/evening toggles, `paused_until` | 1:1 user |
| `telegram_updates` | ledger of every inbound update and its routing outcome | **unique `update_id`**, which dedupes webhook retries |
| `conversations` | one rolling conversation per user and chat | unique (user, chat) |
| `messages` | transcript: role, source (text/voice/command/callback/proactive/agent_reply), text, Telegram ids, voice metadata, `processing_state`, `reply_markup`, `checkpoint_id` | **unique `dedup_key`** (`tg:{chat}:{msg}`, `reply:{id}`, `checkpoint:{id}`, `cb:{update}`) |
| `projects`, `goals` | grouping and longer-term outcomes. Goals have priority, `target_date`, provenance (`created_from_message_id`) | unique (user, project name); goal `idempotency_key` |
| `tasks` | `status` (todo/in_progress/blocked/done/canceled), `commitment` (idea/intention/commitment), priority, `due_at` (instant) **or** `due_date` (date-only), `planned_for_date`, blocker, progress %, parent task, provenance, `completed_at` + `completion_confirmed_at` + `completion_source_message_id` | `idempotency_key` unique; indexes on (user, status/planned/due) |
| `activity_events` | **append-only** history: subject, event type, actor, source message, old/new JSON, note, `occurred_at` | model guards block update and delete |
| `checkpoints` | scheduled check-ins: kind, task/goal, reason, `local_date`, `earliest_at`/`latest_at`, `state`, `claimed_by` token, `claimed_until`, attempts, outcome reason | **unique `idempotency_key`**; index (state, earliest_at) |
| `daily_summaries` | per local day: planned, confirmed, blockers, open loops, narrative, source event/message ids | unique (user, local_date) |
| `memories` | local ledger for Mem0: `mem0_id`, content (wiped on delete), `content_hash`, category, confidence, verification (stated/inferred/confirmed), status (pending_sync/synced/sync_failed/superseded/deleted), provenance, `superseded_by_id`, validity window, sync attempts and error, tombstone fields | unique `mem0_id` |
| `data_deletion_requests` | audit trail of erasures (scope, status, counts; never content) | — |
| `pending_action_confirmations` | proposed material change, `nonce` (callback secret), status, expiry, decided via button or chat | unique nonce |
| `notification_log` | outbound delivery ledger: kind, status (sending/sent/failed/unknown), Telegram message id, attempts, error code | **unique `idempotency_key`** |
| `agent_runs`, `tool_executions` | usage, cost and latency per LLM interaction; per-tool audit with replay key | tool `idempotency_key` unique |

Status semantics worth noting:
- `tasks.status = done` is set **only** by `ProgressService::complete`, which requires a user turn or a button press, and `completion_source_message_id` links to the evidence.
- Planned work is `planned_for_date` or a deadline. Confirmed work is the `completed` events in `activity_events`. Summaries and context keep the two separate.
- Corrections never overwrite history. Superseded memories and events stay, and new rows reference them.
