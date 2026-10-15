# Phase 1 spike report

Goal: prove the risky Android parts of the agent on real hardware before building the backend (ARCHITECTURE.md §9).
Spike app: `agent-app/` (`com.noahport.mdm.agent`, minSdk 33, debug build).

## Test devices
| Device | Android | Notes |
|---|---|---|
| Android emulator (Pixel 9 image, Google Play) | 15 (SDK 35) | 2026-10-15. Checks the APIs only: no real hotspot, no OEM battery management |
| Safaricom Neon Smarta 2 K (non-RugKing test phone) | 14 (SDK 34), patch 2026-07-05 | 2026-10-15, at home. Real hardware, but **not** valid for Ulefone battery behaviour. SIM had no working mobile data |
| Ulefone RugKing (model TBD) | 13–15 | Repeat at least QR provisioning, hotspot and the 24 h soak |

## Setup (adb, quickest)
1. Factory reset the phone. In setup, **skip adding a Google account**: `set-device-owner` fails if any account exists. Enable Developer options (tap Build number 7 times) and USB debugging, then connect USB and accept the "Allow USB debugging" prompt.
2. From `agent-app/` in Git Bash: `scripts/install-device-owner.sh` (builds, installs, sets device owner, opens the app).
3. Tap **Show status**. Expect `deviceOwner=true`.

Pull the log at any time: `adb exec-out run-as com.noahport.mdm.agent cat files/spike.log > spike.log`.
Undo on a test phone: **Release device owner / admin**, then uninstall normally.

If `set-device-owner` says "already some accounts" while Settings shows none, the phone has leftover account state: factory reset again. (Seen on a previously used emulator; a fresh one worked.)

## Testing away from the dev PC (e.g. company laptop)
The APK and QR are built on the dev PC; the laptop only needs adb, with no Android Studio and no install rights.
1. On the dev PC: build, then copy `app-debug.apk` and the QR into `OneDrive\Desktop\noahport-work-kit\`
   (already done 2026-10-15), and publish the APK as the `spike-apk` GitHub release (QR step A2).
2. On the laptop: download **SDK Platform-Tools for Windows** (zip, from developer.android.com/tools/releases/platform-tools)
   and unzip it. Get `app-debug.apk` from OneDrive (onedrive.live.com) or the GitHub release.
3. In a terminal inside the unzipped `platform-tools` folder:
   `.\adb install path\to\app-debug.apk`, then `.\adb shell dpm set-device-owner com.noahport.mdm.agent/.AdminReceiver`.
   The laptop may need the phone maker's USB driver if `.\adb devices` shows nothing.
4. Send results with the app's **Share log** button (email/WhatsApp), so no adb is needed to collect them.
QR testing needs no laptop at all: show `qr.png` on any screen (or a printout) and scan it from the reset phone.

## Procedure per check
| # | Check | How | Pass when |
|---|---|---|---|
| 1 | Device owner via adb | Setup above | `deviceOwner=true` |
| 2 | Hotspot block | Turn the hotspot on, connect a laptop, tap **Block hotspot** | Hotspot turns off and the Settings toggle is greyed out; `ap`/`swlan` interface disappears from the log |
| 3 | Hotspot unblock | **Unblock hotspot** | Hotspot can be turned on again |
| 4 | Data usage, all apps | Use a few apps, tap **Query last 24 h usage** | `PASS`: many UIDs, not just ours, without granting usage access |
| 5 | Tethering usage | Download ~50 MB on the laptop over the hotspot (mobile data), query again | `tethering` grows by about that amount on `mobile` |
| 6 | Network logging | **Enable network logging**, browse, then `adb shell dpm force-network-logs` (or wait ~90 min) | `PASS network log batch` with DNS/connect events and package names |
| 7 | Lock | **Lock now** | Screen locks |
| 8 | Reboot | **Reboot** | Phone restarts; app is still device owner afterwards (no PASS line: the phone restarts before the call returns) |
| 9 | App blocking | Package `com.android.chrome`: suspend/unsuspend, hide/unhide | Suspended app greyed out; hidden app gone from launcher; both reversible |
| 10 | QR provisioning | See next section | All QR checks below pass |
| 11 | 24 h soak | **Start soak**, leave the phone unplugged and idle for 24 h, then **Soak report** | ~96 runs, longest gap well under ~2 h (Doze defers jobs into maintenance windows; repeated multi-hour gaps = OEM killing) |

## QR provisioning test
QR setup is how the real fleet gets enrolled: no USB cable, no adb. It needs a **factory-reset** phone.

### A. Prepare the QR code (on this PC)
1. Build: `cd agent-app && JAVA_HOME="/c/Program Files/Android/Android Studio/jbr" ./gradlew assembleDebug`.
   Build and generate the QR on the **same PC**: the checksum comes from this PC's debug signing key.
2. Host the APK: on GitHub open the repo → **Releases** → **Draft a new release**. Tag `spike-apk`, title "Spike APK (temporary)", tick **Set as a pre-release**, attach `agent-app/app/build/outputs/apk/debug/app-debug.apk`, then **Publish**.
   The download URL is `https://github.com/ManLikeGanga19-tech/mdm/releases/download/spike-apk/app-debug.apk`. Paste it into a browser to check it downloads.
   The repo is public, so anyone with the link can download this APK. Delete the release after the test.
