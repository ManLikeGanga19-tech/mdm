#!/usr/bin/env bash
# Builds the agent, installs it on the one connected phone/emulator, makes it device owner and opens it.
# The phone must be freshly reset with no accounts added (Settings > Passwords & accounts must be empty).
#
# Usage (from agent-app/, in Git Bash): scripts/install-device-owner.sh
set -euo pipefail
export MSYS_NO_PATHCONV=1 # stop Git Bash rewriting /sdcard-style device paths

cd "$(dirname "$0")/.."
sdk="${ANDROID_HOME:-$LOCALAPPDATA/Android/Sdk}"
adb="$sdk/platform-tools/adb"
export JAVA_HOME="${JAVA_HOME:-/c/Program Files/Android/Android Studio/jbr}"

./gradlew assembleDebug --console=plain -q
"$adb" install -r app/build/outputs/apk/debug/app-debug.apk
"$adb" shell dpm set-device-owner com.noahport.mdm.agent/.AdminReceiver
"$adb" shell am start -n com.noahport.mdm.agent/.MainActivity
echo "Done. Pull the log with: $adb exec-out run-as com.noahport.mdm.agent cat files/spike.log"
