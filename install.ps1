$Repository = if ($env:WLIFF_REPOSITORY) { $env:WLIFF_REPOSITORY } else { '52lkj/windows-login-ip-feishu-report' }
$Branch = if ($env:WLIFF_BRANCH) { $env:WLIFF_BRANCH } else { 'main' }
$WebhookUrl = $env:FEISHU_WEBHOOK_URL
$BackendUrl = $env:WLIFF_BACKEND_URL
$IngestToken = $env:WLIFF_INGEST_TOKEN
$ServerKey = $env:WLIFF_SERVER_KEY
$ArchiveUrl = $env:WLIFF_ARCHIVE_URL
$InstallDir = if ($env:WLIFF_INSTALL_DIR) { $env:WLIFF_INSTALL_DIR } else { Join-Path $env:ProgramData 'windows-login-ip-feishu-report' }

if ([string]::IsNullOrWhiteSpace($WebhookUrl) -and [string]::IsNullOrWhiteSpace($BackendUrl)) {
    $WebhookUrl = Read-Host 'Feishu webhook URL'
}

if (-not [string]::IsNullOrWhiteSpace($BackendUrl) -and [string]::IsNullOrWhiteSpace($IngestToken)) {
    $IngestToken = Read-Host 'Backend ingest token'
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

$installArgs = @('-NoProfile', '-ExecutionPolicy', 'Bypass', '-File', $installedScriptPath, '-InstallTask')
if (-not [string]::IsNullOrWhiteSpace($WebhookUrl)) {
    $installArgs += @('-WebhookUrl', $WebhookUrl)
}
if (-not [string]::IsNullOrWhiteSpace($BackendUrl)) {
    $installArgs += @('-BackendUrl', $BackendUrl, '-IngestToken', $IngestToken)
}
if (-not [string]::IsNullOrWhiteSpace($ServerKey)) {
    $installArgs += @('-ServerKey', $ServerKey)
}

& powershell.exe @installArgs
if ($LASTEXITCODE -ne 0) {
    throw 'Installer failed. Please rerun PowerShell as Administrator.'
}

Write-Host "Installed script: $installedScriptPath"

Write-Host 'Sending test report...'
$testArgs = @('-NoProfile', '-ExecutionPolicy', 'Bypass', '-File', $installedScriptPath, '-Today')
if (-not [string]::IsNullOrWhiteSpace($WebhookUrl)) {
    $testArgs += @('-WebhookUrl', $WebhookUrl)
}
if (-not [string]::IsNullOrWhiteSpace($BackendUrl)) {
    $testArgs += @('-BackendUrl', $BackendUrl, '-IngestToken', $IngestToken)
}
if (-not [string]::IsNullOrWhiteSpace($ServerKey)) {
    $testArgs += @('-ServerKey', $ServerKey)
}

& powershell.exe @testArgs
if ($LASTEXITCODE -ne 0) {
    throw 'Test report failed. Please check the webhook/backend URL, token, and network access.'
}

Write-Host 'Test report sent.'
