# Deployment, backups & runbooks

## 1. VPS prerequisites
- Ubuntu 24.04 or Debian 12/13 with ≥2 vCPU and ≥4 GB RAM. Mem0 and Python use about 400 MB.
- Docker Engine with the Compose v2 plugin.
- A DNS `A`/`AAAA` record for `APP_DOMAIN` pointing at the VPS.
- Firewall: allow 22, 80, 443 (TCP) and 443/udp (HTTP/3). Nothing else. PostgreSQL, Redis and Mem0 publish no ports.

## 2. First deploy
```bash
git clone <repo> /opt/companion && cd /opt/companion
cp .env.example .env && chmod 600 .env
# fill .env: APP_KEY (see below), APP_URL=https://$APP_DOMAIN, APP_DOMAIN, ACME_EMAIL,
# DB_PASSWORD, MEM0_PG_PASSWORD, REDIS_PASSWORD, MEM0_API_KEY, HEALTH_TOKEN, TELEGRAM_*, OPENAI_*
for v in DB_PASSWORD MEM0_PG_PASSWORD REDIS_PASSWORD MEM0_API_KEY HEALTH_TOKEN TELEGRAM_WEBHOOK_SECRET; do
  echo "$v=$(openssl rand -hex 32)"; done      # paste into .env
echo "APP_KEY=base64:$(openssl rand -base64 32)"  # paste into .env
docker compose build
docker compose up -d            # migrate runs once and must succeed before app/worker/scheduler start
docker compose ps               # all healthy; `migrate` shows Exited (0)
docker compose exec app php artisan telegram:webhook set
docker compose exec app php artisan telegram:webhook info
```
Caddy obtains the TLS certificate automatically on the first request. Check with `docker compose logs proxy`.

## 3. Updating (zero data loss, brief downtime)
```bash
cd /opt/companion && git pull
docker compose build app mem0
docker compose run --rm migrate          # explicit migration step (idempotent, --isolated lock)
docker compose up -d --remove-orphans    # recreates app/worker/scheduler; workers finish jobs (grace 180s)
```
Webhook deliveries that arrive during a restart are retried by Telegram, and `update_id` dedupe makes those retries safe. Scheduled checkpoints simply wait in PostgreSQL. Migrations must stay backwards-compatible for one release (expand, then contract).

## 4. Secrets
- Secrets live only in `/opt/companion/.env` (mode 600, owned by the deploy user) and are injected through `env_file`. They are never committed: `.gitignore` and `.dockerignore` exclude `.env*`.
- `config:cache` runs at container start, so secrets never end up inside images.
- Rotation: edit `.env`, then run `docker compose up -d`. For `TELEGRAM_WEBHOOK_SECRET`, also run `telegram:webhook set`. For `MEM0_PG_PASSWORD`, also run `ALTER ROLE mem0 PASSWORD '…'` in Postgres. `DB_PASSWORD` works the same way for the `companion` role.
- To harden further, use Docker secrets or sops/age-encrypted `.env` files decrypted at deploy time. Keep the VPS disk encrypted where the provider supports it.

## 5. Backups & restore
Back up three things: **PostgreSQL** (both the `companion` and `mem0` databases), the **mem0 volume** (`history.db`, with deleted text already purged), and **`.env`** (stored separately in a password manager).
```bash
# nightly (cron on host), keeps 14 days
ts=$(date +%F)
docker compose exec -T postgres pg_dumpall -U companion | gzip > /var/backups/companion/pg-$ts.sql.gz
docker run --rm -v companion_mem0data:/d -v /var/backups/companion:/b alpine tar czf /b/mem0-$ts.tgz -C /d .
find /var/backups/companion -mtime +14 -delete
```
Copy backups off-host (restic, borg or S3), encrypted. **Restore:**
```bash
docker compose down
docker volume rm companion_pgdata companion_mem0data
docker compose up -d postgres && sleep 10
gunzip -c pg-YYYY-MM-DD.sql.gz | docker compose exec -T postgres psql -U companion -d postgres
docker run --rm -v companion_mem0data:/d -v $PWD:/b alpine tar xzf /b/mem0-YYYY-MM-DD.tgz -C /d
docker compose up -d
```
Because PostgreSQL is authoritative, restoring the `mem0` database is optional. Without it, run `php artisan tinker` and reset `memories.status='pending_sync', mem0_id=null` for live rows, then `companion:memory retry-failed` re-indexes them.

**Erasure vs backups:** user data deletion does not rewrite old backups. Backups expire after 14 days, and this retention is documented to the user.

## 6. Supervision
- Each role (`app`, `worker`, `scheduler`) is its own container with `restart: unless-stopped` and a health check. Workers recycle themselves (`--max-time=3600 --max-jobs=1000`), and Docker restarts them.
- Readiness is gated by health checks (`service_healthy`, `service_completed_successfully`), and the entrypoint also retries the DB for 120 seconds. `depends_on` alone is not relied upon.
- Scale workers with `docker compose up -d --scale worker=2`. Per-user `WithoutOverlapping` locks plus the checkpoint claim tokens keep this safe.

## 7. Runbooks

| Symptom | Check | Fix |
|---|---|---|
| Bot silent | `docker compose exec app php artisan telegram:webhook info` (last_error_message, pending_update_count) | DNS/TLS: `docker compose logs proxy`. Secret mismatch (401s in app logs): run `telegram:webhook set` again |
| Replies slow or stuck | `curl -H "Authorization: Bearer $HEALTH_TOKEN" http://127.0.0.1:8080/api/health` from `docker compose exec app` | queue backlog: `docker compose logs worker`; scale workers |
| Failed jobs | `php artisan queue:failed` | inspect, then `php artisan queue:retry <id>`. `queue:prune-failed` runs daily (30 days) |
| Mem0 down (health `degraded: true`) | `docker compose logs mem0`, `php artisan companion:memory status` | fix, then `companion:memory retry-failed`. Chat keeps working meanwhile |
| OpenAI errors or cost spike | `php artisan companion:stats --days=1` | adjust `AGENT_DAILY_BUDGET_USD`, models, limits |
| Duplicate or missed check-in | `select state, outcome_reason from checkpoints order by id desc limit 20;` and `notification_log` | suppression reasons explain policy decisions. `unknown` rows (crash mid-send) are never resent |
| User asks to stop | they send `/pause` or say "stop messaging me" | emergency stop for everyone: set `PROACTIVE_ENABLED=false`, then `docker compose up -d` |

## 8. Local development
```bash
composer install && cp .env.example .env && php artisan key:generate
php artisan test                                  # full suite, no network or paid APIs
docker compose -f compose.yaml -f compose.dev.yaml up -d --build postgres redis mem0
# live Mem0 contract test (sidecar with MEM0_TEST_EMBEDDER=1 needs no OpenAI key):
MEM0_CONTRACT_URL=http://127.0.0.1:8001 MEM0_CONTRACT_KEY=<MEM0_API_KEY> php artisan test --group=mem0-live
# talk to a dev bot without HTTPS:
php artisan telegram:poll   # plus `php artisan queue:work` and `php artisan schedule:work`
```
