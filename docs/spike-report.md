# Phase 1 spike report

Goal: prove the risky Android parts of the agent on real hardware before building the backend (ARCHITECTURE.md §9).
Spike app: `agent-app/` (`com.noahport.mdm.agent`, minSdk 33, debug build).

## Test devices
| Device | Android | Notes |
|---|---|---|
| Non-RugKing test phone (model TBD) | TBD | First round. Checks the Android APIs; **not** valid for Ulefone battery behaviour |
| Ulefone RugKing (model TBD) | 13–15 | Second round: repeat at least provisioning, hotspot and the 24 h soak |

## Setup (adb, quickest)
1. Factory reset the phone. In setup, skip adding a Google account (`set-device-owner` fails if any account exists). Enable Developer options and USB debugging.
2. Build: `cd agent-app && ./gradlew assembleDebug`. APK: `agent-app/app/build/outputs/apk/debug/app-debug.apk`.
3. `adb install agent-app/app/build/outputs/apk/debug/app-debug.apk`
4. `adb shell dpm set-device-owner com.noahport.mdm.agent/.AdminReceiver`
5. Open **Noahport MDM Agent** and tap **Show status**. Expect `deviceOwner=true`.

Pull the log at any time: `adb exec-out run-as com.noahport.mdm.agent cat files/spike.log`.
Undo on a test phone: **Release device owner**, then uninstall normally.

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
| 8 | Reboot | **Reboot** | Phone restarts; app is still device owner afterwards |
| 9 | App blocking | Package `com.android.chrome`: suspend/unsuspend, hide/unhide | Suspended app greyed out; hidden app gone from launcher; both reversible |
| 10 | QR provisioning | See below | Phone ends set up with the agent as device owner; log shows `GET_PROVISIONING_MODE` and `ADMIN_POLICY_COMPLIANCE` with the admin extras |
| 11 | 24 h soak | **Start soak**, leave the phone unplugged and idle for 24 h, then **Soak report** | ~96 runs, longest gap well under ~2 h (Doze defers jobs into maintenance windows; repeated multi-hour gaps = OEM killing) |

## QR provisioning
1. Upload `app-debug.apk` as a release asset on a **temporary** GitHub release (or any HTTPS host).
2. `agent-app/scripts/provisioning-payload.sh agent-app/app/build/outputs/apk/debug/app-debug.apk <asset-url> provisioning.json`
   (set `WIFI_SSID`/`WIFI_PASSWORD` to let the phone get online on the welcome screen).
3. `npx --yes qrcode@1.5.4 -o qr.png "$(cat provisioning.json)"`, then factory reset the phone, tap the welcome screen 6 times, and scan.

Unverified points the QR test settles:
- GitHub release asset URLs redirect to another host; confirm the provisioning downloader follows the redirect. If it doesn't, host the APK at a direct URL.
- The checksum is URL-safe base64 **without** `=` padding (the common practice; the docs don't say).
- The debug APK is signed with this PC's debug keystore. Production QR codes will use the release keystore checksum.

## Results
| # | Check | Test phone | RugKing | Notes |
|---|---|---|---|---|
| 1 | Device owner via adb | pending | pending | |
| 2 | Hotspot block (turns off active hotspot) | pending | pending | |
| 3 | Hotspot unblock | pending | pending | |
| 4 | All-UID usage without usage access | pending | pending | |
| 5 | `UID_TETHERING` usage | pending | pending | |
| 6 | Network logging batches | pending | pending | |
| 7 | Lock | pending | pending | |
| 8 | Reboot | pending | pending | |
| 9 | App suspend / hide | pending | pending | |
| 10 | QR provisioning | pending | pending | |
| 11 | 24 h soak | pending | pending | Only the RugKing result counts |

## Conclusions
_To be written after testing._
