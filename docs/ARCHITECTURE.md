# Architecture — Company Phone MDM

## 1. Goals
- Enroll company-owned Ulefone RugKing phones as **fully managed** devices.
- Enforce policies (hotspot control, app allow/block, security settings).
- Monitor: device status, installed apps, per-app data usage, hotspot (tethering) usage, network activity (DNS/TCP logs).
- Remote actions: lock, reboot, reset password, wipe.
- Admin dashboard with reports and alerts (for example "device used > X GB hotspot this week").

Non-goals: reading message content, personal-app spying, managing personal (BYOD) phones.

## 2. Components

| Component | Owner | Responsibility |
|---|---|---|
| Android Device Policy | Google (on phone) | Applies AMAPI policies, sends status reports |
| Companion app (`companion-app/`) | Us, Kotlin | Collects per-app + tethering usage via `NetworkStatsManager`, posts to backend |
| Android Management API | Google Cloud | Enterprise, policies, devices, enrollment tokens, commands |
| Cloud Pub/Sub | Google Cloud | Pushes device events to our webhook |
| PHP backend (MDM module) | Us | AMAPI client, webhook, ingest API, jobs, DB, admin UI |
| Redis | Us | Queue for webhook/ingest processing |
| Database | Us | Devices, usage, logs, commands, alerts |

## 3. Data flows

**Control:** Admin UI → PHP `AmapiService` → AMAPI → Android Device Policy on phone.

**Status:** Phone → AMAPI → Pub/Sub topic → push to `POST /webhooks/amapi` → store raw message → queued job → DB.

**Usage:** Companion app → `POST /api/companion/v1/usage` (Bearer device token) → queued job → `usage_samples`.

**Reporting:** Admin UI reads DB → tables, charts, alerts.

