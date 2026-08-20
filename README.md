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

To send Windows login events to the web backend:

```powershell
.\Get-SuccessfulLoginIPs.ps1 -Today -BackendUrl "https://your-site.example.com/web/ingest.php" -IngestToken "replace-with-a-long-random-token"
.\Get-SuccessfulLoginIPs.ps1 -InstallTask -BackendUrl "https://your-site.example.com/web/ingest.php" -IngestToken "replace-with-a-long-random-token"
```

## Linux Quick Deploy

Run this on the target Linux server:

```bash
export FEISHU_WEBHOOK_URL="https://open.feishu.cn/open-apis/bot/v2/hook/xxxx"
curl -fsSL https://cdn.jsdelivr.net/gh/52lkj/windows-login-ip-feishu-report@main/install-linux.sh | sudo bash
```

If you want to force a specific raw file mirror:

```bash
export FEISHU_WEBHOOK_URL="https://open.feishu.cn/open-apis/bot/v2/hook/xxxx"
export WLIFF_RAW_BASE="https://cdn.jsdelivr.net/gh/52lkj/windows-login-ip-feishu-report@main"
curl -fsSL "$WLIFF_RAW_BASE/install-linux.sh" | sudo bash
```

The Linux installer creates a systemd timer that sends the report daily at 18:00.

## Linux Usage

```bash
./linux-login-ip-feishu-report.sh --today
sudo FEISHU_WEBHOOK_URL="https://open.feishu.cn/open-apis/bot/v2/hook/xxxx" ./linux-login-ip-feishu-report.sh --install
```

## Web Backend

The optional PHP backend receives structured login events, matches them with the server table, flags unusual successful logins, and caches VirusTotal IP reputation.

### Backend Deploy

Upload the `web/` directory to a PHP server with SQLite enabled, then create `web/config.php`:

```php
<?php
return [
    'db_path' => __DIR__ . '/data/login-monitor.sqlite',
    'ingest_token' => 'replace-with-a-long-random-token',
    'admin_token' => 'replace-with-an-admin-token',
    'virustotal_api_key' => 'your-virustotal-api-key',
    'timezone' => 'Asia/Shanghai',
];
```

Open the dashboard:

```text
https://your-site.example.com/web/index.php?token=replace-with-an-admin-token
```

### Linux Agent To Backend

Install the Linux agent with backend reporting enabled:

```bash
export WLIFF_BACKEND_URL="https://your-site.example.com/web/ingest.php"
export WLIFF_INGEST_TOKEN="replace-with-a-long-random-token"
curl -fsSL https://cdn.jsdelivr.net/gh/52lkj/windows-login-ip-feishu-report@main/install-linux.sh | sudo bash
```

You can keep Feishu enabled at the same time:

```bash
export FEISHU_WEBHOOK_URL="https://open.feishu.cn/open-apis/bot/v2/hook/xxxx"
export WLIFF_BACKEND_URL="https://your-site.example.com/web/ingest.php"
export WLIFF_INGEST_TOKEN="replace-with-a-long-random-token"
curl -fsSL https://cdn.jsdelivr.net/gh/52lkj/windows-login-ip-feishu-report@main/install-linux.sh | sudo bash
```

### Windows Agent To Backend

Install the Windows agent with backend reporting enabled:

```powershell
$env:WLIFF_BACKEND_URL = "https://your-site.example.com/web/ingest.php"
$env:WLIFF_INGEST_TOKEN = "replace-with-a-long-random-token"
irm https://raw.githubusercontent.com/52lkj/windows-login-ip-feishu-report/main/install.ps1 | iex
```

You can keep Feishu enabled at the same time by also setting `FEISHU_WEBHOOK_URL`.

### VirusTotal Enrichment

The backend can refresh suspicious IPs from VirusTotal. Run it from cron:

```cron
*/30 * * * * php /path/to/web/enrich.php >/dev/null 2>&1
```

You can also refresh one IP from the IP detail page.

## Notes

- Run PowerShell as Administrator if Security log access is denied.
- On Linux, run the installer with root privileges so it can read protected login logs and install the systemd timer.
- The scheduled task or timer is created to run daily at 18:00.
