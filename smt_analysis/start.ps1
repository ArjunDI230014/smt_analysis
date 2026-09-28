$ErrorActionPreference = 'Stop'
Set-Location -LiteralPath $PSScriptRoot
$phpCommand = (Get-Command php -ErrorAction Stop).Source
$phpOptions = @()
$phpModules = & $phpCommand -m
if ($phpModules -notcontains 'sqlite3') {
    $phpOptions += @('-d', 'extension=sqlite3')
}
$phpOptions += @('-d', 'memory_limit=512M', '-d', 'upload_max_filesize=20M', '-d', 'post_max_size=22M', '-d', 'max_execution_time=120')
& $phpCommand @phpOptions spark migrate
if ($LASTEXITCODE -ne 0) { throw 'Database migration failed.' }
& $phpCommand @phpOptions spark csat:user --if-empty
if ($LASTEXITCODE -ne 0) { throw 'Account setup failed.' }
Write-Host 'Dashboard: http://localhost:8080'
& $phpCommand @phpOptions -S localhost:8080 -t public vendor/codeigniter4/framework/system/rewrite.php
