#!/usr/bin/env bash
# Deploy the latest main branch on the VPS: run as `deploy` from anywhere. Safe to re-run.
set -euo pipefail
cd /opt/noahport-mdm
git pull --ff-only

dc() { docker compose -f deploy/docker-compose.prod.yml "$@"; }

# Real-time hub keys (Mercure, see docker-compose.prod.yml): generated once, kept in deploy/.env (mode 600).
if ! grep -q '^MERCURE_PUBLISHER_JWT_KEY=' deploy/.env; then
  printf 'MERCURE_PUBLISHER_JWT_KEY=%s\nMERCURE_SUBSCRIBER_JWT_KEY=%s\nMERCURE_RESOURCE_IDENTIFIER=%s\n' \
    "$(openssl rand -hex 32)" "$(openssl rand -hex 32)" "https://mdm.94-72-102-13.sslip.io/.well-known/mercure" >> deploy/.env
fi
dc build app
dc up -d --remove-orphans
dc exec -T app php artisan migrate --force
dc exec -T app php artisan optimize
dc exec -T app php artisan filament:optimize
dc exec -T app php artisan horizon:terminate # Horizon restarts itself on the new code

# Put the site block in place now instead of waiting for the guard's next tick.
/opt/caddy-sites/mdm-guard.sh || true
dc ps
