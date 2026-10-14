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
Filled in during Phase 2 scaffolding:
- Start stack: `TODO` (planned: `docker compose up -d`)
- Install: `TODO`
- Run queue worker: `TODO` (Horizon container)
- Run tests: `TODO`
- Lint/format: `TODO` (Pint; ktlint for agent)
