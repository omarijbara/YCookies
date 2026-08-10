#Requires -Version 5.1
<#
.SYNOPSIS
  Triggers a Coolify application deployment via API.

.DESCRIPTION
  Uses a Bearer token and App UUID to trigger a redeploy in Coolify.
  Can wait for the deployment to finish (optional).

  Usage:
    $env:COOLIFY_API_TOKEN = '3|...'
    .\scripts\deploy-coolify.ps1 -AppUuid 'jws0sgooccs0488ckkgc0gcs'
#>
param(
    [Parameter(Mandatory=$true)]
    [string] $AppUuid,
    [string] $BaseUrl = $env:COOLIFY_API_BASE,
    [string] $Token = $env:COOLIFY_API_TOKEN,
    [switch] $Wait = $false
)

if (-not $BaseUrl) { $BaseUrl = 'https://coolify.revyome.com' }
$BaseUrl = $BaseUrl.TrimEnd('/')

if (-not $Token) {
    Write-Error 'Set COOLIFY_API_TOKEN (Coolify UI: Keys and Tokens - API token).'
    exit 1
}

$uri = "$BaseUrl/api/v1/deploy?uuid=$AppUuid&force=true"
Write-Host "Triggering deploy for App: $AppUuid at $BaseUrl ..."

try {
    $resp = Invoke-RestMethod -Uri $uri -Headers @{ Authorization = "Bearer $Token" } -Method Get -TimeoutSec 60
    Write-Host "Deployment queued successfully."
    $resp | ConvertTo-Json | Write-Host
} catch {
    $err = $_.Exception.Message
    if ($_.Exception.Response) {
        $body = New-Object System.IO.StreamReader($_.Exception.Response.GetResponseStream())
        $err = $body.ReadToEnd()
    }
    Write-Error "Deployment trigger failed: $err"
    exit 1
}

if ($Wait) {
    Write-Host "Waiting for deployment to complete (polling not fully implemented in this MVP)..."
    # Simple polling could go here via /api/v1/applications/$AppUuid/deployments
}
