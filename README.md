# Noahport MDM

In-house Mobile Device Management for Noahport's company-owned **Ulefone RugKing** phones (Android 13–15), shared by
clerks across three shifts. Our own Device Policy Controller app (the **agent**) runs as device owner on each phone; a
Laravel admin site controls and monitors them. It does not use Google's Android Management API, whose usage policy
forbids in-house-only solutions.

**Production:** https://mdm.94-72-102-13.sslip.io/admin · **Current release:** v1.0.0 (agent + enrollment tool)

## What it does

| Area | Features |
|---|---|
| Control | Lock, restart, reset screen lock, erase; hotspot block/allow per group or phone; block apps; all reach the phone in about a second (real-time channel + Firebase) |
| Shifts | 3 crews rotating weekly on a roster, 2 coordinators each; coordinator-to-coordinator handover (hand over → accept, per phone); phones locked on a full-screen notice until handed over; each phone given to a clerk for attribution |
| Monitoring | Battery, charging, network, live status every few seconds; hourly data per app (mobile / Wi-Fi / hotspot); websites visited (from network logs, in ~90-minute batches) marked work / not work; installed apps |
| Live view | 2-second speed, signal and top apps by speed, with a terminal-style live console of phone events and admin actions |
| Reports | Dashboard, shift report per phone and clerk (data, work share, peak/off-peak, hotspot, battery), crew handover monitor |
| Access | Editable roles and permissions (super admin, manager, shift coordinator, custom), invite links, disable accounts, audit log of every action, "not allowed" page |
| Enrollment | One-file Windows tool (`NoahportEnroll-<version>.exe`) for any company laptop: no admin rights, no Android Studio; also updates enrolled phones |

## How it fits together

```
Phone (agent, device owner) ──HTTPS──▶ Laravel API ──▶ PostgreSQL / Redis (Horizon jobs)
      ▲   ▲                                │
      │   └── server-sent events ◀── Mercure hub (in FrankenPHP)   ◀── PushService (one wake-up, two channels)
      └────── Firebase Cloud Messaging ◀────────────────────────────┘
Admin site (Filament) ── browsers on PCs and phones
```
A wake-up only says "check in now"; the phone then fetches its policy and commands over authenticated HTTPS.
Details: [docs/ARCHITECTURE.md](docs/ARCHITECTURE.md).

## Repository

| Path | What |
|---|---|
| `backend/` | Laravel 13 + Filament 5 admin site and device API (PHP 8.4, FrankenPHP) |
| `agent-app/` | Kotlin agent (package `com.noahport.mdm.agent`, minSdk 33) and `scripts/make-enroll-kit.sh` |
| `enroll/` | NoahportEnroll (C#, .NET Framework 4.8) and [README.txt](enroll/README.txt) for laptop users |
| `deploy/` | Production compose, Dockerfiles, Caddy site block, deploy and backup scripts ([deploy/README.md](deploy/README.md)) |
| `docs/` | [ARCHITECTURE.md](docs/ARCHITECTURE.md), [STATUS.md](docs/STATUS.md) (current state and next steps), spike report, test guides |

## Development

Everything server-side runs in Docker (no local PHP). From the repo root:

```bash
docker compose up -d                                              # admin site: http://localhost:8080/admin
docker compose run --rm --no-deps app composer install            # first time
docker compose exec app php artisan migrate --seed
docker compose exec app php artisan mdm:create-user you@example.com "Your Name" --role=super_admin
docker compose exec app php artisan test                          # feature tests (Postgres db mdm_test; FCM and the hub are faked)
docker compose exec app vendor/bin/pint app tests database config # code style
```

Agent (Git Bash, JDK from Android Studio):
```bash
cd agent-app
JAVA_HOME="/c/Program Files/Android/Android Studio/jbr" ./gradlew assembleDebug lintDebug   # debug: emulator / test bench only
JAVA_HOME="/c/Program Files/Android/Android Studio/jbr" scripts/make-enroll-kit.sh         # release agent + NoahportEnroll.exe
```
Fleet phones only ever get the **release** agent, signed with the permanent key (kept outside the repo; see
docs/STATUS.md). The kit builder refuses unsigned or debuggable builds.

## Deploying

On the VPS: `/opt/noahport-mdm/deploy/deploy.sh` (pull, build, restart, migrate). See [deploy/README.md](deploy/README.md).

## Releases

Tagged `vX.Y.Z` on `main`; the GitHub release carries `NoahportEnroll-X.Y.Z.exe`, the release APK and their SHA-256.
Agent `versionName` = tag. Enrolled phones are updated by plugging them into the new enrollment tool.

## Rules that matter

- Never commit secrets: `.env*`, Firebase service account, keystores, device credentials.
- The agent package name and signing key are permanent: changing either means re-enrolling every phone.
- All timestamps are UTC; the admin site shows East Africa Time.
- Kenya Data Protection Act: staff notice on every phone, retention limits on network logs, signed staff device-use policy.
