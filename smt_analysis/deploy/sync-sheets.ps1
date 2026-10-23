$ErrorActionPreference = 'Stop'
$projectDirectory = Split-Path -Parent $PSScriptRoot
$phpExecutable = (Get-Command php -ErrorAction Stop).Source
$phpOptions = @('-d', 'memory_limit=768M', '-d', 'max_execution_time=300')
if ((& $phpExecutable -m) -notcontains 'sqlite3') {
    $phpOptions += @('-d', 'extension=sqlite3')
}
Push-Location -LiteralPath $projectDirectory
try {
    & $phpExecutable @phpOptions spark csat:sync
    exit $LASTEXITCODE
} finally {
    Pop-Location
}
