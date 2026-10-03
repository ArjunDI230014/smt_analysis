$ErrorActionPreference = 'Stop'
if (-not (Get-NetFirewallRule -DisplayName 'SMT Analytics LAN' -ErrorAction SilentlyContinue)) {
    New-NetFirewallRule -DisplayName 'SMT Analytics LAN' -Direction Inbound -Action Allow -Protocol TCP -LocalPort 80 -Program 'C:\xampp\apache\bin\httpd.exe' -RemoteAddress LocalSubnet -Profile Private | Out-Null
}
Get-NetConnectionProfile | Select-Object InterfaceAlias,NetworkCategory | Out-File -LiteralPath (Join-Path $PSScriptRoot 'network-status.txt')
