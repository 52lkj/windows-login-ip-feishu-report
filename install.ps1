[CmdletBinding()]
param(
    [string]$Repository = '52lkj/windows-login-ip-feishu-report',
    [string]$Branch = 'main',
    [string]$WebhookUrl,
    [string]$ArchiveUrl,
    [string]$InstallDir = (Join-Path $env:ProgramData 'windows-login-ip-feishu-report')
)

if ([string]::IsNullOrWhiteSpace($WebhookUrl)) {
    $WebhookUrl = Read-Host 'Feishu webhook URL'
}

$ErrorActionPreference = 'Stop'
$tempRoot = Join-Path $env:TEMP ('windows-login-ip-feishu-report-' + [Guid]::NewGuid().ToString('N'))
$zipPath = Join-Path $tempRoot 'repo.zip'
$extractRoot = Join-Path $tempRoot 'extract'
$repoRoot = Join-Path $extractRoot 'windows-login-ip-feishu-report'

New-Item -ItemType Directory -Path $tempRoot, $extractRoot -Force | Out-Null

if ([string]::IsNullOrWhiteSpace($ArchiveUrl)) {
    $ArchiveUrl = "https://codeload.github.com/$Repository/zip/refs/heads/$Branch"
}

Write-Host "Downloading from: $ArchiveUrl"
Invoke-WebRequest -Uri $ArchiveUrl -OutFile $zipPath
Expand-Archive -LiteralPath $zipPath -DestinationPath $extractRoot -Force

$scriptPath = Join-Path $repoRoot 'Get-SuccessfulLoginIPs.ps1'
if (-not (Test-Path -LiteralPath $scriptPath)) {
    $candidate = Get-ChildItem -LiteralPath $extractRoot -Directory | Where-Object { Test-Path -LiteralPath (Join-Path $_.FullName 'Get-SuccessfulLoginIPs.ps1') } | Select-Object -First 1
    if ($candidate) {
        $scriptPath = Join-Path $candidate.FullName 'Get-SuccessfulLoginIPs.ps1'
    }
}

if (-not (Test-Path -LiteralPath $scriptPath)) {
    throw 'Downloaded package does not contain Get-SuccessfulLoginIPs.ps1.'
}

New-Item -ItemType Directory -Path $InstallDir -Force | Out-Null
$installedScriptPath = Join-Path $InstallDir 'Get-SuccessfulLoginIPs.ps1'
Copy-Item -LiteralPath $scriptPath -Destination $installedScriptPath -Force

& powershell.exe -NoProfile -ExecutionPolicy Bypass -File $installedScriptPath -InstallTask -WebhookUrl $WebhookUrl
if ($LASTEXITCODE -ne 0) {
    throw 'Installer failed. Please rerun PowerShell as Administrator.'
}

Write-Host "Installed script: $installedScriptPath"
