#!/usr/bin/env bash
# Noahport MDM shared-Caddy edge self-healing guard (same design as wifios-guard.sh on this server).
# Re-applies MDM's site block and re-attaches mdm-edge-net when a ShuleHQ deploy wipes them. Idempotent.
#
# STALE BIND MOUNT: /opt/shulehq/Caddyfile is bind-mounted into sms-caddy as a single FILE. When a deploy replaces
# that file (new inode), the container keeps the old inode, so `caddy reload --config /etc/caddy/Caddyfile` would
# reload OLD content and drop every site added since (this happened on 2026-10-15 and dropped WiFiOS). So the current
# host file is staged into the DIRECTORY mount (/opt/caddy-sites -> /etc/caddy/sites, which does propagate) and
# Caddy validates and reloads from that copy. Never reload from /etc/caddy/Caddyfile.
set -uo pipefail
CADDY=/opt/shulehq/Caddyfile
SITE=/opt/caddy-sites/mdm.caddy
HOST=mdm.94-72-102-13.sslip.io
LOG=/opt/caddy-sites/mdm-guard.log
STATE=/opt/caddy-sites/.state
LOCK="$STATE/mdm-guard.lock"
CAND="$STATE/mdm-candidate.Caddyfile"
CAND_IN_EDGE=/etc/caddy/sites/.state/mdm-candidate.Caddyfile
NET=mdm-edge-net
EDGE=sms-caddy
mkdir -p "$STATE"
exec 9>"$LOCK" || exit 0
flock -n 9 || exit 0
ts(){ date -u +%Y-%m-%dT%H:%M:%SZ; }
healed=0; why=""
if ! docker inspect -f "{{range \$k,\$v := .NetworkSettings.Networks}}{{\$k}} {{end}}" "$EDGE" 2>/dev/null | grep -qw "$NET"; then
  docker network connect "$NET" "$EDGE" 2>/dev/null && { healed=1; why="network "; }
fi
if ! grep -q "^$HOST {" "$CADDY" 2>/dev/null; then
  if [ -r "$SITE" ]; then printf "\n" >> "$CADDY"; cat "$SITE" >> "$CADDY"; healed=1; why="${why}config "; fi
fi
[ "$healed" = 1 ] || exit 0
if [ "$(docker inspect -f "{{.State.Running}}" "$EDGE" 2>/dev/null)" != "true" ]; then
  echo "$(ts) DEFERRED ($why) — $EDGE not running" >> "$LOG"; exit 0
fi
cp "$CADDY" "$CAND" 2>/dev/null || { echo "$(ts) STAGE FAILED ($why)" >> "$LOG"; exit 0; }
if docker exec "$EDGE" caddy validate --adapter caddyfile --config "$CAND_IN_EDGE" >/dev/null 2>&1; then
  if docker exec "$EDGE" caddy reload --adapter caddyfile --config "$CAND_IN_EDGE" >/dev/null 2>&1; then
    echo "$(ts) HEALED ($why)" >> "$LOG"
  else
    echo "$(ts) RELOAD FAILED ($why)" >> "$LOG"
  fi
else
  echo "$(ts) VALIDATE FAILED after re-append ($why)" >> "$LOG"
fi
