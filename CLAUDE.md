# CLAUDE.md — Company Phone MDM

## What this project is
An in-house Mobile Device Management (MDM) system for **company-owned Ulefone RugKing (Android) phones**.
It is a **standalone production project**: a Laravel backend plus our own Kotlin **Device Policy Controller**
("MDM Agent") provisioned as device owner. It does **not** use Google's Android Management API (its usage policy
forbids in-house-only solutions).

Full design: `docs/ARCHITECTURE.md` — read it before making structural changes.
**Current state, pending decisions, and next steps: `docs/STATUS.md` — read it at the start of every session and update it at the end.**

## Stack
- Backend: Laravel (latest stable), PHP 8.3+, in `backend/`
- Admin UI: Filament; charts with Apache ECharts
- DB: PostgreSQL 16
- Queue/cache: Redis + Laravel Horizon
- Push: Firebase Cloud Messaging (wake-up only)
- Mail: Zoho Mail SMTP
- Agent: Kotlin Android app in `agent-app/` (device owner, `DevicePolicyManager`, `NetworkStatsManager`, WorkManager)
- Runtime: Docker Compose, locally and on the Contabo VPS (no local PHP install)

## Working rules
1. **Inspect before changing.** Match the existing structure, naming, and code style.
2. **Work phase by phase** (ARCHITECTURE.md §9). Propose a plan and wait for approval before writing code for a new phase.
3. **Never commit secrets.** `.env`, Firebase service-account JSON, keystores (`*.jks`, `*.keystore`), device credentials stay out of git.
4. **Device ingest endpoints are fast and idempotent.** Validate, store raw, dispatch a job, return 2xx. Dedupe on `batch_uuid`.
5. **One place per concern:** FCM only through `PushService`; policy resolution only in `PolicyBuilder`; on the agent, policy application only in `PolicyEnforcer`.
6. **Ask before** changing the DB schema or auth once Phase 2 is merged, or the agent package name / signing key ever.
7. **Tests:** feature tests for enrollment, device auth, check-in, policy building, command lifecycle, ingest. Mock FCM; never hit real Google services in tests.
8. **Verify Android APIs** (constants, API levels, device-owner requirements) against developer.android.com before using them. Flag anything uncertain instead of guessing.
9. Keep migrations reversible. Use UTC timestamps everywhere.
10. **Git:** commit and push as the repo owner only. Never add Claude as co-author, collaborator, or attribution (no `Co-Authored-By`, no "Generated with" lines).

## Commands
Run from the repo root (Docker; no local PHP):
- Start stack: `docker compose up -d` → admin site http://localhost:8080/admin (phones on the same Wi-Fi: http://<PC LAN IP>:8080/admin)
- Install PHP deps (into the `vendor` volume): `docker compose run --rm --no-deps app composer install`
- Migrate + seed: `docker compose exec app php artisan migrate --seed`
- Create an admin-site account: `docker compose exec app php artisan mdm:create-user <email> "<name>" --role=super_admin|manager|coordinator`
- Queue worker: the `horizon` service (started by `up -d`)
- Run tests: `docker compose exec app php artisan test` (Postgres database `mdm_test`)
- Lint/format: `docker compose exec app vendor/bin/pint app tests database config` (ktlint for the agent: not set up yet)
- Agent release + enrollment tool: `cd agent-app && scripts/make-enroll-kit.sh` (Git Bash; release key required). Fleet phones get only this release build; `assembleDebug` is for the emulator/test bench
- Remove test phones (dry run unless `--force`): `php artisan mdm:remove-devices <asset tag>...`
- Production deploy: `ssh -i ~/.ssh/wifios_vps deploy@94.72.102.13 /opt/noahport-mdm/deploy/deploy.sh`
- Never run `migrate:fresh` against the dev database casually: it deletes the admin accounts.
