# Testing the MDM agent on a company phone

A step-by-step guide for running the Phase 1 spike on a real phone, away from the dev PC.
Everything you need comes from GitHub: this file, the app (`app-debug.apk`) and the QR code (`qr.png`).

There are two tests. Do them in this order, with a **factory reset in between**:
- **Test 1 — USB:** install the app with a laptop and try every feature.
- **Test 2 — QR:** enroll the phone by scanning a QR code, the way the real fleet will be set up. No laptop needed.

> ⚠️ Both tests **erase the phone**. Use a phone with nothing on it that anyone needs.

---

## Part 0 — Before leaving home (on the dev PC)

The app and QR code are built on the dev PC and are tied to its signing key. Publish them to GitHub once:

1. Open https://github.com/ManLikeGanga19-tech/mdm → **Releases** (right side) → **Draft a new release**.
2. **Choose a tag** → type `spike-apk` → **Create new tag**.
3. Title: `Spike APK (temporary)`.
4. Drag in both files from `C:\Users\ADMIN\OneDrive\Desktop\noahport-work-kit\`:
   - `app-debug.apk`
   - `qr.png`
5. Tick **Set as a pre-release**, then **Publish release**.
6. Check it works: open this link in a browser. It should download the app:
   https://github.com/ManLikeGanga19-tech/mdm/releases/download/spike-apk/app-debug.apk

> If you rebuild the app later, the QR code must be regenerated on the dev PC and both files re-uploaded.

---

## Part 1 — What you need at work

| Item | Where to get it |
|---|---|
| A company Android phone (13, 14 or 15) that can be erased | — |
| A USB cable that carries data (not charge-only) | — |
| A laptop (only for Test 1) | Company laptop is fine; no admin rights needed |
| `app-debug.apk` and `qr.png` | https://github.com/ManLikeGanga19-tech/mdm/releases/tag/spike-apk |
| Android Platform-Tools (adb) | https://developer.android.com/tools/releases/platform-tools → **Download SDK Platform-Tools for Windows** |
| Wi-Fi name and password | Your office |
| (Optional) a second phone or laptop with Wi-Fi | For the hotspot test |

Write down the phone's **make, model and Android version** (Settings → About phone). I need these for the report.

---

## Part 2 — Test 1: USB install

### 2.1 Prepare the laptop
1. Download Platform-Tools (link above) and unzip it, e.g. to `C:\Users\<you>\Downloads\platform-tools`.
2. Download `app-debug.apk` from the release page into the same `platform-tools` folder.
3. Open the `platform-tools` folder in File Explorer, click the address bar, type `powershell` and press Enter.
   A terminal opens in that folder. All commands below are typed there.

### 2.2 Prepare the phone
1. **Factory reset:** Settings → System → Reset options → **Erase all data (factory reset)**.
   (On some phones: Settings → General management → Reset.)
2. Go through setup, but:
   - **Skip / do not add a Google account.** This is essential. With any account on the phone, the next step fails.
   - Connecting to Wi-Fi is fine. Skip everything optional.
3. Turn on Developer options: Settings → About phone → tap **Build number** 7 times.
4. Turn on USB debugging: Settings → System → Developer options → **USB debugging** → on.
5. Plug the phone into the laptop. On the phone, tap **Allow** on "Allow USB debugging?" (tick "Always allow").

### 2.3 Install the app and make it device owner
In the terminal:
```powershell
.\adb devices
```
You should see one line ending in `device`. If it says `unauthorized`, accept the prompt on the phone. If it shows nothing, see **Troubleshooting**.

```powershell
.\adb install app-debug.apk
.\adb shell dpm set-device-owner com.noahport.mdm.agent/.AdminReceiver
```
The second command must print `Success: Device owner set to package com.noahport.mdm.agent/.AdminReceiver`.

> If it says **"already some accounts on the device"**: an account was added during setup. Factory reset and repeat Part 2.2 without adding any account.

Open **Noahport MDM Agent** from the phone's app list. The top half has the buttons; the bottom half is the log.
Every result is written to the log as `PASS`, `FAIL` or `INFO`.

### 2.4 Run the checks
Do these in order. For each one, note what you **see on the phone** as well as what the log says.

| # | Do this | What should happen |
|---|---|---|
| 1 | Tap **Show status** | Log shows `deviceOwner=true` and the phone model |
| 2 | Turn the phone's **hotspot ON** (Settings or quick settings). Connect a second phone/laptop to it. Then tap **Block hotspot** | Hotspot **switches off by itself**, the other device disconnects, and the hotspot toggle in Settings is greyed out / blocked. Log: `PASS block hotspot` |
| 3 | Try to turn the hotspot on again in Settings | It should not be allowed |
| 4 | Tap **Unblock hotspot**, then turn the hotspot on | Hotspot works again |
| 5 | Use the phone for a minute (open Chrome, YouTube…), then tap **Query last 24 h usage** | Log: `PASS mobile usage` / `PASS wifi usage` listing several apps |
| 6 | **Tethering usage:** with the hotspot on and mobile data as the phone's internet (Wi-Fi off), download something ~50 MB on the connected device (e.g. a video). Tap **Query last 24 h usage** again | The `tethering` number on the `mobile` line grows by about that amount |
| 7 | Tap **Enable network logging** (a "device is managed / network monitored" notice may appear: that's expected). Browse a few websites in Chrome. Then in the laptop terminal run `.\adb shell dpm force-network-logs` | Within a minute the log shows `PASS network log batch: N events` with website names |
| 8 | Tap **Lock now** | Screen locks immediately |
| 9 | In the box under "App blocking" keep `com.android.chrome`. Tap **Suspend app**, then try to open Chrome | Chrome is greyed out / won't open. Tap **Unsuspend app**: Chrome works again |
| 10 | Tap **Hide app** | Chrome disappears from the app list. Tap **Unhide app**: it comes back |
| 11 | Tap **Reboot** → **Yes** | Phone restarts. Afterwards open the app → **Show status** → still `deviceOwner=true` |
| 12 | Tap **Share log** and send it to yourself (email/WhatsApp) | You receive the log text |

### 2.5 Optional: overnight battery test
Only meaningful on a **RugKing**, but useful on any phone:
1. Tap **Start soak**. Unplug the phone and leave it untouched for ~24 hours (screen off, on battery as long as possible).
2. Next day tap **Soak report** → **Share log**.
3. Good result: around 96 runs and a "longest gap" well under 120 min.

### 2.6 Finish Test 1
Tap **Share log** one last time and send it. Then continue with Test 2 (the factory reset there removes everything).

---

## Part 3 — Test 2: QR enrollment

No laptop needed. You only need `qr.png` on some screen (laptop, another phone, or printed) and Wi-Fi.

1. **Factory reset** the phone (Settings → System → Reset options → Erase all data).
2. Wait for the first **welcome screen** ("Hi there" / "Welcome" / language selection). **Do not press Start.**
3. **Tap the same empty spot on the welcome screen 6 times quickly.** A QR scanner should open.
   - It may first ask you to connect to Wi-Fi: connect to the office Wi-Fi.
   - If nothing happens, try tapping 6 times on a different spot (e.g. the middle of the screen, or the logo).
4. Point the phone at `qr.png`.
5. If it asks for Wi-Fi, connect. The phone should say something like **"This device belongs to your organization"**, then download and set up the app. Accept the prompts.
6. Finish setup. Open **Noahport MDM Agent** → **Show status** → **Share log**.

Take a **photo** (with another phone) of any error screen: setup screens can't be screenshotted.

### What to note for each step
| # | Question | Answer |
|---|---|---|
| Q1 | Did 6 taps on the welcome screen open a QR scanner? | yes / no |
| Q2 | Did it ask for Wi-Fi, and did connecting work? | |
| Q3 | Did the app download without an error ("Couldn't download…")? | |
| Q4 | Did setup continue after the download, with no "Can't set up device / contact your IT admin" error? | |
| Q5 | Does **Show status** in the app say `deviceOwner=true`? | |
| Q6 | Does the log contain a line starting `GET_PROVISIONING_MODE` and one starting `ADMIN_POLICY_COMPLIANCE ... enrollment_token=spike-test`? | |
| Q7 | Are Camera, Chrome, Phone and Messages still on the phone? | |

---

## Part 4 — Send back the results

Send (email, WhatsApp or paste in the chat):
1. Phone make, model and Android version.
2. The **Share log** text from Test 1 and from Test 2.
3. Your notes for Part 2.4 (especially hotspot turning off, and the tethering number).
4. The Q1–Q7 answers and photos of any error screens.

---

## Part 5 — Clean up

1. In the app tap **Release device owner / admin (test phones only)** → **Yes**. Then uninstall the app normally
   (or just factory reset the phone).
2. On GitHub: Releases → `Spike APK (temporary)` → **Delete** (the repo is public; don't leave the APK up).

---

## Troubleshooting

| Problem | Fix |
|---|---|
| `.\adb devices` shows nothing | Try another cable/USB port (many cables are charge-only). On the phone pull down notifications → USB → choose **File transfer**. The laptop may need the phone maker's USB driver; company laptops sometimes block this. |
| `unauthorized` in `.\adb devices` | Unlock the phone and accept the "Allow USB debugging?" prompt. If it never appears: Developer options → Revoke USB debugging authorizations, unplug, plug back in. |
| `'.\adb' is not recognized` | The terminal is not in the `platform-tools` folder. Repeat step 2.1.3. |
| `set-device-owner` → "already some accounts" | An account exists. Factory reset and skip all account sign-ins. |
| `set-device-owner` → "already provisioned" / "device is already set up" | Some phones only allow it right after reset. Factory reset, skip as much of setup as possible, and run the commands again. |
| `INSTALL_FAILED_...` when installing | Uninstall any older copy first: `.\adb uninstall com.noahport.mdm.agent` (if it refuses, tap **Release device owner / admin** in the app first). |
| App can't be uninstalled | Expected for a device owner. Use **Release device owner / admin** in the app first, or factory reset. |
| QR scan → download error | Note the exact message. Check the release link works in a browser. |
| QR scan → "Can't set up device" after download | Note the exact message; the app and QR may be from different builds (re-publish both from the dev PC). |
