#!/usr/bin/env bash
# Builds the signed release agent and packs the USB enrollment kit (enroll/ + agent.apk + kit.json) into a zip that
# any company laptop can use. Needs the release key (~/.noahport-signing, see docs/STATUS.md).
#
# Usage (from agent-app/, in Git Bash): scripts/make-enroll-kit.sh [output-dir]
# Default output: the OneDrive folder the kit is shared from.
set -euo pipefail

cd "$(dirname "$0")/.."
out="${1:-$USERPROFILE/OneDrive/Noahport/enroll-kit}"
sdk="${ANDROID_HOME:-$LOCALAPPDATA/Android/Sdk}"
export JAVA_HOME="${JAVA_HOME:-/c/Program Files/Android/Android Studio/jbr}"
apksigner="$(ls -d "$sdk"/build-tools/* | sort -V | tail -1)/apksigner.bat"

./gradlew assembleRelease --console=plain -q
apk=app/build/outputs/apk/release/app-release.apk
meta=app/build/outputs/apk/release/output-metadata.json

# Refuse an unsigned build: it can't be installed, and a kit must always carry the permanent key's signature.
cert=$("$apksigner" verify --print-certs "$apk" | sed -n 's/.*certificate SHA-256 digest: //p' | head -1)
[ -n "$cert" ] || { echo "Release APK is not signed. Is ~/.noahport-signing/release.properties present?" >&2; exit 1; }

version=$(python -I -c "import json,sys; e=json.load(open(sys.argv[1]))['elements'][0]; print(e['versionName'], e['versionCode'])" "$meta")
name=${version% *}
code=${version#* }
stage="app/build/enroll-kit/noahport-enroll-kit-$name"
rm -rf "$stage" && mkdir -p "$stage"
cp ../enroll/enroll.ps1 ../enroll/enroll.cmd ../enroll/README.txt "$stage/"
cp "$apk" "$stage/agent.apk"
sha=$(sha256sum "$apk" | cut -d' ' -f1 | tr a-f A-F) # Get-FileHash prints upper-case hex

cat > "$stage/kit.json" <<EOF
{
  "package": "com.noahport.mdm.agent",
  "adminReceiver": ".AdminReceiver",
  "versionName": "$name",
  "versionCode": $code,
  "minSdk": 33,
  "apk": "agent.apk",
  "apkSha256": "$sha",
  "signingCertSha256": "$cert",
  "builtAtUtc": "$(date -u +%Y-%m-%dT%H:%M:%SZ)"
}
EOF

mkdir -p "$out"
zip="$out/noahport-enroll-kit-$name.zip"
python -I -c "
import os, sys, zipfile
stage, zip_path = sys.argv[1], sys.argv[2]
root = os.path.dirname(stage)
with zipfile.ZipFile(zip_path, 'w', zipfile.ZIP_DEFLATED) as z:
    for d, _, files in os.walk(stage):
        for f in files:
            p = os.path.join(d, f)
            z.write(p, os.path.relpath(p, root))
" "$stage" "$zip"

echo "Kit: $zip"
echo "Agent $name ($code), APK SHA-256 $sha, signing cert $cert"
echo "Share it: right-click the zip in OneDrive > Share > people in your organization / specific people."
