# From single-user MVP to multi-tenant SaaS (not implemented)

Already in place: every table is user-scoped, the Mem0 scope comes from the server-side user ID, tools resolve records only through the authenticated user's relations, there are cross-tenant tests, and costs are tracked per user.

Needed for SaaS:
1. **Onboarding:** replace the allowlist with self-service signup (`/start` → consent, terms, privacy notice, timezone confirmation), plus abuse protection and a waitlist.
2. **Tenant quotas:** per-plan daily and monthly token budgets (extend `BudgetGuard`), message rate limits, memory count caps, and limits on voice minutes.
3. **Billing:** Stripe (or similar) subscriptions with a `plans` / `subscriptions` model, webhooks, and a grace-period downgrade to the deterministic mode that already exists.
4. **Isolation hardening:** PostgreSQL row-level security keyed on `app.user_id` as defense in depth, per-tenant Mem0 collections or a vetted shared index with scope filters, and encryption at rest for message text (`encrypted` casts with key rotation).
5. **Compliance:** GDPR DPA, data processing records, an exportable data request (JSON export of tasks, events and memories), retention configurable per tenant, a subprocessor list (OpenAI), deletion SLAs that cover backups, and a region choice.
6. **Scale:** Horizon for queue visibility, per-user queue fairness, Telegram global send rate limiting (about 30 msg/s per bot), a read replica, and external metrics (Prometheus/OpenTelemetry).
7. **Support tooling:** an admin console with audited access (none exists today, on purpose), and a feature-flag service.
