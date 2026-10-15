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
| Firebase Cloud Messaging | Google (free) | High-priority data message that tells the agent to check in now |
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
set-device-owner`, then hand the agent its enrollment token and label (asset tag, assigned-to) with `am broadcast` to
`EnrollmentTokenReceiver`, which is protected by `android.permission.DUMP` (held by adb shell, not by third-party apps).
Tool: `enroll/NoahportEnroll` (C#, .NET Framework 4.8, so it runs on any Windows 10/11 with nothing installed and no
admin), a single `NoahportEnroll-<version>.exe` with the signed agent embedded, built by
`agent-app/scripts/make-enroll-kit.sh` and shared via OneDrive until the backend can serve it. The flow then continues
at `POST /api/device/v1/enroll` exactly as above.

**Control:** Admin changes policy or issues a command → backend stores it → `PushService` sends an FCM data message →
agent calls `POST /api/device/v1/checkin` → receives current policy version and pending commands → applies them →
reports results. A WorkManager periodic check-in (about 15 min) is the fallback when FCM is delayed.

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
- `mdm_alert_rules`: id, name, metric, threshold, window, recipients, enabled
- `mdm_alerts`: id, rule_id, device_id, value, triggered_at, notified_at, acknowledged_at
- `mdm_audit_log`: id, actor_id, action, subject, data (jsonb), created_at

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
| * | `/admin/*` | Filament login + role | Dashboard, devices, groups, policies, commands, alerts, releases |

Ingest endpoints are idempotent on a client-generated `batch_uuid`: validate, store raw, dispatch job, return 2xx.

## 8. Security
- TLS everywhere (reverse proxy with Let's Encrypt). Device API rate-limited.
- Enrollment tokens and device credentials: random 32+ bytes, stored hashed, expiring/revocable.
- Agent APK signed with a release keystore kept **outside git** and backed up offline. Losing it means re-provisioning every phone.
- FCM messages carry no data except "check in"; all commands are fetched over authenticated HTTPS.
- Role-based admin access; every command and policy change written to `mdm_audit_log`.
- Secrets (Firebase service-account JSON, DB/SMTP passwords, keystore) in `.env`/server paths, never in git.
- Kenya Data Protection Act 2019: signed staff device-use policy before rollout, staff notice of what is collected,
  retention limits on network logs, check ODPC registration obligations.

## 9. Phases and acceptance criteria

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

## 10. Directory layout
```
backend/                 Laravel app
  app/Mdm/
    Services/PushService.php
    Services/PolicyBuilder.php
    Services/EnrollmentService.php
    Http/Controllers/Device/ (Enroll, Checkin, CommandResult, Inventory, Usage, NetworkLogs)
    Jobs/
    Models/
  app/Filament/          admin UI
  database/migrations/   mdm_* tables
agent-app/               Android Studio project (Kotlin DPC)
deploy/                  Docker, reverse proxy, server notes
docs/ARCHITECTURE.md
docker-compose.yml
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
```

## 12. Open questions
- Domain name for the VPS (needed for TLS and the provisioning QR).
- Exact RugKing model(s) and Android version (sets the agent's `minSdk`).
- Agent package name (for example `com.<company>.mdm.agent`; cannot change after rollout).
- Admin roles needed (for example super admin, IT staff, read-only manager).
