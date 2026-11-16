# Running at home on a Raspberry Pi

The companion runs fine on a **Raspberry Pi 4 or 5 with 4 GB RAM** under a **64-bit** OS. At home it uses
**long polling** (`TELEGRAM_MODE=polling`): the Pi opens outbound connections to Telegram and OpenAI and
nothing else. You need no domain, no TLS certificate, no router port forwarding and no open ports.

| Resource | Measured / limit |
|---|---|
| RAM | ~0.4–0.5 GB idle for the whole stack. `compose.home.yaml` caps each service (total cap ≈ 3 GB) |
| Disk | ~2.3 GB of images plus data (small: text, tasks, memories). Container logs are rotated (3 × 10 MB per service) |
| CPU | Idle most of the time. The AI work happens at OpenAI |
| Architecture | `linux/arm64`. Images built on an Apple Silicon Mac are already arm64 |

## 1. Prepare the Pi
1. Flash **Raspberry Pi OS Lite (64-bit)** with Raspberry Pi Imager. In its settings enable SSH with your
   **public key** (no password login), and set the hostname (e.g. `companion`), Wi-Fi and timezone.
2. Storage: a microSD card works, but PostgreSQL writes wear SD cards out and they fail without warning.
   For something you rely on, boot from a **USB SSD** (Pi 4 supports USB boot). Either way, keep backups (§5).
3. On the Pi:
   ```bash
   sudo apt update && sudo apt full-upgrade -y
   sudo apt install -y unattended-upgrades git
   curl -fsSL https://get.docker.com | sudo sh      # official Docker install script
   sudo usermod -aG docker $USER && exit            # log in again afterwards
   timedatectl                                      # "System clock synchronized: yes" (check-ins depend on it)
   ```

## 2. Get the project onto the Pi
From your Mac, in the project folder, copy the code **without** secrets, dependencies or local state:
```bash
rsync -av --exclude .env --exclude vendor --exclude node_modules --exclude 'storage/logs/*' \
  ./ companion.local:~/companion/
```
Images: build them on the Pi (`--build` below; the first build takes a while on a Pi 4), or build on the
Mac and copy them over. This is faster, and arm64 Mac images run as-is on the Pi:
```bash
docker compose -f compose.yaml -f compose.dev.yaml build app mem0
docker save companion-app:latest companion-mem0:latest | gzip | ssh companion.local 'gunzip | docker load'
```

## 3. Configure `.env` on the Pi
Create it **on the Pi** (never commit it, never send it through chat):
```bash
cd ~/companion && cp .env.example .env && chmod 600 .env && nano .env
```
- `TELEGRAM_MODE=polling`
- `APP_ENV=production`, `APP_DEBUG=false`, and `APP_URL=http://localhost` (unused, but must be set)
- `TELEGRAM_BOT_TOKEN`, `TELEGRAM_ALLOWED_USER_IDS`
- `OPENAI_API_KEY`, the model IDs and the `OPENAI_PRICE_*` values
- random internal secrets. Generate each with `openssl rand -hex 32`: `DB_PASSWORD`, `MEM0_PG_PASSWORD`,
  `REDIS_PASSWORD`, `MEM0_API_KEY`, `HEALTH_TOKEN`
- `APP_KEY`: `echo "base64:$(openssl rand -base64 32)"` and paste the output
- leave `APP_DOMAIN`, `ACME_EMAIL` and `TELEGRAM_WEBHOOK_SECRET` empty (webhook-only)

### Sharing the Pi with other Docker apps
The home stack publishes **no ports** and namespaces everything as `companion_*` (containers, networks,
volumes), so it doesn't collide with other compose projects. Two things to check first:
- **Use a bot token of its own.** On start the poller calls `deleteWebhook` for its token. If another app
  uses the same bot through a webhook (ngrok, for example), that app stops receiving messages.
- **Headroom:** check `free -h` and `docker stats --no-stream`. Budget about 0.5 GB idle and up to ~1 GB
  under load for this stack.

## 4. Start
> **Only one bot receiver may run at a time.** Stop the poller on your Mac (and its stack) before starting
> the Pi. Two pollers fighting over one bot get `409 Conflict` from Telegram.

```bash
cd ~/companion
docker compose -f compose.yaml -f compose.home.yaml --profile polling up -d        # add --build to build on the Pi
docker compose -p companion ps                                                     # all healthy; migrate "Exited (0)"
docker compose -p companion logs -f poller worker                                  # watch a message go through
```
Everything has `restart: unless-stopped`, so the stack comes back on its own after a reboot or power cut.
To save typing, create an alias:
`alias cdc='docker compose -f compose.yaml -f compose.home.yaml --profile polling'`. Then `cdc up -d`, `cdc logs -f`.

Health, from the Pi:
`docker compose -p companion exec app sh -c 'curl -s -H "Authorization: Bearer $HEALTH_TOKEN" http://127.0.0.1:8080/api/health'`

## 5. Backups (do this, especially on SD)
The commands in [deployment.md §5](deployment.md#5-backups--restore) work unchanged. Run them nightly from
cron and copy the files **off the Pi** (another computer, NAS or cloud, encrypted). A failed SD card with no
copy loses all history and memories.

## 6. Updating
```bash
# on the Mac: rsync again (as in §2), then on the Pi:
cd ~/companion && docker compose -f compose.yaml -f compose.home.yaml --profile polling up -d --build
```
Migrations run automatically (the one-shot `migrate` service) before the app, worker, scheduler and poller start.

## Switching to a VPS later
On the VPS, use `compose.yaml` without the overlay, set `TELEGRAM_MODE=webhook`, set the domain
variables and run `php artisan telegram:webhook set` (see [deployment.md](deployment.md)). Then stop the Pi.
The webhook and polling cannot be used at the same time.
