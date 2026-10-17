# Architecture — Company Phone MDM

## 1. Goals
- Enroll company-owned Ulefone RugKing phones as **fully managed** (device owner) devices.
- Enforce policies: hotspot control (block, or allow and monitor, set from the admin UI), app allow/block, security restrictions.
- Monitor: device status, installed apps, per-app data usage, hotspot (tethering) usage, network activity (DNS/connect logs).
- Remote actions: lock, reboot, reset password, wipe.
- Admin dashboard with reports and email alerts (for example "device used > X GB hotspot this week").
- Every device is identified, monitored and controlled **individually**: a fleet list with search/filters, and a
  per-device page showing only that device's status, usage, network logs, commands and history. Policies and commands
  target one device or a group, never the whole fleet implicitly.
- Enrollment must work from **any company laptop** (Windows, no admin rights, no Android Studio): only Platform-Tools
  plus files downloaded from our server. Nothing may depend on the dev PC.

Non-goals: reading message content, personal-app spying, managing personal (BYOD) phones.

### Why not Google's Android Management API
AMAPI's permissible-usage policy prohibits "solutions developed and used exclusively for first party in-house
applications" (developers.google.com/android/management/permissible-usage). This system is in-house only, so it
uses its **own Device Policy Controller (DPC)** app and Android's `DevicePolicyManager` APIs directly. No Google
approval or quota is involved. Google is used only for Firebase Cloud Messaging (wake-up pushes).

## 2. Components

| Component | Owner | Responsibility |
|---|---|---|
| MDM Agent (`agent-app/`) | Us, Kotlin | Device owner DPC: applies policy, runs commands, collects status, app inventory, usage, network logs; uploads to backend |
| Backend (`backend/`) | Us, Laravel | Device API, enrollment, policies, command queue, jobs, DB, admin UI (Filament), email alerts |
| Real-time hub | Us (Mercure, built into FrankenPHP) | Server-sent-events channel each phone keeps open; the fastest "check in now" |
| Firebase Cloud Messaging | Google (free) | High-priority data message that tells the agent to check in now (second channel; reaches phones in Doze) |
| PostgreSQL | Us | Devices, policies, commands, telemetry, alerts |
| Redis | Us | Queues (Horizon), cache, rate limiting |
| Zoho Mail (SMTP) | Zoho | Alert and admin emails |
| Contabo VPS | Us | Runs everything in Docker Compose behind a TLS reverse proxy |

## 3. Data flows

**Enrollment:** Admin creates an enrollment token in the UI → backend renders a provisioning QR code → phone is
factory reset, tapped 6× on the welcome screen, scans QR → Android downloads the agent APK from our server, verifies
its signing-certificate checksum, makes it device owner → agent calls `POST /api/device/v1/enroll` with the token from
the admin-extras bundle → backend returns a per-device credential.

**USB enrollment (current path, 2026-10-15):** Play Protect blocks non-allowlisted DPCs during QR provisioning, so until
the agent is allowlisted, phones are enrolled from a company laptop over USB: install the release APK, `dpm
set-device-owner`, put it on the battery allowlist, then hand the agent its enrollment code and asset tag with `am broadcast` to
`EnrollmentTokenReceiver`, which is protected by `android.permission.DUMP` (held by adb shell, not by third-party apps).
Tool: `enroll/NoahportEnroll` (C#, .NET Framework 4.8, so it runs on any Windows 10/11 with nothing installed and no
admin), a single `NoahportEnroll-<version>.exe` with the signed agent embedded, built by
`agent-app/scripts/make-enroll-kit.sh` and shared via OneDrive until the backend can serve it. The flow then continues
at `POST /api/device/v1/enroll` exactly as above.

**Control (real time, since 2026-10-16):** Admin changes policy, issues a command or records a handover → backend
stores it → `PushService` queues one wake-up (Redis `BLPOP`, so the job starts at once) and sends it on two channels
with the same wake id: a private update on the phone's topic in the **Mercure hub** (FrankenPHP `--mercure`,
`/.well-known/mercure`; each phone's `AgentService` holds a server-sent-events connection to it via
`RealtimeChannel`) and an **FCM** high-priority data message. The agent acts on whichever arrives first
(`Agent.wake` dedupes the id) and runs the check-in **immediately on its own thread** (`Checkin.runSoon`, partial
wake lock), not through WorkManager: `POST /api/device/v1/checkin` → current policy + pending commands → applied →
results reported. Measured: action → phone in about a second. WorkManager keeps the ~15-minute periodic check-in and
retries failed ones. Check-in hands the phone its hub URL, topic and a 30-day subscribe-only token. Phones are on
Android's battery allowlist (set over adb by the enrollment tool) because Doze cuts device-owner apps off the network
too (SDK source: "DPC apps themselves are not automatically exempted from Doze mode"). The phone page shows how long
the last action took to reach the phone.

