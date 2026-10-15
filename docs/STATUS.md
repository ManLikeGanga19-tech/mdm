# Project status & handoff

Read this, `CLAUDE.md`, and `docs/ARCHITECTURE.md` before starting work. Update it at the end of every session.

_Last updated: 2026-10-16_

## Current phase
**Phase 2, Milestone A (approved 2026-10-15)** — pilot-ready core: server + admin site, device API, agent check-in/policy/commands, enrollment-tool update, VPS deploy. Step 1 done: admin site running locally in Docker (Filament 5 / Laravel 13) with roles, phones, shift handover, clerks (CSV import), groups & rules, accounts, audit log; 16 feature tests pass. **Deployed to the VPS 2026-10-16: https://mdm.94-72-102-13.sslip.io/admin** (see `deploy/README.md`). Dashboard has KPIs + needs-attention + activity. Next: device API (enroll/check-in/command results), then the agent side, then Firebase; telemetry features requested by the user (live traffic, battery, per-app/per-site data, peak/off-peak shift report) are Milestone B and need a plan first.

Phase 1 (spike) summary: **Phase 1 — Device spike.** Plan approved 2026-10-15. Spike app passes all checks possible on an Android 15
emulator (device owner, all-app usage stats, network logging, app suspend/hide, lock, reboot). 2026-10-15: the same checks plus
hotspot block **pass on a real phone** (Safaricom Neon Smarta 2 K, Android 14). **QR provisioning is blocked by Play Protect**
(DPC allowlist, see spike report); enrollment goes by USB for now. Next: tethering check, then a RugKing. Procedure, QR steps and results: `docs/spike-report.md`. Step-by-step guide for testing on a company phone: `docs/TESTING-AT-WORK.md`.

