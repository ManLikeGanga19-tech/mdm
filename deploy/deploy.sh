#!/usr/bin/env bash
# Deploy the latest main branch on the VPS: run as `deploy` from anywhere. Safe to re-run.
set -euo pipefail
cd /opt/noahport-mdm
git pull --ff-only

dc() { docker compose -f deploy/docker-compose.prod.yml "$@"; }
dc build app
dc up -d --remove-orphans
dc exec -T app php artisan migrate --force
dc exec -T app php artisan optimize
dc exec -T app php artisan filament:optimize
dc exec -T app php artisan horizon:terminate # Horizon restarts itself on the new code

# Put the site block in place now instead of waiting for the guard's next tick.
/opt/caddy-sites/mdm-guard.sh
dc ps