**Live view:** for up to 10 minutes the phone posts a sample every 2 s (`POST /api/device/v1/live`): speed, network,
signal and the apps using the most data (NetworkStatsManager deltas; Android refreshes per-app counters on its own
schedule, about every 10 s), plus its event lines (`LiveFeed`: agent log lines, charger, battery, network, real-time
channel). The server adds admin actions and wake-up timings. Shown as a chart and a terminal-style **Live console**;
kept in Redis only (15 min). Website names are not live: Android hands network logs over in batches (~1,200 events
or ~90 minutes).

**Telemetry:** Agent batches status, app inventory, usage samples, and network logs → `POST /api/device/v1/*` →
stored raw, processed by queued jobs → tables.

**Reporting:** Admin UI reads DB → tables, ECharts charts, alert rules → Zoho Mail.

## 4. Provisioning QR (fully managed)
QR payload is JSON with at least (verify names against developer.android.com `DevicePolicyManager` constants):
- `android.app.extra.PROVISIONING_DEVICE_ADMIN_COMPONENT_NAME` — agent's `DeviceAdminReceiver`
- `android.app.extra.PROVISIONING_DEVICE_ADMIN_PACKAGE_DOWNLOAD_LOCATION` — HTTPS URL of the agent APK on our server
- `android.app.extra.PROVISIONING_DEVICE_ADMIN_SIGNATURE_CHECKSUM` — URL-safe base64 SHA-256 of the signing cert
- `android.app.extra.PROVISIONING_ADMIN_EXTRAS_BUNDLE` — `{ "api_base_url": ..., "enrollment_token": ... }`
- Optional Wi-Fi extras for first-boot network.

Android 12+ requires the agent to handle `ACTION_GET_PROVISIONING_MODE` and `ACTION_ADMIN_POLICY_COMPLIANCE`.

## 5. Policy model
Policies are our own JSON schema, versioned, assigned to a **device group**, with optional **per-device overrides**
(for example hotspot on for one phone). The backend resolves group + overrides into one effective policy
(`PolicyBuilder`); the agent applies it (`PolicyEnforcer`) and reports the applied version.

Starting policy fields → Android mechanism (verify each during Phase 1 spike):

| Policy field | Android mechanism |
|---|---|
| `hotspot`: `blocked` / `allowed` | `UserManager.DISALLOW_CONFIG_TETHERING` user restriction |
| `factory_reset_disabled` | `DISALLOW_FACTORY_RESET` |
| `add_user_disabled` | `DISALLOW_ADD_USER` |
| `safe_boot_disabled` | `DISALLOW_SAFE_BOOT` |
| `unknown_sources_disabled` | `DISALLOW_INSTALL_UNKNOWN_SOURCES_GLOBALLY` |
| `blocked_apps[]` | `setApplicationHidden` / `setPackagesSuspended` |
| `network_logging` | `setNetworkLoggingEnabled` + `onNetworkLogsAvailable` |
| `password` requirements | `setRequiredPasswordComplexity` |
| agent protection | `setUninstallBlocked`, device owner status |

Usage collection uses `NetworkStatsManager`. A device owner is expected to get device-wide stats access
(all UIDs, including `UID_TETHERING`) without the usage-access permission. **Confirm on a RugKing in the Phase 1 spike.**

## 6. Data model

