# Project status & handoff

Read this, `CLAUDE.md`, and `docs/ARCHITECTURE.md` before starting work. Update it at the end of every session.

_Last updated: 2026-10-15_

## Current phase
**Phase 1 — Device spike.** Plan approved 2026-10-15. Spike app passes all checks possible on an Android 15
emulator (device owner, all-app usage stats, network logging, app suspend/hide, lock, reboot). 2026-10-15: the same checks plus
hotspot block **pass on a real phone** (Safaricom Neon Smarta 2 K, Android 14). **QR provisioning is blocked by Play Protect**
(DPC allowlist, see spike report); enrollment goes by USB for now. Next: tethering check, then a RugKing. Procedure, QR steps and results: `docs/spike-report.md`. Step-by-step guide for testing on a company phone: `docs/TESTING-AT-WORK.md`.

## Decisions made
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
| Fleet | Ulefone RugKing, Android 13–15 → agent `minSdk = 33` |
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

## Admin interface (answering "which device holds it")
The admin UI is **not on any phone**. It is the Filament web app (Phase 2/5) on the Contabo VPS, opened in a browser on
any PC. Phones only run the agent. Until Phase 2 exists, the spike app's own test screen is the only interface.

## Emulators
- `Pixel_9` (user's, Android 15): left as found (spike app removed).
- `Noahport_Spike` (Android 15, Google Play image): disposable, created for agent testing. Start with
  `emulator -avd Noahport_Spike`; wipe with `-wipe-data` if `set-device-owner` complains about accounts.

## Waiting on the user
- [ ] Run the spike on a RugKing (USB enrollment, hotspot, 24 h soak)
- [ ] Back up `C:\Users\ADMIN\.noahport-signing\` offline (USB drive + password manager). Losing it means re-enrolling every phone
- [ ] Check whether Noahport has a D-U-N-S number (needed for the Android Developer Console / allowlist appeal)
- [ ] Share the enroll kit zip from OneDrive and try `enroll.cmd` on the **company laptop** (checks that its policy allows PowerShell scripts)
- [ ] Fleet size, to judge how long USB enrollment takes
- [ ] Exact RugKing model(s) for the report
- [ ] Domain name for the VPS (needed by Phase 2/6)

## Local environment (Windows PC)
- Android Studio 2025.3.4: `C:\Program Files\Android\Android Studio` (bundled JDK 21 in `jbr\`)
- Android SDK: `C:\Users\ADMIN\AppData\Local\Android\Sdk` (adb in `platform-tools\`; platforms 36, 36.1)
- Build agent: `cd agent-app && JAVA_HOME="/c/Program Files/Android/Android Studio/jbr" ./gradlew assembleDebug lintDebug` (Git Bash)
- Build the USB enrollment kit: `cd agent-app && scripts/make-enroll-kit.sh` (Git Bash; needs the release key). Writes `C:\Users\ADMIN\OneDrive\Noahport\enroll-kit\noahport-enroll-kit-<version>.zip`; share it from OneDrive
- `agent-app/local.properties` (gitignored) must escape the path: `sdk.dir=C\:\\Users\\ADMIN\\AppData\\Local\\Android\\Sdk`
- Docker installed; no local PHP/Composer/Postgres/Redis (everything server-side runs in Docker)
- Node 22 installed (used for `npx qrcode` to render provisioning QR codes)

## Session log
- 2026-10-14: Wrote the docs. Found the AMAPI permissible-usage restriction and switched the design to our own DPC. Hosting, alerts, and hotspot decisions made. Proposed the Phase 1 plan.
- 2026-10-15: Phase 1 approved. Fleet is Android 13–15; package `com.noahport.mdm.agent`. Built the spike app, QR payload script and spike-report template; `assembleDebug` + `lintDebug` pass. Device testing is next. Ran it on an Android 15 emulator: all emulator-testable checks pass; fixed a crash (missing `INTERNET` permission); added `install-device-owner.sh` and step-by-step QR test instructions. Added Share log button, a verified QR generator (`agent-app/scripts/qr`; `npx qrcode` mangles JSON on Windows) and a work kit in `OneDrive/Desktop/noahport-work-kit` (APK + QR) for testing from the company laptop.
- 2026-10-15 (evening, at home): Published the `spike-apk` pre-release (verified the hash matches the build). Test 1 (USB) on a Safaricom Neon Smarta 2 K, Android 14: device owner, hotspot block/unblock (turns off a live hotspot), Wi-Fi usage, network logging, lock, suspend/hide, reboot all pass. Mobile/tethering usage not tested (SIM had no data). Test 2 (QR): blocked by Play Protect's DPC allowlist. Chose option C: USB enrollment now, allowlist appeal in parallel. Next: one-click USB enrollment script, then the release key and appeal once the key is approved. Created the permanent release key (user approved) and wired it into `assembleRelease`. Proposed the USB enrollment tool plan. Tethering usage check passed on the test phone (bundle bought, USB tethering to the dev PC). Built the USB enrollment kit (`enroll/`, `EnrollmentTokenReceiver`, `make-enroll-kit.sh`, agent 0.1.1-spike). Tested: fresh install on the test phone with the release key (token + labels received), re-run on an enrolled phone (no change), tampered APK refused, adb auto-download with no SDK on PATH, and the post-boot "already some accounts" race on an emulator (retried, then enrolled). Not yet tested: a phone with a real account, and the company laptop itself.
