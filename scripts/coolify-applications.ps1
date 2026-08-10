#Requires -Version 5.1
<#
.SYNOPSIS
  Lists Coolify applications via API (Bearer token).

.DESCRIPTION
  Coolify does NOT authenticate the API with email/password — you need a token from
  the UI: Profile / Keys & Tokens → Create New Token (read-only is enough for status).

  Usage:
    $env:COOLIFY_API_TOKEN = '3|...'   # from Coolify UI
    $env:COOLIFY_API_BASE = 'https://coolify.revyome.com'   # optional
    .\scripts\coolify-applications.ps1
    .\scripts\coolify-applications.ps1 -FilterHost improve.ypsilon.dev
#>
param(
    [string] $BaseUrl = $env:COOLIFY_API_BASE,
    [string] $Token = $env:COOLIFY_API_TOKEN,
    [string] $FilterHost = ''
)

function Import-CoolifyFromEnvE2e {
    param([string] $EnvPath)
    if (-not (Test-Path -LiteralPath $EnvPath)) { return }
    Get-Content -LiteralPath $EnvPath -Encoding UTF8 | ForEach-Object {
        $line = $_.Trim()
        if ($line -eq '' -or $line.StartsWith('#')) { return }
        if ($line -notmatch '^([A-Za-z_][A-Za-z0-9_]*)=(.*)$') { return }
        $key = $Matches[1]
        if ($key -notin @('COOLIFY_API_TOKEN', 'COOLIFY_API_BASE')) { return }
        if ([string]::IsNullOrEmpty([Environment]::GetEnvironmentVariable($key, 'Process'))) {
            $val = $Matches[2].Trim()
            if ($val.Length -ge 2 -and $val.StartsWith('"') -and $val.EndsWith('"')) {
                $val = $val.Substring(1, $val.Length - 2)
            }
            [Environment]::SetEnvironmentVariable($key, $val, 'Process')
        }
    }
}

$repoRoot = (Resolve-Path (Join-Path $PSScriptRoot '..')).Path
if (-not $Token -or -not $env:COOLIFY_API_BASE) {
    Import-CoolifyFromEnvE2e -EnvPath (Join-Path $repoRoot '.env.e2e')
}
if (-not $BaseUrl) { $BaseUrl = $env:COOLIFY_API_BASE }
if (-not $Token) { $Token = $env:COOLIFY_API_TOKEN }

if (-not $BaseUrl) { $BaseUrl = 'https://coolify.revyome.com' }
$BaseUrl = $BaseUrl.TrimEnd('/')

if (-not $Token) {
    Write-Error 'Set COOLIFY_API_TOKEN (Coolify UI: Keys and Tokens - API token). Web login password does not work for /api/v1.'
    exit 1
}

$uri = "$BaseUrl/api/v1/applications"
try {
    $resp = Invoke-RestMethod -Uri $uri -Headers @{ Authorization = "Bearer $Token" } -Method Get -TimeoutSec 60
} catch {
    Write-Error "API request failed: $_"
    exit 1
}

if (-not $resp) {
    Write-Host 'Empty response.'
    exit 0
}

# API may return array or wrapped object
$apps = @($resp)
if ($resp -is [System.Collections.IDictionary] -and $resp.data) {
    $apps = @($resp.data)
}

$rows = foreach ($a in $apps) {
    $fqdn = $a.fqdn
    if ($FilterHost -and $fqdn -notmatch [regex]::Escape($FilterHost)) { continue }

    [pscustomobject]@{
        name   = $a.name
        uuid   = $a.uuid
        status = $a.status
        fqdn   = $fqdn
    }
}

if ($rows) { $rows | Format-Table -AutoSize } else { Write-Host 'No rows (empty list or filter excluded all).' }

if ($FilterHost -and -not ($apps | Where-Object { $_.fqdn -match [regex]::Escape($FilterHost) })) {
    Write-Host "No application matched FQDN filter: $FilterHost (check team scope of token)."
}
