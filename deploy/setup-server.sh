#!/usr/bin/env bash
# One-time setup of Noahport MDM on the shared VPS, run as `deploy` from /opt/noahport-mdm (see deploy/README.md).
# Idempotent: existing secrets and files are kept. Touches shared things only the way the other projects do:
# its own site file + guard in /opt/caddy-sites, its own network mdm-edge-net, and a validated graceful Caddy reload.
set -euo pipefail
cd "$(dirname "$0")"
URL=https://mdm.94-72-102-13.sslip.io

# 1. Secrets, generated here and never committed.
if [ ! -f .env ]; then
  umask 077
  printf 'DB_PASSWORD=%s\n' "$(openssl rand -hex 24)" > .env
fi
if [ ! -f .env.production ]; then
  umask 077
  . ./.env
  cat > .env.production <<EOF
APP_NAME="Noahport MDM"
APP_ENV=production
APP_KEY=base64:$(openssl rand -base64 32)
APP_DEBUG=false
APP_URL=$URL
APP_DISPLAY_TIMEZONE=Africa/Nairobi
LOG_CHANNEL=stack
LOG_STACK=daily
LOG_LEVEL=info
DB_CONNECTION=pgsql
DB_HOST=postgres
DB_PORT=5432
DB_DATABASE=mdm
DB_USERNAME=mdm
DB_PASSWORD=$DB_PASSWORD
SESSION_DRIVER=redis
SESSION_SECURE_COOKIE=true
QUEUE_CONNECTION=redis
CACHE_STORE=redis
REDIS_HOST=redis
MAIL_MAILER=log
EOF
fi

# Server-only secret files (Firebase service account), mounted read-only into the app containers.
mkdir -p secrets && chmod 700 secrets

# 2. Network shared with the edge Caddy only.
docker network inspect mdm-edge-net >/dev/null 2>&1 || docker network create mdm-edge-net >/dev/null

# 3. Site block + guard, installed the same way as U-SAFE's.
install -m 664 caddy/mdm.caddy /opt/caddy-sites/mdm.caddy
install -m 775 caddy/mdm-guard.sh /opt/caddy-sites/mdm-guard.sh
sudo install -m 644 systemd/mdm-edge-guard.path systemd/mdm-edge-guard.service systemd/mdm-edge-guard.timer /etc/systemd/system/
sudo systemctl daemon-reload
sudo systemctl enable --now mdm-edge-guard.path mdm-edge-guard.timer

# 4. Nightly database backup at 02:30 server time, 14 days kept.
mkdir -p ../backups
# (`crontab -l` fails when the user has no crontab yet, and grep -v fails on empty input: neither is an error here.)
{
  { crontab -l 2>/dev/null || true; } | { grep -v 'noahport-mdm/deploy/backup.sh' || true; }
  echo '30 2 * * * /opt/noahport-mdm/deploy/backup.sh >> /opt/noahport-mdm/backups/backup.log 2>&1'
} | crontab -

echo "Setup done. Next: deploy/deploy.sh"
