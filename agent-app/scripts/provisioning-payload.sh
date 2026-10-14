#!/usr/bin/env bash
# Builds the QR provisioning JSON for a fully managed (device owner) setup.
#
# Usage: scripts/provisioning-payload.sh <signed.apk> <https-download-url> [out.json]
# Optional env: WIFI_SSID, WIFI_PASSWORD, WIFI_SECURITY (WPA|WEP|NONE), API_BASE_URL, ENROLLMENT_TOKEN
#
# Render the QR locally (keeps tokens off third-party sites):
#   npx --yes qrcode@1.5.4 -o qr.png "$(cat out.json)"
set -euo pipefail

apk="${1:?signed apk path}"
url="${2:?https download url for the apk}"
out="${3:-provisioning.json}"

sdk="${ANDROID_HOME:-$LOCALAPPDATA/Android/Sdk}"
build_tools="$(ls -d "$sdk"/build-tools/* | sort -V | tail -1)"
apksigner="$build_tools/apksigner"
[ -f "$apksigner.bat" ] && apksigner="$apksigner.bat"

# Android compares this to the URL-safe base64 SHA-256 of the APK's signing certificate.
cert_hex="$("$apksigner" verify --print-certs "$apk" | sed -n 's/.*certificate SHA-256 digest: //p' | head -1 | tr -d '\r')"
[ -n "$cert_hex" ] || { echo "Could not read signing certificate from $apk" >&2; exit 1; }
checksum="$(printf '%s' "$cert_hex" | xxd -r -p | base64 | tr '+/' '-_' | tr -d '=')"

wifi=""
if [ -n "${WIFI_SSID:-}" ]; then
  wifi=",
  \"android.app.extra.PROVISIONING_WIFI_SSID\": \"$WIFI_SSID\",
  \"android.app.extra.PROVISIONING_WIFI_PASSWORD\": \"${WIFI_PASSWORD:-}\",
  \"android.app.extra.PROVISIONING_WIFI_SECURITY_TYPE\": \"${WIFI_SECURITY:-WPA}\""
fi

cat > "$out" <<JSON
{
  "android.app.extra.PROVISIONING_DEVICE_ADMIN_COMPONENT_NAME": "com.noahport.mdm.agent/.AdminReceiver",
  "android.app.extra.PROVISIONING_DEVICE_ADMIN_PACKAGE_DOWNLOAD_LOCATION": "$url",
  "android.app.extra.PROVISIONING_DEVICE_ADMIN_SIGNATURE_CHECKSUM": "$checksum",
  "android.app.extra.PROVISIONING_LEAVE_ALL_SYSTEM_APPS_ENABLED": true,
  "android.app.extra.PROVISIONING_ADMIN_EXTRAS_BUNDLE": {
    "api_base_url": "${API_BASE_URL:-https://example.invalid}",
    "enrollment_token": "${ENROLLMENT_TOKEN:-spike-test}"
  }$wifi
}
JSON

echo "Wrote $out (signature checksum $checksum)"
