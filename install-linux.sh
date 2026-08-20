#!/usr/bin/env bash
set -euo pipefail

Repository="${WLIFF_REPOSITORY:-52lkj/windows-login-ip-feishu-report}"
Branch="${WLIFF_BRANCH:-main}"
WebhookUrl="${FEISHU_WEBHOOK_URL:-}"
BackendUrl="${WLIFF_BACKEND_URL:-}"
IngestToken="${WLIFF_INGEST_TOKEN:-}"
InstallDir="${WLIFF_INSTALL_DIR:-/opt/windows-login-ip-feishu-report}"
RawBase="${WLIFF_RAW_BASE:-https://cdn.jsdelivr.net/gh/${Repository}@${Branch}}"

if [[ -z "${WebhookUrl// }" && -z "${BackendUrl// }" ]]; then
    if [[ -r /dev/tty ]]; then
        read -r -p "Feishu webhook URL: " WebhookUrl </dev/tty
    else
        echo "FEISHU_WEBHOOK_URL or WLIFF_BACKEND_URL is required." >&2
        exit 1
    fi
fi

if [[ -z "${WebhookUrl// }" && -z "${BackendUrl// }" ]]; then
    echo "Feishu webhook URL or backend URL is required." >&2
    exit 1
fi

if [[ -n "${BackendUrl// }" && -z "${IngestToken// }" ]]; then
    echo "WLIFF_INGEST_TOKEN is required when WLIFF_BACKEND_URL is set." >&2
    exit 1
fi

if [[ "${EUID:-$(id -u)}" -ne 0 ]]; then
    echo "Install requires root. Run with sudo or as root." >&2
    exit 1
fi

TempRoot="$(mktemp -d)"
ScriptPath="$TempRoot/linux-login-ip-feishu-report.sh"

cleanup() {
    rm -rf "$TempRoot"
}
trap cleanup EXIT

ScriptUrl="${RawBase%/}/linux-login-ip-feishu-report.sh"
echo "Downloading from: $ScriptUrl"

if command -v curl >/dev/null 2>&1; then
    curl -fL "$ScriptUrl" -o "$ScriptPath"
elif command -v wget >/dev/null 2>&1; then
    wget -O "$ScriptPath" "$ScriptUrl"
else
    echo "curl or wget is required." >&2
    exit 1
fi

mkdir -p "$InstallDir"
InstalledScriptPath="$InstallDir/linux-login-ip-feishu-report.sh"
cp "$ScriptPath" "$InstalledScriptPath"
chmod 755 "$InstalledScriptPath"

FEISHU_WEBHOOK_URL="$WebhookUrl" WLIFF_BACKEND_URL="$BackendUrl" WLIFF_INGEST_TOKEN="$IngestToken" WLIFF_INSTALL_DIR="$InstallDir" "$InstalledScriptPath" --install

echo "Installed script: $InstalledScriptPath"
