# Deploying Noahport MDM to the Contabo VPS

The VPS (94.72.102.13) is shared with other projects (ShuleHQ, WoodenHouses, U-SAFE, WiFiOS). Their shared edge
Caddy (`sms-caddy`, owned by ShuleHQ) holds ports 80/443. Each project keeps its site block in
`/opt/caddy-sites/<project>.caddy` and a guard re-appends it to `/opt/shulehq/Caddyfile` whenever a ShuleHQ deploy
overwrites that file. MDM follows the same pattern and never touches the other projects' containers.

**Never run `caddy reload --config /etc/caddy/Caddyfile` in `sms-caddy`.** That path is a single-file bind mount
that goes stale when ShuleHQ's deploy replaces the host file; reloading it silently drops every site added since
(on 2026-10-15 it dropped WiFiOS). Stage the host file into `/opt/caddy-sites/.state/` and reload from
`/etc/caddy/sites/.state/<name>.Caddyfile`, as `mdm-guard.sh` and `wifios-guard.sh` do.

| What | Where |
|---|---|
| Address | https://mdm.94-72-102-13.sslip.io (sslip.io; switch to a company subdomain later) |
| Code + compose | `/opt/noahport-mdm` (git checkout of this repo), `deploy/docker-compose.prod.yml` |
| Secrets (server only) | `/opt/noahport-mdm/deploy/.env` (DB password, Mercure hub keys), `deploy/.env.production` (Laravel) |
| Real-time hub | Mercure inside the app container (`php-server --mercure`), public at `/.well-known/mercure` through the edge Caddy; keys `MERCURE_*` in `deploy/.env`, generated once by `deploy.sh` |
| Site block / guard | `/opt/caddy-sites/mdm.caddy`, `/opt/caddy-sites/mdm-guard.sh`, systemd `mdm-edge-guard.{path,timer}` |
| Network to the edge | `mdm-edge-net` (only `sms-caddy` and our app are on it) |
| Firebase key | `/opt/noahport-mdm/deploy/secrets/firebase-sa.json` (mode 600; mounted at `/run/secrets/mdm/`), enabled by `FCM_PROJECT_ID` + `FCM_CREDENTIALS_PATH` in `.env.production` |
| Backups | `/opt/noahport-mdm/backups`, nightly 02:30, 14 days (`deploy/backup.sh`) |
| Memory limits | app 512 MB, horizon 384 MB, scheduler 192 MB, postgres 512 MB, redis 128 MB |

SSH: `ssh -i ~/.ssh/wifios_vps deploy@94.72.102.13` (root login is disabled).

## First time
```bash
sudo mkdir -p /opt/noahport-mdm && sudo chown deploy:deploy /opt/noahport-mdm
git clone https://github.com/ManLikeGanga19-tech/mdm.git /opt/noahport-mdm
/opt/noahport-mdm/deploy/setup-server.sh
/opt/noahport-mdm/deploy/deploy.sh
cd /opt/noahport-mdm && docker compose -f deploy/docker-compose.prod.yml exec app php artisan db:seed --force
docker compose -f deploy/docker-compose.prod.yml exec app php artisan mdm:create-user <email> "<name>" --role=super_admin
```

## Every update
```bash
/opt/noahport-mdm/deploy/deploy.sh
```
It pulls `main`, builds the image, restarts app/horizon/scheduler, runs migrations and caches. If a pull changes
`deploy.sh` itself, bash may run a mix of old and new lines: run it a second time (harmless).

Check after a deploy: `curl -s -o /dev/null -w '%{http_code}' https://mdm.94-72-102-13.sslip.io/up` → 200, and the
hub without a token → 401 (`.../.well-known/mercure?match=x`).

## Removing test phones
`php artisan mdm:remove-devices <asset tag>...` lists each phone and everything recorded about it, and deletes only
with `--force` (audited). Accounts, roles, crews, roster, clerks and other phones are not touched.
```bash
cd /opt/noahport-mdm && docker compose -f deploy/docker-compose.prod.yml exec app php artisan mdm:remove-devices NP-TEST-02
```

## Restore a backup
```bash
gunzip -c /opt/noahport-mdm/backups/mdm-<stamp>.sql.gz | \
  docker compose -f /opt/noahport-mdm/deploy/docker-compose.prod.yml exec -T postgres psql -U mdm -d mdm
```