- `mdm_device_groups`: id, name, policy_id
- `mdm_policies`: id, name, payload (jsonb), version
- `mdm_devices`: id, uuid, serial, imei, model, manufacturer, android_version, security_patch, agent_version_code, group_id, policy_overrides (jsonb), applied_policy_version, fcm_token, assigned_user_id, state, enrolled_at, last_checkin_at
- `mdm_enrollment_tokens`: id, token_hash, group_id, max_uses, uses, expires_at, created_by
- `mdm_device_credentials`: id, device_id, token_hash, created_at, revoked_at
- `mdm_commands`: id, uuid, device_id, type, payload (jsonb), status (`queued`, `delivered`, `succeeded`, `failed`, `expired`), requested_by, created_at, delivered_at, completed_at, result (jsonb)
- `mdm_ingest_batches`: id, device_id, batch_uuid (unique), kind, raw (jsonb), received_at, processed_at, error
- `mdm_status_reports`: id, device_id, payload (jsonb), reported_at
- `mdm_app_inventory`: device_id, package_name, version_name, version_code, enabled, last_seen_at (unique device+package)
- `mdm_usage_samples`: id, device_id, package_name (NOT NULL; sentinels `__tethering__`, `__removed__`, `__uid_<n>__` for shared/unknown UIDs), network_type (`mobile`, `wifi`), rx_bytes, tx_bytes, period_start, period_end; unique (device_id, package_name, network_type, period_start)
- `mdm_network_logs`: id, device_id, log_type (`dns`, `connect`), host, ip, port, package_name, occurred_at
- `mdm_agent_releases`: id, version_code, version_name, apk_path, sha256, cert_checksum, is_current, created_at
- `mdm_alert_rules`: id, name, metric (`App\Mdm\AlertMetric`), threshold, window (day/week), group_id, recipient_role_ids, recipient_emails, enabled
- `mdm_alerts`: id, rule_id, device_id (null for crew handovers), key (dedupe), value, message, triggered_at, notified_at, resolved_at, acknowledged_at, acknowledged_by
- `mdm_audit_log`: id, actor_id, action, subject_type, subject_id, data (jsonb), created_at
- `mdm_clerks`: id, staff_number (unique), name, crew_id, active — imported from the company staff spreadsheet
- `mdm_device_assignments`: id, device_id, clerk_id, started_at, ended_at (null = holding it now), assigned_by, ended_by, note.
  The RugKings are shared per shift: the incoming coordinator gives each phone to a clerk of their crew, so usage and
  network activity are attributed to the clerk who held the phone at that time (`HandoverService::holderAt`). Partial
  unique indexes: one open assignment per device and per clerk.
- `mdm_crews`: id, name, active. Three crews rotate weekly through the shifts (mornings → afternoons → nights); each
  has two coordinators (`users.crew_id`) and its clerks (`mdm_clerks.crew_id`).
- `mdm_roster`: date (the day the shift starts, EAT), shift_id, crew_id; unique (date, shift). Generated by
  `RosterService` (week w: shift i is worked by the crew that worked shift i−w in the first week), editable per day.
- `mdm_crew_handovers`: shift_id + date (the shift being opened; unique), from_crew_id, to_crew_id, handed_by/at,
  accepted_by/at, status (`pending`, `accepted`, `disputed`), note; `mdm_crew_handover_items`: device_id, note,
  received, receive_note. `CrewHandoverService`: the outgoing coordinator hands the shift phones over (from 30 min
  before the shift), the incoming one accepts phone by phone; received phones leave the handover lock at once
  (`HandoverEnforcement`), missing ones mark it disputed.
- Roles: `mdm_roles` (key for the built-in `super_admin`, `manager`, `coordinator`), `mdm_role_permissions`
  (permission values from the `App\Mdm\Permission` enum), `users.role_id`, `disabled_at`, `last_login_at`,
  `invited_at`. Super admin always has every permission and can't be edited; the last active super admin can't be
  disabled, demoted or deleted (`AccountService`).

`mdm_devices.asset_tag` is the phone's physical label (e.g. NP-0042); devices can be pre-registered by asset tag before
enrollment. `assigned_user_id` was dropped in favour of `mdm_device_assignments`.

All timestamps UTC. Raw/high-volume tables (`ingest_batches`, `network_logs`, `status_reports`) get retention jobs (default 90 days).

## 7. Endpoints

| Method | Path | Auth | Purpose |
|---|---|---|---|
| GET | `/agent/apk/{version}` | Public (checksum-verified by Android) | APK download for provisioning and self-update |
| POST | `/api/device/v1/enroll` | Enrollment token | Register device, return device credential |
| POST | `/api/device/v1/checkin` | Bearer device credential | Status + FCM token in; effective policy + pending commands out |
| POST | `/api/device/v1/commands/{uuid}/result` | Bearer device credential | Command outcome |
| POST | `/api/device/v1/inventory` | Bearer device credential | Installed apps |
| POST | `/api/device/v1/usage` | Bearer device credential | Batched usage samples |
| POST | `/api/device/v1/network-logs` | Bearer device credential | Batched network logs |
| POST | `/api/device/v1/live` | Bearer device credential | Live view samples + event lines; answers whether to continue |
| POST | `/api/device/v1/enrollment-code/check` | None (rate-limited) | Enrollment tool checks a code without using it up |
| GET | `/.well-known/mercure?match=<topic>` | Mercure subscriber JWT (one topic) | Real-time channel (server-sent events) |
| * | `/admin/*` | Filament login + role permissions | Dashboard, phones, My shift, crews, roster, clerks, groups, reports, accounts, roles |

