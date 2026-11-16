#!/bin/sh
# Role-based entrypoint. Config is cached at start because env vars are only known at runtime.
set -eu
cd /app

role="${1:-app}"
shift || true

wait_for_db() {
  i=0
  until php artisan db:show --json >/dev/null 2>&1; do
    i=$((i + 1))
    if [ "$i" -ge 60 ]; then echo "database not reachable after 120s" >&2; exit 1; fi
    sleep 2
  done
}

php artisan config:cache >/dev/null
php artisan route:cache >/dev/null
php artisan event:cache >/dev/null

case "$role" in
  app)
    wait_for_db
    exec frankenphp run --config /etc/frankenphp/Caddyfile.app
    ;;
  worker)
    wait_for_db
    # --max-time/--max-jobs recycle the process (memory hygiene); Docker restarts it.
    exec php artisan queue:work redis --queue=high,default,memory,low --sleep=1 --tries=3 --backoff=10 --timeout=170 --max-time=3600 --max-jobs=1000 --memory=256 "$@"
    ;;
  scheduler)
    wait_for_db
    exec php artisan schedule:work
    ;;
  poller)
    wait_for_db
    exec php artisan telegram:poll
    ;;
  migrate)
    wait_for_db
    exec php artisan migrate --force --isolated
    ;;
  *)
    exec "$role" "$@"
    ;;
esac
