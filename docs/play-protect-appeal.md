# Play Protect DPC allowlist appeal — draft

Status: **draft, not submitted** (2026-10-15). Waiting on prerequisites below.

Why: since ~Sept 2026 Google Play Protect only lets Android Enterprise–approved DPCs install during QR (6-tap)
provisioning; ours is blocked with "App blocked to protect your device" (`docs/spike-report.md`). Google's guidance is
to request allowlisting through the Play Protect appeal form. Reviewers check that the app is used strictly for
enterprise purposes, doesn't violate the Mobile Unwanted Software (MUwS) principles and isn't a Potentially Harmful
Application (PHA). Until approved, phones are enrolled over USB (`enroll/NoahportEnroll`).

## Prerequisites (in order)
1. [ ] **D-U-N-S number** for Noahport (free, dnb.com/duns/get-a-duns.html, up to 30 business days).
2. [ ] **Android Developer Console organization account** (needs the D-U-N-S number).
3. [ ] **Developer verification + app registration:** register package `com.noahport.mdm.agent` with the release
   signing certificate below. Google's DPC allowlisting page says custom DPC developers must complete Android's
   verification process.
4. [x] **Agent ready for review (0.2.0, 2026-10-15):** release builds show only the "This phone is managed by Noahport"
   notice (what is and isn't collected, what IT can do, the phone's label, an "I understand" acknowledgement) and
   no controls; the spike's test buttons exist only in debug builds.
5. [ ] Submit the form below and record the date here and in `docs/STATUS.md`.

## Where
- Form: https://support.google.com/googleplay/android-developer/contact/protectappeals
- Guidance: https://developers.google.com/android/play-protect/warning-dev-guidance
- DPC allowlisting: https://support.google.com/work/android/answer/16694822

The form's exact fields aren't published. Developers report being asked for the APK, package name, SHA-256 and a
description of the use case. Fill in whatever it asks from the table below; paste the description as is.

## Values for the form
| Field | Value |
|---|---|
| App name | Noahport MDM Agent |
| Package name | `com.noahport.mdm.agent` |
| Signing certificate SHA-256 | `07:E0:6E:5A:07:BF:E4:1F:F5:1D:4A:CA:CE:52:7B:4D:8C:46:F7:72:CB:28:AF:96:68:3F:95:79:41:27:22:D4` |
| Certificate subject | `CN=Noahport MDM Agent, O=Noahport, C=KE` |
| APK file | `agent-app/app/build/outputs/apk/release/app-release.apk` (rebuild with `scripts/make-enroll-kit.sh`; attach the build you submit) |
| APK SHA-256 (0.2.0, versionCode 3) | `0439ce3d795aee185df4bb841652b1d161b279bf8fa4b94b000fc00d6f04f3f0` — changes with every build; recompute with `sha256sum` |
| Developer / organization | Noahport (legal name exactly as on the D-U-N-S record) |
| Developer Console account ID | _fill in after step 2_ |
| Contact email | _company address, not a personal one_ |
| Country | Kenya |
| Distribution | Not on Google Play. Installed only on company-owned devices: QR provisioning from our own HTTPS server, currently USB (`adb` + `dpm set-device-owner`) |
| Warning shown | "App blocked to protect your device" during QR (6-tap) device-owner provisioning on a factory-reset device |
| Devices | Company-owned Ulefone RugKing phones, Android 13–15 (tested on Android 14 and 15) |

## Description of the use case (paste)

> **Noahport MDM Agent is the in-house device policy controller (DPC) for Noahport's own company-owned phones.**
>
> Noahport is a company in Kenya that issues Ulefone RugKing Android phones to its staff for work. These phones are
> owned by the company and are set up as fully managed devices (device owner) on a factory-reset phone. The agent is
> not published on Google Play or anywhere else, is not offered to other organizations or the public, and is never
> installed on personal (BYOD) phones. We develop and operate it ourselves, together with our own management server.
>
> The agent uses the standard Android `DevicePolicyManager` APIs as device owner to:
> - enforce company policy: block or allow the Wi-Fi hotspot/tethering (`DISALLOW_CONFIG_TETHERING`), block or hide
>   apps that are not allowed on work phones (`setPackagesSuspended`, `setApplicationHidden`), and security settings
>   such as screen-lock requirements;
> - run remote actions requested by the company's IT administrators: lock, reboot, reset the screen-lock password,
>   and wipe a lost or stolen phone;
> - report device status for fleet management: model, Android version and security patch level, installed apps,
>   per-app mobile/Wi-Fi data usage including tethering (`NetworkStatsManager`), and DNS/connection metadata from
>   Android's built-in network logging (`setNetworkLoggingEnabled` / `retrieveNetworkLogs`), so IT can monitor data
>   costs and security on company phones.
>
> What it does **not** do: it does not read SMS, call logs, contacts, photos or other media, message or e-mail
> content, keystrokes or screen content. It does not request SMS, accessibility or notification-listener
> permissions. It does not track location. Its only permissions are `INTERNET` (to reach our management server) and
> `QUERY_ALL_PACKAGES` (to label data usage and network events with app names), plus the standard device-admin
> receiver.
>
> Transparency: the phone is enrolled only by Noahport IT with physical access to a factory-reset device. Android
> shows the "This device is managed by your organization" and network-monitoring notices. The agent's only screen is
> a notice titled "This phone is managed by Noahport" that lists what is and is not collected and what IT can do,
> which the staff member acknowledges; the app offers no other controls on the phone. Staff receive and sign Noahport's device-use policy, which explains the monitoring, in
> line with the Kenya Data Protection Act 2019. Network-log data is kept for a limited period (90 days by default) and
> is visible only to Noahport's IT administrators.
>
> Why a custom DPC: we manage only our own fleet. Google's Android Management API is intended for EMM solution
> providers and its permissible-usage policy excludes solutions used only in-house, so we built our own DPC on the
> public `DevicePolicyManager` APIs, as Android Enterprise allows for custom DPCs.
>
> Since about September 2026, Play Protect blocks the agent during QR provisioning ("App blocked to protect your
> device"), which stops us from enrolling our phones the standard way. Installing it with `adb` and
> `dpm set-device-owner` works but does not scale. We ask that the agent (package `com.noahport.mdm.agent`, signing
> certificate SHA-256 `07:E0:6E:5A:07:BF:E4:1F:F5:1D:4A:CA:CE:52:7B:4D:8C:46:F7:72:CB:28:AF:96:68:3F:95:79:41:27:22:D4`)
> be allowlisted as an approved DPC for provisioning. We are happy to provide the source code, a test build, a
> demonstration, or any further information.

## Notes
- Check every claim above is still true when submitting (permissions: `agent-app/app/src/main/AndroidManifest.xml`;
  collected data: `docs/ARCHITECTURE.md` §1). If the agent gains location or other sensitive features, update this.
- Reports say Google may not send a decision e-mail. Test periodically: factory reset a test phone and provision by QR
  with a current release build (`agent-app/scripts/provisioning-payload.sh`, checksum
  `B-BuWge_5B_1HUrKzlJ7TYxG93LLKK-WaD-VeUEnItQ`).
- Developers report waits of weeks to months with no ETA from Google. Keep USB enrollment as the working path.
