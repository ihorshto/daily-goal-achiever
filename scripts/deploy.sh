#!/usr/bin/env bash
# Deploy the latest main on the Raspberry Pi: pull, rebuild, restart.
# Migrations run automatically via the one-shot `migrate` service.
#
# Usage (on the Pi):  ~/companion/scripts/deploy.sh
set -euo pipefail

cd "$(dirname "$0")/.."

COMPOSE=(docker compose -f compose.yaml -f compose.home.yaml --profile polling)

if [[ ! -f .env ]]; then
    echo "ERROR: .env is missing in $(pwd)" >&2
    exit 1
fi

if [[ -n "$(git status --porcelain --untracked-files=no)" ]]; then
    echo "ERROR: local changes to tracked files; commit or discard them first:" >&2
    git status --short --untracked-files=no >&2
    exit 1
fi

before=$(git rev-parse --short HEAD)
git pull --ff-only
after=$(git rev-parse --short HEAD)

if [[ "$before" == "$after" ]]; then
    echo "Already up to date ($after); rebuilding anyway."
else
    echo "Updated $before -> $after:"
    git log --oneline "$before..$after"
fi

"${COMPOSE[@]}" up -d --build --remove-orphans

if [[ "$("${COMPOSE[@]}" ps -a migrate --format '{{.ExitCode}}' 2>/dev/null)" != "0" ]]; then
    echo "WARNING: migrate did not exit cleanly; check: ${COMPOSE[*]} logs migrate" >&2
fi

"${COMPOSE[@]}" ps
