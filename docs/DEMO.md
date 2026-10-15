# Demo for management — Noahport phone MDM (about 10 minutes)

Goal of the meeting: show that the risky part (controlling company phones) is proven, show what IT will get, and get
approval for the pilot.

## What you need
| Item | State |
|---|---|
| Test phone (Safaricom Neon Smarta 2 K, asset tag `NP-TEST-02`) | Enrolled, agent 0.2.0 = exactly what a staff phone runs. Charge it. |
| Laptop (dev PC) with the emulator `Noahport_Spike` | Runs the **test** agent with the control buttons (IT's controls, before the admin site exists) |
| Admin-site prototype | https://claude.ai/artifact/UA8LkCEucxpxwVs6bTavec — offline copy: `C:\Users\ADMIN\OneDrive\Noahport\demo\admin-console-prototype.html` (double-click opens it in the browser) |
| Enrollment tool | `C:\Users\ADMIN\OneDrive\Noahport\enroll-kit\NoahportEnroll-0.2.0.exe` |
| USB cable | For the enrollment-tool step |

## Before the meeting (10 min)
1. Start the emulator **without** wiping it (wiping removes the test agent). In PowerShell:
   ```powershell
   & "C:\Users\ADMIN\AppData\Local\Android\Sdk\emulator\emulator.exe" -avd Noahport_Spike
   ```
   When it has booted, open **Noahport MDM Agent** on it and tap **Show status**: the log must say `deviceOwner=true`.
   Then open **Chrome** on the emulator once and get past its welcome screens (**Use without an account** / **No
   thanks**). Otherwise Chrome stays on its first-run screen and the website-logging step shows no site names.
2. Open the prototype link in the browser and click through once.
3. Unlock the test phone and open **Noahport MDM Agent** (the staff notice).
4. Put the phone on Wi-Fi so the demo doesn't spend your data bundle.

## The script

### 1. The problem (1 min)
"Company phones are used for personal hotspot sharing and non-work apps; data costs rise and we can't see or control
it. We are building our own management system for the company phones: an app on every phone, and an admin website
for IT."

### 2. What a staff member sees (2 min) — test phone
- Show the **"This phone is managed by Noahport"** screen: what Noahport can and cannot see, what IT can do,
  the phone's asset tag. Staff tap **I understand**; the date is recorded (Kenya Data Protection Act).
- Point out there are **no buttons**: staff cannot change anything.
- Try to remove the app: long-press it → there is no Uninstall (it is the phone's manager). Settings → Apps →
  Noahport MDM Agent → Uninstall is greyed out.
- Pull down the notification shade / Settings: Android itself says the device is managed by the organization.

### 3. IT's controls, live (3 min) — emulator on the laptop
"These are the same controls the admin website will send to phones. Today I trigger them with a test app."
- **Block hotspot** → open Settings → Network & internet → Hotspot & tethering: it's blocked by the admin.
  (On the real test phone this also switched off a running hotspot and disconnected the other device.)
- **Suspend app** (`com.android.chrome`) → try to open Chrome → "Blocked by your IT admin". **Unsuspend app**.
- **Query last 24 h usage** → data used per app, including hotspot sharing.
- **Enable network logging**, open a website in Chrome, then in PowerShell:
  ```powershell
  & "C:\Users\ADMIN\AppData\Local\Android\Sdk\platform-tools\adb.exe" shell dpm force-network-logs
  ```
  → the log lists the websites visited and which app opened them.
- **Lock now** → the screen locks.
- Leave **Unblock hotspot** for the end so the emulator is left clean.

### 4. The admin website IT will use (3 min) — prototype
"This is a clickable prototype with example data. Building the real one is the next phase."
- **Phones**: every phone on one row; search, filter by group.
- **Alerts** → `NP-0004 shared 6.3 GB over hotspot this week` → **Open phone**.
- On the phone's page: switch **Hotspot** to **Blocked** → watch **Commands**: Queued → Delivered → Done.
- **Apps** tab: block an app. **Websites & services** tab: what the phone connected to.
- **Groups & rules**: Field Ops / Drivers block the hotspot; Supervisors may share but are monitored.
- **Enroll phones**: how a new phone is added.

### 5. Adding a phone (1 min) — enrollment tool
Plug the test phone into the laptop, double-click `NoahportEnroll-0.2.0.exe`. It recognises the phone:
"This phone is already enrolled. Nothing changed." Explain: on a new, reset phone it installs the app, makes it the
phone's manager and records the asset tag and owner, in about a minute per phone, from any company laptop.

### 6. Status and what we need (1 min)
Proven on a real phone (2026-10-15): device management, hotspot block/unblock (turns off a live hotspot), data
usage per app and hotspot, website logging, lock, restart, app blocking, enrollment from a laptop.

Next steps (from `docs/ARCHITECTURE.md` §9):
1. **Admin website + server** (Phase 2): the real version of the prototype, on our own server.
2. **Phones report to the server** (Phases 3–5): the app checks in, IT's changes apply within minutes, usage charts
   and email alerts.
3. **Pilot**: a small group of RugKing phones for 1–2 weeks, including a 24-hour battery test on a RugKing.
4. **Rollout** to all phones.

Decisions/support needed from management:
- Approve the pilot and choose the pilot group (suggest 3–5 phones, one per group).
- Company documents for the free **D-U-N-S number** (certificate of incorporation, registered address), needed for
  Google to approve our app for QR-code setup without a laptop.
- A **domain name** for the admin website.
- A **staff device-use policy** for staff to sign before rollout (Kenya Data Protection Act).
- Exact RugKing model(s) in the fleet.

## If something goes wrong
| Problem | Do this |
|---|---|
| Emulator shows `deviceOwner=false` or the app is missing | Skip part 3; say the controls were verified on the real phone and show `docs/spike-report.md` results |
| Chrome won't open on the emulator for the logging step | Skip it; mention 64 events with site names were logged on the real phone |
| No internet for the prototype link | Open the offline copy in OneDrive\Noahport\demo |
| Enrollment tool blocked by Windows ("Windows protected your PC") | More info → Run anyway. That's expected until the tool is code-signed |
| Test phone asks for the hotspot test | Don't use its hotspot in the demo: it runs the staff app with no controls, and the hotspot is currently allowed |
