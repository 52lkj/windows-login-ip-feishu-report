<#
.SYNOPSIS
Extract successful Windows logon IP addresses from the Security event log and send daily summaries to Feishu.

.DESCRIPTION
Reads Windows Security event ID 4624, extracts the IpAddress field from the
event XML, and can optionally:
  - install a daily scheduled task that runs at 18:00
  - probe the server's outbound public IP address
  - send a text summary to a Feishu group webhook

Run PowerShell as Administrator if access to the Security log is denied.

.EXAMPLE
.\Get-SuccessfulLoginIPs.ps1 -Today

.EXAMPLE
.\Get-SuccessfulLoginIPs.ps1 -Today -WebhookUrl https://open.feishu.cn/open-apis/bot/v2/hook/xxxx

.EXAMPLE
.\Get-SuccessfulLoginIPs.ps1 -InstallTask -WebhookUrl https://open.feishu.cn/open-apis/bot/v2/hook/xxxx

.EXAMPLE
.\Get-SuccessfulLoginIPs.ps1 -Since (Get-Date).AddDays(-7) -Unique
#>

[CmdletBinding()]
param(
    [datetime]$Since,
    [datetime]$Until,
    [switch]$Today,
    [switch]$Unique,
    [switch]$Detailed,
    [switch]$IncludeLocal,
    [string]$OutCsv,
    [string]$WebhookUrl,
    [switch]$InstallTask,
    [string]$TaskName = 'Get-SuccessfulLoginIPs-Daily',
    [string]$TaskTime = '18:00'
)

$filter = @{
    LogName = 'Security'
    Id      = 4624
}

if ($Today) {
    $Since = (Get-Date).Date
    $Until = Get-Date
}

if ($PSBoundParameters.ContainsKey('Since')) {
    $filter.StartTime = $Since
}

if ($PSBoundParameters.ContainsKey('Until')) {
    $filter.EndTime = $Until
}

function Get-EventDataValue {
    param(
        [xml]$EventXml,
        [string]$Name
    )

    $node = $EventXml.Event.EventData.Data | Where-Object { $_.Name -eq $Name } | Select-Object -First 1
    if ($null -eq $node) {
        return $null
    }

    return [string]$node.'#text'
}

function Normalize-IpAddress {
    param([string]$IpAddress)

    if ([string]::IsNullOrWhiteSpace($IpAddress)) {
        return $null
    }

    $value = $IpAddress.Trim()
    if ($value.StartsWith('::ffff:')) {
        $value = $value.Substring(7)
    }

    return $value.ToLowerInvariant()
}

function Get-PublicIpAddress {
    $targets = @(
        @{
            Kind = 'json'
            Uri  = 'https://api.ipify.org?format=json'
            Key  = 'ip'
        },
        @{
            Kind = 'text'
            Uri  = 'https://ifconfig.me/ip'
        },
        @{
            Kind = 'text'
            Uri  = 'https://checkip.amazonaws.com'
        }
    )

    foreach ($target in $targets) {
        try {
            if ($target.Kind -eq 'json') {
                $response = Invoke-RestMethod -Uri $target.Uri -Method Get -TimeoutSec 10 -ErrorAction Stop
                $ip = [string]$response.($target.Key)
            }
            else {
                $response = Invoke-WebRequest -Uri $target.Uri -UseBasicParsing -TimeoutSec 10 -ErrorAction Stop
                $ip = [string]$response.Content
            }

            $ip = ($ip -replace '\s+', '').Trim()
            if ($ip) {
                return $ip
            }
        }
        catch {
            continue
        }
    }

    return $null
}

function Get-SuccessfulLoginRecords {
    param(
        [hashtable]$FilterHashtable,
        [switch]$AllowLocal
    )

    $localOrEmptyValues = @('', '-', '::1', '127.0.0.1')

    foreach ($event in Get-WinEvent -FilterHashtable $FilterHashtable -ErrorAction Stop) {
        $xml = [xml]$event.ToXml()
        $ipAddress = Normalize-IpAddress -IpAddress (Get-EventDataValue -EventXml $xml -Name 'IpAddress')

        if ([string]::IsNullOrWhiteSpace($ipAddress)) {
            continue
        }

        if (-not $AllowLocal -and $localOrEmptyValues -contains $ipAddress) {
            continue
        }

        [pscustomobject]@{
            TimeCreated       = $event.TimeCreated
            IpAddress         = $ipAddress
            AccountName       = Get-EventDataValue -EventXml $xml -Name 'TargetUserName'
            Domain            = Get-EventDataValue -EventXml $xml -Name 'TargetDomainName'
            LogonType         = Get-EventDataValue -EventXml $xml -Name 'LogonType'
            WorkstationName   = Get-EventDataValue -EventXml $xml -Name 'WorkstationName'
            AuthenticationPkg = Get-EventDataValue -EventXml $xml -Name 'AuthenticationPackageName'
            ProcessName       = Get-EventDataValue -EventXml $xml -Name 'ProcessName'
            EventRecordId     = $event.RecordId
        }
    }
}

