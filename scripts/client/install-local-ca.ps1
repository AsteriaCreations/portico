#Requires -Version 5.1
<#
.SYNOPSIS
    Trust a local root CA certificate (e.g. from mkcert) on this Windows PC,
    so its browser stops warning about a Portico server's HTTPS cert.

.DESCRIPTION
    For a club running Portico's check-in server over a self-issued local CA
    (mkcert or a small internal CA) rather than a public CA, every staff
    device that reaches it over HTTPS needs that CA's root certificate
    trusted -- otherwise every browser shows a certificate warning.

    This script imports one root CA cert into the Local Machine "Trusted Root
    Certification Authorities" store (not the current user's store), so it
    covers every user profile and every Chromium-based browser (Chrome, Edge)
    on a shared/kiosk PC in one run. Run it once per PC.

    Firefox does NOT use the Windows certificate store by default -- it ships
    its own. Either accept a one-time warning there, or turn on
    "Enterprise Roots" support in about:config
    (security.enterprise_roots.enabled = true), which makes Firefox read the
    Windows store too. mkcert's own -install also configures Firefox's NSS
    store directly, but only on the machine mkcert was run on -- it has no
    effect here, since this script only distributes the CA cert mkcert
    already generated elsewhere.

    This script does not generate a CA or a certificate -- see mkcert
    (https://github.com/FiloSottile/mkcert) for that, run once on whichever
    machine issues the server's certificate. Copy just its root CA
    (`mkcert -CAROOT` prints the folder; the file is rootCA.pem) to each
    staff PC and run this script against it.

.PARAMETER CaCertPath
    Path to the CA's root certificate (PEM or DER). Default: rootCA.pem next
    to this script.

.PARAMETER TestUrl
    Optional. After importing, fetch this URL and confirm the certificate now
    validates (e.g. https://checkin.example.lan/admin/login) -- catches "wrong
    CA" or "cert doesn't cover this hostname" immediately instead of a staff
    member hitting a browser warning later.

.EXAMPLE
    # Copy rootCA.pem next to this script on the staff PC, then from an
    # elevated PowerShell:
    Set-ExecutionPolicy -Scope Process -ExecutionPolicy Bypass
    .\install-local-ca.ps1 -TestUrl 'https://checkin.example.lan/admin/login'
#>
[CmdletBinding(SupportsShouldProcess = $true)]
param(
    [string]$CaCertPath = (Join-Path $PSScriptRoot 'rootCA.pem'),
    [string]$TestUrl
)

$ErrorActionPreference = 'Stop'
Set-StrictMode -Version Latest

function Write-Step { param([string]$Message) Write-Host "==> $Message" -ForegroundColor Cyan }
function Write-Info { param([string]$Message) Write-Host "    $Message" -ForegroundColor Gray }

$principal = New-Object Security.Principal.WindowsPrincipal(
    [Security.Principal.WindowsIdentity]::GetCurrent())
if (-not $principal.IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)) {
    throw 'Run this from an elevated PowerShell (Administrator) -- importing into the Local Machine store needs it.'
}

if (-not (Test-Path $CaCertPath)) {
    throw "CA certificate not found: $CaCertPath. Copy the CA's rootCA.pem (find it on the issuing machine with `mkcert -CAROOT`) next to this script, or pass -CaCertPath."
}

Write-Step 'Reading certificate'
$cert = New-Object Security.Cryptography.X509Certificates.X509Certificate2($CaCertPath)
Write-Info "Subject    : $($cert.Subject)"
Write-Info "Thumbprint : $($cert.Thumbprint)"
Write-Info "Valid      : $($cert.NotBefore) - $($cert.NotAfter)"

Write-Step 'Importing into Local Machine \ Trusted Root Certification Authorities'
$store = New-Object Security.Cryptography.X509Certificates.X509Store('Root', 'LocalMachine')
$store.Open('ReadWrite')
try {
    $existing = $store.Certificates | Where-Object { $_.Thumbprint -eq $cert.Thumbprint }
    if ($existing) {
        Write-Info "already trusted (thumbprint $($cert.Thumbprint) present)"
    } elseif ($PSCmdlet.ShouldProcess('LocalMachine\Root', "Add $($cert.Subject)")) {
        $store.Add($cert)
        Write-Info 'added'
    }
} finally {
    $store.Close()
}

if ($TestUrl) {
    Write-Step "Verifying against $TestUrl"
    if ($WhatIfPreference) {
        Write-Info 'skipped under -WhatIf'
    } else {
        try {
            $response = Invoke-WebRequest -Uri $TestUrl -UseBasicParsing -TimeoutSec 15
            Write-Info "OK -- HTTP $($response.StatusCode), certificate validated"
        } catch {
            throw "Request to $TestUrl failed even after importing the CA: $($_.Exception.Message). Confirm this is the right rootCA.pem for that server, and that the server's leaf certificate actually covers the hostname/IP in the URL."
        }
    }
}

Write-Host ''
Write-Host 'Done. If Firefox is used on this PC, see this script''s header comment' -ForegroundColor Green
Write-Host 'about security.enterprise_roots.enabled -- Firefox has its own cert store.'
