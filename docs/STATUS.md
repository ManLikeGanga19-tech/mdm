# Project status & handoff

Read this, `CLAUDE.md`, and `docs/ARCHITECTURE.md` before starting work. Update it at the end of every session.

_Last updated: 2026-10-14_

## Current phase
**Phase 1 — Device spike.** The plan below has been proposed and is waiting for approval. No code has been written yet.

## Decisions made (Phase 0)
| Topic | Decision |
|---|---|
| Project type | Standalone production project, repo root = this folder |
| Device management | Own Kotlin device-owner app ("MDM Agent", `agent-app/`). **Not** Google AMAPI (its policy forbids in-house-only use) |
| Backend | Laravel + Filament + PostgreSQL 16 + Redis/Horizon, in Docker |
| Hosting | Contabo VPS (domain still to be chosen) |
| Push | Firebase Cloud Messaging, wake-up only |
| Alerts | Email via Zoho Mail SMTP |
| Hotspot | Controlled from the admin UI per group / per device: block, or allow and monitor |
| Git | Commit and push as the repo owner only; no Claude co-author/attribution (CLAUDE.md rule 10) |
| GitHub | https://github.com/ManLikeGanga19-tech/mdm (branch `main`) |

## Phase 1 plan (pending approval)
Goal: prove the risky Android parts on one real RugKing before building the backend.
1. Minimal Kotlin project in `agent-app/` with a `DeviceAdminReceiver`.
2. Make it device owner on a freshly reset phone with `adb shell dpm set-device-owner <pkg>/.AdminReceiver` (quicker than QR for testing).
3. Test screen with a button per check, logging results:
   - Hotspot block/unblock with `DISALLOW_CONFIG_TETHERING`, including whether an active hotspot gets turned off
   - `NetworkStatsManager` returns all-UID + `UID_TETHERING` stats as device owner, without usage-access permission
   - Network logging (`setNetworkLoggingEnabled`) delivers batches
   - `lockNow`, `reboot`, app blocking (`setApplicationHidden` / `setPackagesSuspended`)
4. Real QR provisioning test, with the APK hosted temporarily as a GitHub release asset.
5. Leave the phone for 24 hours to see whether Ulefone battery management kills WorkManager jobs.
6. Write pass/fail results to `docs/spike-report.md`.

## Waiting on the user
- [ ] Approval of the Phase 1 plan
- [ ] RugKing exact model + Android version (sets `minSdk`)
- [ ] Final package name (e.g. `com.<company>.mdm.agent`); the spike may use a temporary one
- [ ] Test phone that can be factory reset, with USB debugging enabled
- [ ] Domain name for the VPS (needed by Phase 2/6)

## Local environment (Windows PC)
- Android Studio: `C:\Program Files\Android\Android Studio` (bundled JDK in `jbr\`)
- Android SDK: `C:\Users\ADMIN\AppData\Local\Android\Sdk` (adb in `platform-tools\`)
- Docker installed; no local PHP/Composer/Postgres/Redis (everything server-side runs in Docker)
- Node 22 installed

## Session log
- 2026-10-14: Wrote the docs. Found the AMAPI permissible-usage restriction and switched the design to our own DPC. Hosting, alerts, and hotspot decisions made. Proposed the Phase 1 plan.