3. Generate the payload, adding the Wi-Fi the phone should join during setup:
   ```bash
   cd agent-app
   WIFI_SSID="OfficeWifi" WIFI_PASSWORD="secret" \
     scripts/provisioning-payload.sh app/build/outputs/apk/debug/app-debug.apk \
     https://github.com/ManLikeGanga19-tech/mdm/releases/download/spike-apk/app-debug.apk provisioning.json
   (cd scripts/qr && npm install && node make-qr.js ../../provisioning.json ../../qr.png)
   ```
   `make-qr.js` decodes the image back and prints `OK` only if it matches the payload. Don't use `npx qrcode "$(cat ...)"`
   on Windows: the npx wrapper mangles the JSON quotes and produces a QR code of the wrong content.
   Open `qr.png` on the PC screen. `provisioning.json` and `qr.png` contain the Wi-Fi password: don't commit them, and delete them after the test.

### B. Enroll the phone
1. Factory reset (Settings → System → Reset options → Erase all data). Wait for the **welcome / "Hi there"** screen. Don't press Start.
2. Tap the **same spot on the welcome screen 6 times** quickly. A QR scanner should open (on some phones it first asks for Wi-Fi).
3. Scan `qr.png`. The phone should show "This device belongs to your organization", download the agent, and finish setup.
4. Open **Noahport MDM Agent** and tap **Show status**.

### C. Record these results (send me the screenshots and `spike.log`)
| QR check | What to look for | If it fails |
|---|---|---|
| Q1 Scanner opens | 6 taps open a QR scanner | Note the Android version; some builds need taps on a specific area, or Wi-Fi first |
| Q2 Wi-Fi from QR | Phone joins the Wi-Fi without typing it | Check SSID/password spelling; the security type must be WPA, WEP or NONE |
| Q3 APK download | No "couldn't download" error | GitHub redirects release downloads to another host. If this fails, the APK needs a direct (non-redirect) HTTPS URL |
| Q4 Checksum accepted | No "can't set up device / contact your IT admin" after the download | The checksum format is wrong: send me the exact message |
| Q5 Device owner | **Show status** says `deviceOwner=true` | |
| Q6 Provisioning callbacks | Log has `GET_PROVISIONING_MODE ...` and `ADMIN_POLICY_COMPLIANCE ... adminExtras={api_base_url=..., enrollment_token=spike-test}` | Admin extras missing means the backend can't receive enrollment tokens this way |
| Q7 System apps kept | Camera, Chrome, Phone, Messages still present | `LEAVE_ALL_SYSTEM_APPS_ENABLED` not honoured; we'd need to re-enable apps ourselves |

Take a photo of any error screen: those screens can't be screenshotted.

## Results
| # | Check | Emulator (A15) | Test phone | RugKing | Notes |
|---|---|---|---|---|---|
| 1 | Device owner via adb | PASS | **PASS** | pending | Fails with "already some accounts" on a previously used emulator; fresh one fine |
| 2 | Hotspot block (turns off active hotspot) | PASS (restriction set) | **PASS** | pending | Emulator has no real hotspot; turn-off must be checked on hardware |
| 3 | Hotspot unblock | PASS | **PASS** | pending | |
| 4 | All-UID usage without usage access | **PASS** | **PASS** (Wi-Fi + mobile) | pending | 15 UIDs on mobile and on Wi-Fi with no usage-access grant |
| 5 | `UID_TETHERING` usage | n/a | **PASS** | pending | Needs a real hotspot |
| 6 | Network logging batches | **PASS** | **PASS** | pending | 26 events (8 DNS, 18 connect) with package names, via `force-network-logs` |
| 7 | Lock | PASS | **PASS** | pending | |
| 8 | Reboot | PASS | **PASS** | pending | Still device owner after reboot |
| 9 | App suspend / hide | PASS | **PASS** | pending | Chrome suspended/unsuspended and hidden/unhidden |
| 10 | QR provisioning | not tested | **FAIL: Play Protect** | pending | "App blocked to protect your device" during QR setup; see below |
| 11 | 24 h soak | n/a | pending | pending | Only the RugKing result counts |

Other observations:
- As device owner the app's standby bucket is `5` (EXEMPTED), even though it isn't on the battery-optimization allowlist. That's a good sign for background jobs; the RugKing soak still has to confirm it.
- Test phone (Neon Smarta 2 K): Wi-Fi usage 19 UIDs / 48 MB; network logging 64 events (21 DNS, 43 connect) with package and host names; suspended Chrome opens the "Blocked by your IT admin" dialog; device owner and network logging survive reboot. Mobile/tethering first untested (SIM had no bundle: Safaricom captive portal); re-tested after a bundle with USB tethering to the dev PC: a 52.4 MB download moved the mobile `tethering` figure from 2.4 MB to 60.3 MB (the rest is VPN overhead and other PC traffic), mobile total 108 to 167 MB.
- Test phone: logcat at 15:25 UTC showed `Cannot suspend package "com.noahport.mdm.agent": has an active device admin`. Something on the phone (OEM app manager?) tried to suspend the agent; device admin status blocked it.
- **QR provisioning is blocked by Google Play Protect.** Since ~Sept 2026, Play Protect only lets DPCs on Android Enterprise's allowlist install during provisioning; others get "App blocked to protect your device" with only an OK button ([Google: DPC allowlisting](https://support.google.com/work/android/answer/16694822)). It reportedly also blocks Google's own Test DPC. Getting on the list: Android verification plus a Play Protect appeal, with no published review time. `adb shell dpm set-device-owner` still works.
- Bug found and fixed on the emulator: without the `INTERNET` permission, `NetworkInterface.getNetworkInterfaces()` returns null and crashed the app.

## Conclusions
_To be written after hardware testing._
