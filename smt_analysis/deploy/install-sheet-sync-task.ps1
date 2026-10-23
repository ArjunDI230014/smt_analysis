param(
    [string]$TaskName = 'Summit Analytics Google Sheets Sync'
)
$ErrorActionPreference = 'Stop'
$syncScript = Join-Path $PSScriptRoot 'sync-sheets.ps1'
$action = New-ScheduledTaskAction -Execute 'powershell.exe' -Argument ('-NoProfile -NonInteractive -WindowStyle Hidden -ExecutionPolicy Bypass -File "' + $syncScript + '"') -WorkingDirectory (Split-Path -Parent $PSScriptRoot)
$trigger = New-ScheduledTaskTrigger -Once -At (Get-Date).AddMinutes(1) -RepetitionInterval (New-TimeSpan -Minutes 1)
$settings = New-ScheduledTaskSettingsSet -MultipleInstances IgnoreNew -StartWhenAvailable -ExecutionTimeLimit (New-TimeSpan -Minutes 6) -AllowStartIfOnBatteries -DontStopIfGoingOnBatteries
$principal = New-ScheduledTaskPrincipal -UserId ([System.Security.Principal.WindowsIdentity]::GetCurrent().Name) -LogonType Interactive -RunLevel Limited
Register-ScheduledTask -TaskName $TaskName -Action $action -Trigger $trigger -Settings $settings -Principal $principal -Description 'Checks the configured private Google Sheet when due. Requires the server computer to be awake and this Windows user to be signed in.' -Force | Out-Null
Write-Host ('Installed task: ' + $TaskName)
Write-Host 'The app controls the refresh interval. The task checks once a minute while this Windows user is signed in.'
