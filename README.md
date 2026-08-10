# windows-login-ip-feishu-report

PowerShell script to collect today's successful Windows login IPs, probe the server public IP, and send a daily summary to a Feishu group.

## Usage

```powershell
.\Get-SuccessfulLoginIPs.ps1 -Today -WebhookUrl "https://open.feishu.cn/open-apis/bot/v2/hook/xxxx"
.\Get-SuccessfulLoginIPs.ps1 -InstallTask -WebhookUrl "https://open.feishu.cn/open-apis/bot/v2/hook/xxxx"
```

## Notes

- Run PowerShell as Administrator if Security log access is denied.
- The scheduled task is created to run daily at 18:00.
