#!/usr/bin/env bash
# Nightly Postgres dump of the MDM database (cron, set up by setup-server.sh). Keeps 14 days.
set -euo pipefail
dir=/opt/noahport-mdm/backups
mkdir -p "$dir"
file="$dir/mdm-$(date -u +%Y%m%dT%H%MZ).sql.gz"
docker compose -f /opt/noahport-mdm/deploy/docker-compose.prod.yml exec -T postgres pg_dump -U mdm -d mdm --no-owner | gzip > "$file"
find "$dir" -name 'mdm-*.sql.gz' -mtime +14 -delete
echo "$(date -u +%FT%TZ) backup ok $(du -h "$file" | cut -f1) $file"
