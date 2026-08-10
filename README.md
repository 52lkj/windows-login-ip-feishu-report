# windows-login-ip-feishu-report

PowerShell script to collect today's successful Windows login IPs, probe the server public IP, and send a daily summary to a Feishu group.

## Quick Deploy

Run this on the target Windows server:

```powershell
irm https://raw.githubusercontent.com/52lkj/windows-login-ip-feishu-report/main/install.ps1 | iex
```

It will download the repository, ask for your Feishu webhook if needed, and install the daily task.

If GitHub is slow in your region, download the installer from a mirror or use a different archive URL:

```powershell
irm https://raw.githubusercontent.com/52lkj/windows-login-ip-feishu-report/main/install.ps1 | iex
# then run:
# .\install.ps1 -ArchiveUrl "https://your-mirror.example.com/windows-login-ip-feishu-report.zip"
```

## Usage

```powershell
.\Get-SuccessfulLoginIPs.ps1 -Today -WebhookUrl "https://open.feishu.cn/open-apis/bot/v2/hook/xxxx"
.\Get-SuccessfulLoginIPs.ps1 -InstallTask -WebhookUrl "https://open.feishu.cn/open-apis/bot/v2/hook/xxxx"
```

## Notes

- Run PowerShell as Administrator if Security log access is denied.
- The scheduled task is created to run daily at 18:00.
