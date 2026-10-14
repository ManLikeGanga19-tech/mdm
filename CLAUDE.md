# CLAUDE.md — Company Phone MDM

## What this project is
An in-house Mobile Device Management (MDM) system for **company-owned Ulefone RugKing (Android) phones**.
It uses Google's **Android Management API (AMAPI)** to enroll, configure, and monitor devices, and adds a
small **Kotlin companion app** to collect per-app and hotspot (tethering) data usage, which AMAPI does not report.

Everything server-side runs in the **existing company PHP backend**. There is no separate Python/Node service.

Full design: `docs/ARCHITECTURE.md` — read it before making structural changes.

## Stack
- Backend: existing PHP app (expected Laravel; confirm in Phase 0), `google/apiclient` for AMAPI
- Queue: Redis + framework queue workers (Laravel queues / Symfony Messenger)
- DB: the backend's existing database (MySQL or PostgreSQL)
- Admin UI: decided in Phase 0 (Filament if Laravel; otherwise Inertia/React + shadcn/ui)
- Charts: Apache ECharts
- Google Cloud: Android Management API, Cloud Pub/Sub (push subscription)
- Device side: Android Device Policy (Google), companion app in `companion-app/` (Kotlin)

## Working rules
1. **Inspect before changing.** Match the existing codebase's framework, structure, naming, and code style.
2. **Work phase by phase** (see ARCHITECTURE.md §9). Propose a plan and wait for approval before writing code for a new phase.
3. **Never commit secrets.** Service-account JSON, device tokens, and `.env` values stay out of git. Add paths to `.gitignore`.
4. **Webhooks must be fast and idempotent.** Verify, store the raw message, dispatch a job, return 2xx. Dedupe on Pub/Sub `messageId`.
5. **Every AMAPI call goes through one service class** (`AmapiService` or equivalent). No direct client calls from controllers.
6. **Ask before** changing existing tables, auth, or anything outside the MDM module.
7. **Tests:** write feature tests for webhook parsing, ingest auth, and policy building. Mock Google APIs; never call real AMAPI in tests.
8. **Verify AMAPI field names** against the current reference docs (developers.google.com/android/management/reference/rest) before using a policy field. Flag anything uncertain instead of guessing.
9. Keep migrations reversible. Use UTC timestamps everywhere.

## Commands
Fill these in during Phase 0 once the repo is inspected:
- Install: `TODO`
- Run dev server: `TODO`
- Run queue worker: `TODO`
- Run tests: `TODO`
- Lint/format: `TODO`