function Build-SummaryText {
    param(
        [string]$PublicIp,
        [object[]]$Records
    )

    $summary = $Records |
        Group-Object IpAddress |
        Sort-Object Count -Descending |
        ForEach-Object {
            [pscustomobject]@{
                IpAddress = $_.Name
                Count     = $_.Count
                FirstSeen = ($_.Group | Sort-Object TimeCreated | Select-Object -First 1).TimeCreated
                LastSeen  = ($_.Group | Sort-Object TimeCreated -Descending | Select-Object -First 1).TimeCreated
            }
        }

    $lines = New-Object System.Collections.Generic.List[string]
    $lines.Add("出口公网IP: " + ($(if ($PublicIp) { $PublicIp } else { 'unavailable' })))
    $lines.Add("今日成功登录IP:")

    if (-not $summary -or $summary.Count -eq 0) {
        $lines.Add("  none")
        return ($lines -join "`n")
    }

    foreach ($item in $summary) {
        $firstSeen = $item.FirstSeen.ToString('yyyy-MM-dd HH:mm:ss')
        $lastSeen = $item.LastSeen.ToString('yyyy-MM-dd HH:mm:ss')
        $lines.Add(("  {0} ({1} times, first {2}, last {3})" -f $item.IpAddress, $item.Count, $firstSeen, $lastSeen))
    }

    return ($lines -join "`n")
}

function Send-FeishuText {
    param(
        [Parameter(Mandatory = $true)]
        [string]$WebhookUrl,
        [Parameter(Mandatory = $true)]
        [string]$Text
    )

    $payload = @{
        msg_type = 'text'
        content  = @{
            text = $Text
        }
    } | ConvertTo-Json -Depth 6

    Invoke-RestMethod -Method Post -Uri $WebhookUrl -ContentType 'application/json; charset=utf-8' -Body $payload -ErrorAction Stop | Out-Null
}

function Register-DailyTask {
    param(
        [Parameter(Mandatory = $true)]
        [string]$ScriptPath,
        [Parameter(Mandatory = $true)]
        [string]$WebhookUrl,
        [Parameter(Mandatory = $true)]
        [string]$TaskName,
        [Parameter(Mandatory = $true)]
        [string]$TaskTime
    )

    $action = New-ScheduledTaskAction -Execute 'powershell.exe' -Argument ('-NoProfile -ExecutionPolicy Bypass -WindowStyle Hidden -File "{0}" -Today -WebhookUrl "{1}"' -f $ScriptPath, $WebhookUrl)
    $trigger = New-ScheduledTaskTrigger -Daily -At $TaskTime
    $settings = New-ScheduledTaskSettingsSet -StartWhenAvailable -AllowStartIfOnBatteries -DontStopIfGoingOnBatteries -MultipleInstances IgnoreNew

    Register-ScheduledTask -TaskName $TaskName -Action $action -Trigger $trigger -Settings $settings -Description 'Send daily successful login IP summary to Feishu at 18:00' -Force -ErrorAction Stop | Out-Null
    Write-Host "Scheduled task installed: $TaskName at $TaskTime"
}

if ($InstallTask) {
    if ([string]::IsNullOrWhiteSpace($WebhookUrl)) {
        Write-Error 'WebhookUrl is required when installing the scheduled task.'
        exit 1
    }

    $scriptPath = if ($PSCommandPath) { $PSCommandPath } else { $MyInvocation.MyCommand.Path }
    if ([string]::IsNullOrWhiteSpace($scriptPath)) {
        Write-Error 'Cannot determine script path for scheduled task registration.'
        exit 1
    }

    try {
        Register-DailyTask -ScriptPath $scriptPath -WebhookUrl $WebhookUrl -TaskName $TaskName -TaskTime $TaskTime
    }
    catch {
        if ($_.Exception.Message -match '0x80070005|拒绝访问|Access is denied') {
            Write-Error 'Failed to register scheduled task. Please run PowerShell as Administrator.'
        }
        Write-Error "Failed to register scheduled task. $($_.Exception.Message)"
        exit 1
    }

    return
}

try {
    $records = @(Get-SuccessfulLoginRecords -FilterHashtable $filter -AllowLocal:$IncludeLocal)
}
catch {
    Write-Error "Failed to read Security log. Try running PowerShell as Administrator. $($_.Exception.Message)"
    exit 1
}

$publicIp = Get-PublicIpAddress
$reportText = Build-SummaryText -PublicIp $publicIp -Records $records

if ($WebhookUrl) {
    try {
        Send-FeishuText -WebhookUrl $WebhookUrl -Text $reportText
        Write-Host 'Feishu message sent.'
    }
    catch {
        Write-Error "Failed to send Feishu message. $($_.Exception.Message)"
        exit 1
    }
}

if ($OutCsv) {
    $records | Export-Csv -Path $OutCsv -NoTypeInformation -Encoding UTF8
    Write-Host "Saved: $OutCsv"
}

if ($Unique) {
    $output = $records |
        Group-Object IpAddress |
        Sort-Object Count -Descending |
        Select-Object @{
            Name = 'IpAddress'
            Expression = { $_.Name }
        }, Count, @{
            Name = 'FirstSeen'
            Expression = { ($_.Group | Sort-Object TimeCreated | Select-Object -First 1).TimeCreated }
        }, @{
            Name = 'LastSeen'
            Expression = { ($_.Group | Sort-Object TimeCreated -Descending | Select-Object -First 1).TimeCreated }
        }
}
elseif ($Detailed) {
    $output = $records | Sort-Object TimeCreated -Descending
}
elseif ($Today -or $WebhookUrl) {
    $output = $reportText
}
else {
    $output = $records |
        Select-Object -ExpandProperty IpAddress -Unique |
        Sort-Object
}

if (-not $OutCsv -and -not $WebhookUrl) {
    $output
}