## 4. AMAPI setup (one-time)
1. GCP project, enable **Android Management API** and **Cloud Pub/Sub**.
2. Service account with AMAPI access; key stored outside the repo (secrets manager or server path in `.env`).
3. Create enterprise via `signupUrls.create` → `enterprises.create` (Google's AMAPI quickstart can do this).
4. Create Pub/Sub topic; grant `android-cloud-policy@system.gserviceaccount.com` the Publisher role on it.
5. Set the topic on the enterprise (`pubsubTopicName`, `enabledNotificationTypes`: `ENROLLMENT`, `STATUS_REPORT`, `COMMAND`, `USAGE_LOGS`).
6. Create a **push subscription** to `https://<backend>/webhooks/amapi` with OIDC authentication enabled.

## 5. Base policy (starting point — verify fields against current docs)
```json
{
  "deviceConnectivityManagement": { "tetheringSettings": "DISALLOW_ALL_TETHERING" },
  "statusReportingSettings": {
    "applicationReportsEnabled": true,
    "deviceSettingsEnabled": true,
    "softwareInfoEnabled": true,
    "networkInfoEnabled": true,
    "hardwareStatusEnabled": true,
    "powerManagementEventsEnabled": true
  },
  "usageLog": { "enabledLogTypes": ["NETWORK_ACTIVITY_LOGS"] },
  "factoryResetDisabled": true,
  "addUserDisabled": true,
  "applications": [
    {
      "packageName": "com.company.mdmcompanion",
      "installType": "FORCE_INSTALLED",
      "managedConfiguration": { "api_base_url": "https://<backend>", "device_token": "<per-device>" }
    }
  ]
}
```
Hotspot may be allowed per policy for some staff; usage is still tracked by the companion app.

## 6. Data model

- `mdm_devices`: id, amapi_name (unique, `enterprises/x/devices/y`), serial, model, android_version, imei, assigned_user_id, policy_id, state, enrolled_at, last_status_at
- `mdm_policies`: id, amapi_name, name, payload (json), version
- `mdm_enrollment_tokens`: id, amapi_name, policy_id, qr_code (json), expires_at
- `mdm_device_tokens`: id, device_id, token_hash, created_at, revoked_at
- `mdm_pubsub_messages`: id, message_id (unique), notification_type, raw (json), received_at, processed_at, error
- `mdm_status_reports`: id, device_id, payload (json), reported_at
- `mdm_app_inventory`: device_id, package_name, version_name, state, last_seen_at (unique device+package)
- `mdm_usage_samples`: id, device_id, package_name (nullable; tethering rows use `__tethering__`), network_type (`mobile`, `wifi`), rx_bytes, tx_bytes, period_start, period_end; unique (device_id, package_name, network_type, period_start)
- `mdm_network_logs`: id, device_id, log_type (`dns`, `connect`), host, ip, port, package_name, occurred_at
- `mdm_commands`: id, device_id, type, amapi_operation, status, requested_by, created_at, completed_at
- `mdm_alert_rules`: id, name, metric, threshold, window, enabled
- `mdm_alerts`: id, rule_id, device_id, value, triggered_at, acknowledged_at

Raw tables (`pubsub_messages`, `network_logs`) need a retention job (for example 90 days).

## 7. Endpoints

| Method | Path | Auth | Purpose |
|---|---|---|---|
| POST | `/webhooks/amapi` | Pub/Sub OIDC JWT (verify issuer, audience, service-account email) | Device events |
| POST | `/api/companion/v1/usage` | Bearer device token (hashed lookup) | Batched usage samples |
| POST | `/api/companion/v1/heartbeat` | Bearer device token | Companion liveness |
| * | `/admin/mdm/*` | Existing admin auth + role | Dashboard, devices, policies, commands, alerts |

Webhook body: `message.data` is base64 JSON; `message.attributes.notificationType` selects the handler.

## 8. Security
- Service-account key never in git; loaded from env path.
- Pub/Sub push verified via OIDC token; reject anything else.
- Device tokens: random 32+ bytes, stored hashed, delivered via managed configuration, revocable per device.
- Rate-limit companion endpoints.
- Role-based access to admin actions; log every command with `requested_by`.
- Signed staff device-use policy before rollout (Kenya Data Protection Act 2019).

## 9. Phases and acceptance criteria

**Phase 0 — Discovery**
Inspect the existing PHP repo: framework and version, DB, auth, queue setup, frontend. Fill in CLAUDE.md commands. Choose the admin UI. Propose module structure.
*Done when:* written plan approved.

**Phase 1 — AMAPI foundation**
`AmapiService`, config, migrations for devices, policies, tokens. Commands to create policy and enrollment token (QR). Enroll one RugKing.
*Done when:* one phone enrolled and visible via `devices.list`.

**Phase 2 — Event ingestion**
Webhook with OIDC verification, idempotent storage, jobs per notification type, device and app-inventory sync, network logs.
*Done when:* status reports and app inventory update in the DB automatically.

**Phase 3 — Companion app**
Kotlin app reading `NetworkStatsManager` (per-UID and tethering buckets), reading managed config, batching uploads with WorkManager. Distributed as a private app via managed Google Play.
*Spike first:* confirm usage-access permission can be granted on a fully managed RugKing, and test Ulefone battery optimization.
*Done when:* 3 days of per-app and tethering usage arrive reliably from the test phone.

**Phase 4 — Admin dashboard**
Device list and detail, usage charts, app inventory, network logs, policy assignment, remote commands, alert rules and notifications.
*Done when:* admin can see usage and lock a device from the UI.

**Phase 5 — Rollout**
Retention jobs, monitoring, docs, signed staff policy, enroll remaining phones.

## 10. Directory layout (adapt to the framework)
```
app/Mdm/
  Services/AmapiService.php
  Http/Controllers/AmapiWebhookController.php
  Http/Controllers/CompanionIngestController.php
  Jobs/ (ProcessStatusReport, ProcessEnrollment, ProcessUsageLogs, ProcessUsageBatch)
  Models/
  Policies/PolicyBuilder.php
database/migrations/ (mdm_* tables)
companion-app/ (Android Studio project, Kotlin)
docs/ARCHITECTURE.md
CLAUDE.md
```

## 11. Environment variables
```
AMAPI_PROJECT_ID=
AMAPI_ENTERPRISE=enterprises/XXXX
AMAPI_CREDENTIALS_PATH=/secure/path/sa.json
AMAPI_PUBSUB_TOPIC=projects/<project>/topics/<topic>
AMAPI_PUSH_AUDIENCE=https://<backend>/webhooks/amapi
AMAPI_PUSH_SA_EMAIL=<push-subscription-service-account>
```

## 12. Open questions
- Exact PHP framework/version and DB (Phase 0).
- Admin UI choice (Phase 0).
- Hotspot policy: block for everyone, or allow and monitor for some roles?
- Alert channels: email, SMS, Slack?
