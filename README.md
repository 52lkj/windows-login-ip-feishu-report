# windows-login-ip-feishu-report

Scripts to collect today's successful login IPs, probe the server public IP, and send a daily summary to a Feishu group.

- Windows: reads Security event ID 4624.
- Linux: reads `last`/`wtmp`, `journalctl`, `/var/log/auth.log`, and `/var/log/secure`.

## Windows Quick Deploy

Run this on the target Windows server:

```powershell
$env:FEISHU_WEBHOOK_URL = "https://open.feishu.cn/open-apis/bot/v2/hook/xxxx"
irm https://raw.githubusercontent.com/52lkj/windows-login-ip-feishu-report/main/install.ps1 | iex
```

It will download the repository, ask for your Feishu webhook if needed, and install the daily task.

If GitHub is slow in your region, download the installer from a mirror or use a different archive URL:

```powershell
$env:FEISHU_WEBHOOK_URL = "https://open.feishu.cn/open-apis/bot/v2/hook/xxxx"
irm https://raw.githubusercontent.com/52lkj/windows-login-ip-feishu-report/main/install.ps1 | iex
# optional:
# $env:WLIFF_ARCHIVE_URL = "https://your-mirror.example.com/windows-login-ip-feishu-report.zip"
```

## Windows Usage

```powershell
.\Get-SuccessfulLoginIPs.ps1 -Today -WebhookUrl "https://open.feishu.cn/open-apis/bot/v2/hook/xxxx"
.\Get-SuccessfulLoginIPs.ps1 -InstallTask -WebhookUrl "https://open.feishu.cn/open-apis/bot/v2/hook/xxxx"
```

## Linux Quick Deploy

Run this on the target Linux server:

```bash
export FEISHU_WEBHOOK_URL="https://open.feishu.cn/open-apis/bot/v2/hook/xxxx"
curl -fsSL https://raw.githubusercontent.com/52lkj/windows-login-ip-feishu-report/main/install-linux.sh | sudo bash
```

If GitHub is slow in your region, download the installer from a mirror or use a different archive URL:

```bash
export FEISHU_WEBHOOK_URL="https://open.feishu.cn/open-apis/bot/v2/hook/xxxx"
export WLIFF_ARCHIVE_URL="https://your-mirror.example.com/windows-login-ip-feishu-report.zip"
curl -fsSL https://raw.githubusercontent.com/52lkj/windows-login-ip-feishu-report/main/install.sh | sudo bash
```

The Linux installer creates a systemd timer that sends the report daily at 18:00.

## Linux Usage

```bash
./linux-login-ip-feishu-report.sh --today
sudo FEISHU_WEBHOOK_URL="https://open.feishu.cn/open-apis/bot/v2/hook/xxxx" ./linux-login-ip-feishu-report.sh --install
```

## Notes

- Run PowerShell as Administrator if Security log access is denied.
- On Linux, run the installer with root privileges so it can read protected login logs and install the systemd timer.
- The scheduled task or timer is created to run daily at 18:00.
