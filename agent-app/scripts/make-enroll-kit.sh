#!/usr/bin/env bash
# Builds the signed release agent and the USB enrollment tool NoahportEnroll.exe (agent embedded) that any company
# laptop can run with nothing installed. Needs the release key (~/.noahport-signing, see docs/STATUS.md) and the
# .NET SDK.
#
# Usage (from agent-app/, in Git Bash): scripts/make-enroll-kit.sh [output-dir]
# Default output: the OneDrive folder the tool is shared from.
set -euo pipefail

cd "$(dirname "$0")/.."
out="${1:-$USERPROFILE/OneDrive/Noahport/enroll-kit}"
sdk="${ANDROID_HOME:-$LOCALAPPDATA/Android/Sdk}"
export JAVA_HOME="${JAVA_HOME:-/c/Program Files/Android/Android Studio/jbr}"
apksigner="$(ls -d "$sdk"/build-tools/* | sort -V | tail -1)/apksigner.bat"

./gradlew assembleRelease --console=plain -q
apk="$PWD/app/build/outputs/apk/release/app-release.apk"
meta=app/build/outputs/apk/release/output-metadata.json

# Refuse an unsigned build: it can't be installed, and the tool must always carry the permanent key's signature.
cert=$("$apksigner" verify --print-certs "$apk" | sed -n 's/.*certificate SHA-256 digest: //p' | head -1)
[ -n "$cert" ] || { echo "Release APK is not signed. Is ~/.noahport-signing/release.properties present?" >&2; exit 1; }
# Fleet phones only ever get the release agent: a debuggable build shows test controls and allows run-as.
aapt="$(ls -d "$sdk"/build-tools/* | sort -V | tail -1)/aapt.exe"
if "$aapt" dump xmltree "$apk" AndroidManifest.xml | grep -q 'android:debuggable'; then
  echo "Refusing a debuggable APK: the enrollment kit must carry the release build." >&2; exit 1
fi

version=$(python -I -c "import json,sys; e=json.load(open(sys.argv[1]))['elements'][0]; print(e['versionName'])" "$meta")
numeric=$(echo "$version" | grep -oE '^[0-9]+(\.[0-9]+){0,3}')
sha=$(sha256sum "$apk" | cut -d' ' -f1)

stage="$PWD/app/build/enroll-kit"
rm -rf "$stage"
dotnet publish ../enroll/NoahportEnroll -c Release -nologo -v q -o "$stage" \
  -p:AgentApk="$apk" -p:AgentVersion="$version" -p:AgentVersionNumeric="$numeric" \
  -p:ApkSha256="$sha" -p:SigningCertSha256="$cert"

mkdir -p "$out"
cp "$stage/NoahportEnroll.exe" "$out/NoahportEnroll-$version.exe"
cp ../enroll/README.txt "$out/README.txt"

echo "Tool: $out/NoahportEnroll-$version.exe"
echo "Agent $version, APK SHA-256 $sha, signing cert $cert"
echo "Share it: right-click the exe in OneDrive > Share > specific people."
