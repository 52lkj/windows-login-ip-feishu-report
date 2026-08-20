#!/usr/bin/env bash
set -euo pipefail

Repository="${WLIFF_REPOSITORY:-52lkj/windows-login-ip-feishu-report}"
Branch="${WLIFF_BRANCH:-main}"
WebhookUrl="${FEISHU_WEBHOOK_URL:-}"
ArchiveUrl="${WLIFF_ARCHIVE_URL:-}"
InstallDir="${WLIFF_INSTALL_DIR:-/opt/windows-login-ip-feishu-report}"

if [[ -z "${WebhookUrl// }" ]]; then
    if [[ -r /dev/tty ]]; then
        read -r -p "Feishu webhook URL: " WebhookUrl </dev/tty
    else
        echo "FEISHU_WEBHOOK_URL is required." >&2
        exit 1
    fi
fi

if [[ -z "${WebhookUrl// }" ]]; then
    echo "Feishu webhook URL is required." >&2
    exit 1
fi

if [[ "${EUID:-$(id -u)}" -ne 0 ]]; then
    echo "Install requires root. Run with sudo." >&2
    exit 1
fi

if ! command -v unzip >/dev/null 2>&1; then
    echo "unzip is required." >&2
    exit 1
fi

TempRoot="$(mktemp -d)"
ZipPath="$TempRoot/repo.zip"
ExtractRoot="$TempRoot/extract"

cleanup() {
    rm -rf "$TempRoot"
}
trap cleanup EXIT

mkdir -p "$ExtractRoot"

if [[ -z "${ArchiveUrl// }" ]]; then
    ArchiveUrl="https://codeload.github.com/${Repository}/zip/refs/heads/${Branch}"
fi

echo "Downloading from: $ArchiveUrl"

if command -v curl >/dev/null 2>&1; then
    curl -fL "$ArchiveUrl" -o "$ZipPath"
elif command -v wget >/dev/null 2>&1; then
    wget -O "$ZipPath" "$ArchiveUrl"
else
    echo "curl or wget is required." >&2
    exit 1
fi

unzip -q "$ZipPath" -d "$ExtractRoot"

ScriptPath="$(find "$ExtractRoot" -maxdepth 3 -type f -name 'linux-login-ip-feishu-report.sh' | head -n 1)"

if [[ -z "$ScriptPath" ]]; then
    echo "Downloaded package does not contain linux-login-ip-feishu-report.sh." >&2
    exit 1
fi

mkdir -p "$InstallDir"
InstalledScriptPath="$InstallDir/linux-login-ip-feishu-report.sh"
cp "$ScriptPath" "$InstalledScriptPath"
chmod 755 "$InstalledScriptPath"

FEISHU_WEBHOOK_URL="$WebhookUrl" WLIFF_INSTALL_DIR="$InstallDir" "$InstalledScriptPath" --install

echo "Installed script: $InstalledScriptPath"