## Decisions made
| Topic | Decision |
|---|---|
| Project type | Standalone production project, repo root = this folder |
| Device management | Own Kotlin device-owner app ("MDM Agent", `agent-app/`). **Not** Google AMAPI (its policy forbids in-house-only use) |
| Backend | Laravel + Filament + PostgreSQL 16 + Redis/Horizon, in Docker |
| Hosting | Contabo VPS, shared with other projects (this stack is small: ~1 GB RAM, little disk for 6 phones). Address until company DNS access: free `mdm.<vps-ip>.sslip.io` with Let's Encrypt |
| Push | Firebase Cloud Messaging, wake-up only |
| Alerts | Email via Zoho Mail SMTP |
| Hotspot | Controlled from the admin UI per group / per device: block, or allow and monitor |
| Admin roles | `super_admin` (controls), `manager` (read-only), `coordinator` (shift handover only) |
| Fleet use | **6 RugKings shared per shift** by clerks. Coordinators record each handover (who holds which phone) so activity is attributed to the clerk; clerks imported from the company staff spreadsheet (CSV) |
| Admin on the user's phone | The admin site in the phone's browser (installable to home screen). The user's personal phone is **never** reset, enrolled or touched over adb |
| Git | Commit and push as the repo owner only; no Claude co-author/attribution (CLAUDE.md rule 10) |
| GitHub | https://github.com/ManLikeGanga19-tech/mdm (branch `main`) |
| Fleet | 6 Ulefone RugKing, Android 13–15 → agent `minSdk = 33` |
| Package name | `com.noahport.mdm.agent` (company "Noahport"). **Permanent** once phones are enrolled (rule 6) |
| Agent toolchain | AGP 9.2.1 (matches the installed Android Studio), Gradle 9.8.1 wrapper (checksum-verified), JDK 21 from Studio's `jbr`, compileSdk/targetSdk 36 |
| Enrollment | **Option C (2026-10-15):** enroll by USB (`dpm set-device-owner`) now, and apply for Play Protect's DPC allowlist in parallel; switch to QR if approved. QR (6-tap) is blocked for non-allowlisted DPCs since ~Sept 2026 |
| Release signing key | Created 2026-10-15, **permanent** (rule 6). RSA 4096, PKCS12, alias `noahport-mdm-agent`, valid to 2056. Kept outside the repo and OneDrive at `C:\Users\ADMIN\.noahport-signing\` (`release.jks` + `release.properties` with the password); the user must keep an offline backup. Cert SHA-256 `07:E0:6E:5A:07:BF:E4:1F:F5:1D:4A:CA:CE:52:7B:4D:8C:46:F7:72:CB:28:AF:96:68:3F:95:79:41:27:22:D4`, provisioning checksum `B-BuWge_5B_1HUrKzlJ7TYxG93LLKK-WaD-VeUEnItQ`. `assembleRelease` signs with it (path override: `-Pnoahport.signing` / `NOAHPORT_SIGNING`). Release builds aren't debuggable, so `run-as` log pulls only work on debug builds |
| Provisioning | Agent keeps system apps enabled (`EXTRA_PROVISIONING_LEAVE_ALL_SYSTEM_APPS_ENABLED`), in both the QR payload and the provisioning-mode result |

## Phase 1 progress
1. ✅ Kotlin project in `agent-app/`: `AdminReceiver`, Android 12+ provisioning activities (`ProvisioningModeActivity`, `PolicyComplianceActivity`), test screen `MainActivity`, `SoakWorker`, `SpikeLog` (writes `files/spike.log`)
2. ✅ on emulator / ✅ on test phone: device owner via `agent-app/scripts/install-device-owner.sh`
3. ✅ on emulator / ✅ on test phone: the checks, including hotspot turn-off. mobile and tethering usage (USB tethering, 52 MB download counted)
4. ❌ QR provisioning: payload, download and checksum worked, but Play Protect blocks the DPC ("App blocked to protect your device"). Needs allowlisting
5. ⏳ 24 h soak. **Must be repeated on a real RugKing**: the test phone can't show Ulefone battery behaviour
6. ⏳ Fill in `docs/spike-report.md`

API facts confirmed from the SDK 36.1 sources (`Sdk/sources/android-36.1`):
- `DISALLOW_CONFIG_TETHERING` set by a device owner applies globally; Android 9+ turns off active tethering when it is set.
- `NetworkStatsManager`: device owner apps get usage for all users/UIDs, including tethering, without `PACKAGE_USAGE_STATS`; `querySummary(type, null, …)` covers all networks of that type.
- Network logging needs every user/profile affiliated (fine on a fully managed phone with one user).

## Admin interface
The Filament web app (`backend/`), opened in a browser on a PC or the admin's phone. Locally: `docker compose up -d`, then
http://localhost:8080/admin (or http://<PC LAN IP>:8080/admin from a phone on the same Wi-Fi). Account for the user:
`orwenjodaniel19@gmail.com` (super admin; password given in chat, change it after first sign-in). Phones only run the agent.

## Emulators
- `Pixel_9` (user's, Android 15): left as found (spike app removed).
- `Noahport_Spike` (Android 15, Google Play image): currently set up for the management demo (debug agent as device owner); start it WITHOUT `-wipe-data` until the demo is done. Start with
  `emulator -avd Noahport_Spike`; wipe with `-wipe-data` if `set-device-owner` complains about accounts.

## Waiting on the user
- [ ] Run the spike on a RugKing (USB enrollment, hotspot, 24 h soak)
- [ ] Back up the release key (no USB drive): `release.jks` to OneDrive **Personal Vault** + a second cloud copy, and the password separately (password manager / paper). Losing it means re-enrolling every phone
- [ ] Check whether Noahport has a D-U-N-S number (needed for the Android Developer Console / allowlist appeal)
- [ ] Share `NoahportEnroll-<version>.exe` from OneDrive and try it on the **company laptop** (SmartScreen / antivirus may block an unsigned exe)
- [ ] Apply for a free D-U-N-S number for Noahport (Noahport has none; up to 30 days). Then Android Developer Console org account, then the Play Protect appeal (draft: `docs/play-protect-appeal.md`)
- [ ] Staff spreadsheet (CSV: staff number, name) to import the clerks
- [ ] Firebase project for instant pushes (steps to be given)
- [ ] VPS SSH access + a check of what already runs there (reverse proxy, free RAM/disk)
- [ ] Asset tags of the 6 RugKings
- [ ] Exact RugKing model(s) for the report
- [ ] Company subdomain later (no DNS access now; sslip.io meanwhile)

## Local environment (Windows PC)
- Android Studio 2025.3.4: `C:\Program Files\Android\Android Studio` (bundled JDK 21 in `jbr\`)
- Android SDK: `C:\Users\ADMIN\AppData\Local\Android\Sdk` (adb in `platform-tools\`; platforms 36, 36.1)
- Build agent: `cd agent-app && JAVA_HOME="/c/Program Files/Android/Android Studio/jbr" ./gradlew assembleDebug lintDebug` (Git Bash)
- Build the USB enrollment kit: `cd agent-app && scripts/make-enroll-kit.sh` (Git Bash; needs the release key). Needs the .NET SDK (10 installed). Writes `C:\Users\ADMIN\OneDrive\Noahport\enroll-kit\NoahportEnroll-<version>.exe` + `README.txt`; share them from OneDrive
- `agent-app/local.properties` (gitignored) must escape the path: `sdk.dir=C\:\\Users\\ADMIN\\AppData\\Local\\Android\\Sdk`
- Docker installed; no local PHP/Composer/Postgres/Redis (everything server-side runs in Docker)
- Node 22 installed (used for `npx qrcode` to render provisioning QR codes)

## Session log
- 2026-10-14: Wrote the docs. Found the AMAPI permissible-usage restriction and switched the design to our own DPC. Hosting, alerts, and hotspot decisions made. Proposed the Phase 1 plan.
- 2026-10-15: Phase 1 approved. Fleet is Android 13–15; package `com.noahport.mdm.agent`. Built the spike app, QR payload script and spike-report template; `assembleDebug` + `lintDebug` pass. Device testing is next. Ran it on an Android 15 emulator: all emulator-testable checks pass; fixed a crash (missing `INTERNET` permission); added `install-device-owner.sh` and step-by-step QR test instructions. Added Share log button, a verified QR generator (`agent-app/scripts/qr`; `npx qrcode` mangles JSON on Windows) and a work kit in `OneDrive/Desktop/noahport-work-kit` (APK + QR) for testing from the company laptop.
- 2026-10-15 (evening, at home): Published the `spike-apk` pre-release (verified the hash matches the build). Test 1 (USB) on a Safaricom Neon Smarta 2 K, Android 14: device owner, hotspot block/unblock (turns off a live hotspot), Wi-Fi usage, network logging, lock, suspend/hide, reboot all pass. Mobile/tethering usage not tested (SIM had no data). Test 2 (QR): blocked by Play Protect's DPC allowlist. Chose option C: USB enrollment now, allowlist appeal in parallel. Next: one-click USB enrollment script, then the release key and appeal once the key is approved. Created the permanent release key (user approved) and wired it into `assembleRelease`. Proposed the USB enrollment tool plan. Tethering usage check passed on the test phone (bundle bought, USB tethering to the dev PC). Built the USB enrollment kit (`enroll/`, `EnrollmentTokenReceiver`, `make-enroll-kit.sh`, agent 0.1.1-spike). Tested: fresh install on the test phone with the release key (token + labels received), re-run on an enrolled phone (no change), tampered APK refused, adb auto-download with no SDK on PATH, and the post-boot "already some accounts" race on an emulator (retried, then enrolled). Not yet tested: a phone with a real account, and the company laptop itself. Replaced the PowerShell kit with a single `NoahportEnroll.exe` (C#, .NET Framework 4.8, agent embedded) at the user's request (scripts are unreliable on company laptops); tested on the test phone (already-enrolled, adb auto-download) and a fresh emulator (full enrollment incl. the post-boot retry). Noahport has no D-U-N-S number yet. Drafted the Play Protect allowlist appeal (`docs/play-protect-appeal.md`). Agent 0.2.0: release builds show only the staff notice ("This phone is managed by Noahport", what is/isn't collected, label, "I understand" acknowledgement stored in UTC) with no controls; spike buttons are debug-only (FLAG_DEBUGGABLE). Verified on an emulator enrolled with NoahportEnroll-0.2.0.exe (acknowledgement survives reboot). The test phone still runs 0.1.1-spike; 0.2.0 has no "Release device owner" button, so freeing a phone on 0.2.0 means a factory reset. Demo prep for management (2026-10-16): test phone upgraded to 0.2.0 (still device owner, shows the staff notice); emulator `Noahport_Spike` wiped and set up with the debug agent as device owner for live control demos (rehearsed: hotspot block/unblock, network logging, suspend/unsuspend); clickable admin-site prototype published (https://claude.ai/artifact/UA8LkCEucxpxwVs6bTavec, offline copy in `C:\Users\ADMIN\OneDrive\Noahport\demo`); demo script `docs/DEMO.md`.
- 2026-10-16: Milestone A approved (pilot-ready core). Decisions: coordinator role for shift handovers, sslip.io address, clerks from CSV, roles super admin / manager / coordinator, Firebase yes, Vercel not used (it can't run the Laravel stack). Scaffolded `backend/` (Laravel 13.36, Filament 5.10, Horizon 5.50; FrankenPHP 1.13.1 / PHP 8.4 image `deploy/docker/app.Dockerfile`; `docker-compose.yml` with Postgres 16.10 and Redis 7.4; vendor/ in a named volume to keep it out of OneDrive). Tables per ARCHITECTURE §6 plus clerks/assignments/roles; services `HandoverService`, `PolicyBuilder`, `CommandService`, `PushService` (stub until Firebase). Admin pages: dashboard, Phones (detail with hotspot override, lock/restart/reset/erase), Shift handover, Clerks + CSV import, Groups & rules, Accounts, Audit log; home-screen manifest. Removed the Laravel skeleton's AGENTS.md/CLAUDE.md (they say to install PHP locally, against the Docker rule). 16 feature tests pass. Gotchas: notifications.data must be json on Postgres; compose must not pass `.env` as `env_file` (it overrides phpunit's APP_ENV via $_SERVER).
- 2026-10-16 (VPS): Deployed to the shared Contabo VPS as `deploy` (root SSH disabled; key `~/.ssh/wifios_vps`). **Incident:** the first mdm-guard copied U-SAFE's guard, which reloads `sms-caddy` from `/etc/caddy/Caddyfile`; that single-file bind mount is stale (container sees the 2026-10-03 inode), so the reload dropped the WiFiOS sites for ~15 min. Restored by staging the current host Caddyfile into `/opt/caddy-sites/.state/` and reloading from `/etc/caddy/sites/.state/...`. With the user's approval, mdm-, whk- and usafe-guard all now use that staged method (originals backed up in `/opt/caddy-sites/.state/*.bak-20261015T2112Z`). Lasting fix belongs to ShuleHQ: mount the directory, not the single file, and `import /etc/caddy/sites/*.caddy`. Noticed (not changed): a Cloudflare API token in world-readable `/opt/caddy-sites/woodenhouses.caddy`. Production super admin created (password given in chat). Local tunnel stopped.