Ingest endpoints are idempotent on a client-generated `batch_uuid`: validate, store raw, dispatch job, return 2xx.

## 8. Security
- TLS everywhere (reverse proxy with Let's Encrypt). Device API rate-limited.
- Enrollment tokens and device credentials: random 32+ bytes, stored hashed, expiring/revocable.
- Agent APK signed with a release keystore kept **outside git** and backed up offline. Losing it means re-provisioning every phone.
- Wake-ups (FCM and the real-time hub) carry no data except "check in" and an id; all commands are fetched over
  authenticated HTTPS. Hub updates are private: a phone's token may subscribe to its own topic only; the server's
  publisher token lives 5 minutes. Both HS256 keys are generated on the server (`deploy/.env`).
- Role-based admin access with editable permissions (Roles & permissions); blocked pages show a "not allowed" page.
  Every command, policy, role, account, roster and handover change is written to `mdm_audit_log`.
- Fleet phones only ever get the **release** agent (not debuggable; the kit builder refuses a debuggable APK). The
  debug build (test controls, `run-as`) is for the emulator and the test bench only.
- Secrets (Firebase service-account JSON, DB/SMTP passwords, keystore) in `.env`/server paths, never in git.
- Kenya Data Protection Act 2019: signed staff device-use policy before rollout, staff notice of what is collected,
  retention limits on network logs, check ODPC registration obligations.

## 9. Phases and acceptance criteria

**Where we are (2026-10-16, v1.0.1):** Phases 1–5 are delivered in practice (USB enrollment instead of QR, see §3;
alert emails wait for Zoho SMTP details). Phase 6 is under way: deployed on the Contabo VPS with backups and
retention; first RugKing in the field; rollout to the remaining phones from 2026-10-17. Still open: agent self-update
(phones are updated by plugging them into the enrollment tool), Zoho alert emails, signed staff device-use policy.
The phase list below is the original plan.

**Phase 0 — Decisions** ✅
Stack, hosting, alerting, AMAPI replaced by own DPC.

**Phase 1 — Device spike (de-risk first)**
Minimal Kotlin DPC provisioned by QR on one RugKing. Confirm: device owner provisioning works, hotspot restriction
blocks and unblocks tethering, `NetworkStatsManager` returns all-UID and tethering stats, network logging delivers
batches, lock/reboot work, behaviour under Ulefone battery management.
*Done when:* written spike report with pass/fail per item.

**Phase 2 — Backend foundation**
Laravel + Docker Compose (app, worker, scheduler, Postgres, Redis), Filament admin with roles, migrations, enrollment
tokens + QR, device enroll/checkin API, policy builder, command queue, FCM `PushService`, tests.
*Done when:* tests pass and a simulated device (HTTP client) can enroll, check in, and receive policy + commands.

**Phase 3 — Agent MVP**
Agent: provisioning flow, enroll, check-in (FCM + WorkManager), policy enforcement, commands (lock, reboot, reset
password, wipe), result reporting.
*Done when:* a RugKing enrolled via QR; admin toggles hotspot and locks the phone from the UI.

**Phase 4 — Telemetry**
Status reports, app inventory, per-app + tethering usage, network logs, batching and retry.
*Done when:* 3 days of reliable data from the test phone.

**Phase 5 — Dashboard & alerts**
Device list/detail, usage charts, inventory, network logs, alert rules, Zoho Mail notifications.
*Done when:* admin sees usage charts and receives an alert email.

**Phase 6 — Production & rollout**
Contabo deployment, TLS, backups (pg_dump offsite), monitoring, retention jobs, agent self-update, docs, signed staff
policy, enroll remaining phones.

**Future — Location and posts (after the full pilot; deferred by the user 2026-10-17)**
Proposed design, not approved or built. Track phones by **zone and post**, not raw dots: GPS in the port drifts 10–40 m
near container stacks and cranes, so the question answered is "is this phone at its post for this shift?".
- *Map:* self-hosted MapLibre GL JS with one PMTiles file (OpenStreetMap extract of Mombasa/Kilindini) served from the
  VPS: no tile server, API key or per-view fees. Our layers on top: port boundary and zones (berths, yards, gates,
  sheds) drawn in an admin zone editor; optionally georeferenced KPA/Noahport site plans or a drone orthophoto.
- *Posts:* clerk → post → shift, hung off the roster/crews. Compliance = share of the shift inside the post zone, with
  a tolerance buffer (~25 m, set by the field test).
- *Agent signals (cheap first):* on-device geofences for the phone's own post + port boundary (enter/exit events);
  GPS breadcrumbs only during the clerk's rostered shift (~2 min moving, ~10 min still); connected Wi-Fi BSSID as a
  zone hint where port Wi-Fi exists; optional Wi-Fi fingerprinting (scan limits: 4 per 2 min foreground, 1 per 30
  min for all background apps); on-demand live tracking via the live view. Device owners on fully managed devices can
  grant themselves fine/background location on Android 12+ (no sensor opt-out in our USB provisioning).
- *Network-side option:* if port Wi-Fi is Cisco (WLC / Cisco Spaces), locate clients from the controller with no
  battery cost; needs the factory MAC on the company SSID (device-owner control of MAC randomization: unverified).
- *Server:* PostGIS (`postgis/postgis:16` image swap), tables `mdm_zones`, `mdm_posts`, `mdm_location_samples`,
  `mdm_zone_events`; existing batched ingest; live map over Mercure. Alerts: away from post > N min, left the port.
- *Privacy (Kenya DPA):* location only during the rostered shift, 30-day retention, staff notice + signed policy.
- *Phases:* L0 one-day field test at real posts (accuracy, BSSIDs, battery) → L1 map, zone editor, posts →
  L2 agent collection + live map → L3 compliance report + alerts.
- *Open questions:* port Wi-Fi owner/vendor and AP list; list of posts and who assigns them; site plans/GIS files.

## 10. Directory layout
```
backend/                 Laravel app
  app/Mdm/
    Permission.php, Role.php           permissions enum, built-in roles
    Services/PushService.php           the only place that wakes phones (Mercure + FCM)
    Services/PolicyBuilder.php         the only place policy is resolved
    Services/EnrollmentService.php, HandoverService.php, HandoverEnforcement.php,
             CrewHandoverService.php, RosterService.php, AccountService.php, LiveView.php, UsageReport.php, ...
    Http/Controllers/Device/           Enroll, EnrollmentCode, Checkin, CommandResult, Ingest, Live
    Jobs/, Models/
  app/Filament/          admin UI (pages, resources, widgets), Auth/ (password reset request)
  resources/views/errors/403.blade.php  "not allowed" page
  database/migrations/   mdm_* tables
  tests/Feature/         feature tests (FCM and the hub are always faked)
agent-app/               Kotlin DPC (Android Studio project); scripts/make-enroll-kit.sh builds the kit
enroll/                  NoahportEnroll (C#) USB enrollment tool + README.txt for laptop users
deploy/                  production compose, Dockerfiles, Caddy site block, deploy/backup scripts
docs/                    ARCHITECTURE.md (this), STATUS.md (current state), spike report, test/demo guides
docker-compose.yml       local development stack
CLAUDE.md
```

## 11. Environment variables (backend)
```
APP_URL=https://<domain>
DB_CONNECTION=pgsql
REDIS_HOST=redis
FCM_PROJECT_ID=
FCM_CREDENTIALS_PATH=/secure/path/firebase-sa.json
MAIL_MAILER=smtp
MAIL_HOST=smtp.zoho.com        # region-specific, e.g. smtp.zoho.eu
MAIL_PORT=465
MAIL_USERNAME=
MAIL_PASSWORD=                 # Zoho app-specific password
AGENT_CERT_CHECKSUM=           # SHA-256 of agent signing cert, URL-safe base64
APP_DISPLAY_TIMEZONE=Africa/Nairobi
```
Real-time hub (process environment for FrankenPHP and Laravel, from `deploy/.env`, generated by `deploy.sh`):
```
MERCURE_PUBLISHER_JWT_KEY=     # HS256 key, 64 hex chars
MERCURE_SUBSCRIBER_JWT_KEY=    # HS256 key, 64 hex chars
MERCURE_RESOURCE_IDENTIFIER=https://<domain>/.well-known/mercure   # absolute URL; also the tokens' audience
MERCURE_TRUSTED_ISSUERS=noahport-mdm
MERCURE_INTERNAL_URL=http://app/.well-known/mercure                # where the server publishes
```

## 12. Open questions
- Company subdomain to replace `mdm.94-72-102-13.sslip.io` (no DNS access yet).
- Play Protect DPC allowlist (needs a D-U-N-S number) to switch from USB to QR enrollment.

Resolved: Fleet is Android 13–15 (`minSdk 33`); package `com.noahport.mdm.agent` (permanent); roles are editable
(super admin, manager, shift coordinator + custom).
