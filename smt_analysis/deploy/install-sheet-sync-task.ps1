param(
    [string]$TaskName = 'Summit Analytics Google Sheets Sync'
)
$ErrorActionPreference = 'Stop'
$projectDirectory = Split-Path -Parent $PSScriptRoot
$phpExecutable = (Get-Command php -ErrorAction Stop).Source
$phpWindowless = Join-Path (Split-Path -Parent $phpExecutable) 'php-win.exe'
if (-not (Test-Path -LiteralPath $phpWindowless -PathType Leaf)) {
    throw 'php-win.exe was not found beside php.exe. Install the Windows PHP CLI package to run syncs without a console window.'
}
$phpArguments = '-d memory_limit=768M -d max_execution_time=300'
if ((& $phpExecutable -m) -notcontains 'sqlite3') {
    $phpArguments += ' -d extension=sqlite3'
}
# php-win.exe is the CLI executable without a console; hiding PowerShell can still flash a window.
$phpArguments += ' "' + (Join-Path $projectDirectory 'spark') + '" csat:sync --no-header'
$action = New-ScheduledTaskAction -Execute $phpWindowless -Argument $phpArguments -WorkingDirectory $projectDirectory
$trigger = New-ScheduledTaskTrigger -Once -At (Get-Date).AddMinutes(1) -RepetitionInterval (New-TimeSpan -Minutes 1)
$settings = New-ScheduledTaskSettingsSet -MultipleInstances IgnoreNew -StartWhenAvailable -ExecutionTimeLimit (New-TimeSpan -Minutes 6) -AllowStartIfOnBatteries -DontStopIfGoingOnBatteries
$principal = New-ScheduledTaskPrincipal -UserId ([System.Security.Principal.WindowsIdentity]::GetCurrent().Name) -LogonType Interactive -RunLevel Limited
Register-ScheduledTask -TaskName $TaskName -Action $action -Trigger $trigger -Settings $settings -Principal $principal -Description 'Checks the configured private Google Sheet when due. Requires the server computer to be awake and this Windows user to be signed in.' -Force | Out-Null
Write-Host ('Installed task: ' + $TaskName)
Write-Host 'The app controls the refresh interval. The task checks once a minute without a console window while this Windows user is signed in.'
