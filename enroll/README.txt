NOAHPORT MDM - ENROLLING COMPANY PHONES OVER USB
================================================

Works on any Windows 10/11 laptop. No admin rights and no Android Studio needed.
Enrolling ERASES nothing by itself, but the phone must be freshly factory reset first.


ONE-TIME SETUP ON THE LAPTOP
----------------------------
1. Download the kit zip from the OneDrive link IT gave you.
2. Right-click the zip > Properties > tick "Unblock" (if shown) > OK.
   This stops Windows asking "Do you want to run this file?" for every phone.
3. Right-click the zip > Extract All... > choose e.g. Documents\noahport-enroll-kit.
4. The first run downloads Android Platform-Tools (adb, about 7 MB) from Google
   into your user folder. If the laptop has no internet, download
   "SDK Platform-Tools for Windows" from
   https://developer.android.com/tools/releases/platform-tools
   and unzip it into the kit folder (so that platform-tools\adb.exe is next to enroll.ps1).


PREPARE EACH PHONE
------------------
1. Factory reset: Settings > System > Reset options > Erase all data (factory reset).
2. Go through setup but DO NOT SIGN IN to a Google (or any) account. Skip it.
   Wi-Fi is fine. Skip everything optional.
3. Settings > About phone > tap "Build number" 7 times (enables Developer options).
4. Settings > System > Developer options > turn on "USB debugging".


ENROLL
------
1. Double-click enroll.cmd in the kit folder. A black window opens.
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
"does not match kit.json"     The download is damaged. Download the kit again.
"Could not download           The laptop can't reach Google. See ONE-TIME SETUP step 4.
 Platform-Tools"
The window closes instantly / The laptop blocks scripts. Ask IT to allow enroll.cmd,
"running scripts is disabled"  or run it from a laptop that allows it.
