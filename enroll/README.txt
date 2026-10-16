NOAHPORT MDM - ENROLLING COMPANY PHONES OVER USB
================================================

Works on any Windows 10/11 laptop. No admin rights, nothing to install.
The tool is one file: NoahportEnroll-<version>.exe.
Enrolling erases nothing by itself, but the phone must be freshly factory reset first.


ONE-TIME SETUP ON THE LAPTOP
----------------------------
1. Download NoahportEnroll-<version>.exe from the OneDrive link IT gave you,
   e.g. into Documents\Noahport.
2. Right-click the exe > Properties > tick "Unblock" (if shown) > OK.
3. The first run downloads Android Platform-Tools (adb, about 7 MB) from Google
   into your user folder. If the laptop has no internet, download
   "SDK Platform-Tools for Windows" from
   https://developer.android.com/tools/releases/platform-tools
   and unzip it next to the exe (so that platform-tools\adb.exe is beside it).

If Windows shows "Windows protected your PC": click "More info" > "Run anyway".
(The tool is not yet code-signed, so Windows doesn't recognise it.)


GET AN ENROLLMENT CODE (IT ADMIN, ONCE PER SESSION)
---------------------------------------------------
1. On the admin site: Enrollment codes > New. Pick the group the phones go
   into (e.g. "Shift phones"), how many phones, and an expiry date.
2. Copy the code (starts with NPE-). It is shown ONLY ONCE.
3. The tool asks for it when it starts and checks it with the server.
   One code covers the whole session; each phone uses up one of its uses.

Shift phones lock themselves if no handover is recorded after a shift change
(group setting "Handover lock"). Right after enrolling, record a handover for
each phone (admin site > Shift handover), or turn the lock off for the group
until the pilot starts.


DOING THIS OVER ANYDESK
-----------------------
The phones plug into the laptop that RUNS the tool (the company laptop), not
into the PC of the person helping over AnyDesk. AnyDesk only shares the screen.
- Person at the laptop: unplugs/plugs phones, does the phone steps below, and
  taps "Allow" on "Allow USB debugging?" on each phone.
- Person on AnyDesk: runs the exe, types the enrollment code, asset tags and
  names in the black window, and checks each phone appears on the admin site.
No admin rights are needed, so AnyDesk's "request elevation" is not needed.


PREPARE EACH PHONE
------------------
1. Factory reset: Settings > System > Reset options > Erase all data (factory reset).
2. Go through setup but DO NOT SIGN IN to a Google (or any) account. Skip it.
   Wi-Fi is fine. Skip everything optional.
3. Settings > About phone > tap "Build number" 7 times (enables Developer options).
4. Settings > System > Developer options > turn on "USB debugging".


ENROLL
------
1. Double-click NoahportEnroll-<version>.exe. A black window opens.
   Paste the enrollment code and press Enter; wait for "Code accepted".
2. Plug in the phone with a DATA cable. On the phone tap "Allow" on
   "Allow USB debugging?" (tick "Always allow").
3. Type the phone's asset tag (e.g. NP-0042) and press Enter.
   Type the name of the person it is issued to, or just press Enter to skip.
4. Wait for "DONE: ... is enrolled". The agent app opens on the phone.
5. Unplug the phone, press Enter, and plug in the next one.
   Type Q and Enter when you are finished.

Every phone (enrolled or not) is recorded in Documents\Noahport\enrollments.csv
on this laptop. Send that file to IT after each session.


IF SOMETHING GOES WRONG
-----------------------
"Waiting for a phone..."      Try another cable or USB port (many cables are charge-only).
                              On the phone: pull down notifications > USB > "File transfer".
"Allow USB debugging?" never  Developer options > Revoke USB debugging authorizations,
 appears                      then unplug and plug back in.
"has 1 account(s)"            Someone signed in during setup. Factory reset and skip sign-ins.
"Another app manages this     The phone belongs to another MDM. Factory reset it.
 phone"
"set-device-owner failed"     Factory reset, skip as much of setup as possible, try again.
"agent inside this tool is    The download is damaged. Download the exe again.
 damaged"
"Could not download           The laptop can't reach Google. See ONE-TIME SETUP step 3.
 Platform-Tools"
The exe is blocked or deleted The laptop's security software doesn't trust it yet.
 by the laptop                Ask IT to allow NoahportEnroll.exe.
