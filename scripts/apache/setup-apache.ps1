#Requires -Version 5.1
<#
.SYNOPSIS
    Install Apache + mod_php as the "Apache-Portico" Windows service for a
    Portico production LAN check-in server.

.DESCRIPTION
    Automates the Apache half of docs/DEPLOYMENT.md, "Serving the app on the
    LAN":

      - extract the Apache Lounge httpd zip to -ApacheDir
      - point Define SRVROOT at -ApacheDir, change "Listen 80" to the LAN IP,
        and add  Include conf/extra/portico.conf  to httpd.conf
      - copy scripts/apache/portico.conf into conf/extra/
      - httpd -t, then install + configure + start the service
      - open an inbound firewall rule for TCP 80 from the LAN CIDR

    The LAN IP, hostname, and CIDR are read from portico.conf (its
    Define SITE_IP / SITE_HOST / SITE_LAN lines), so that file stays the single
    source of truth -- this script never second-guesses it.

    Idempotent: safe to re-run. Skips work already done and reports it.

    NOT in scope (do these separately, per docs/DEPLOYMENT.md):
      - installing PHP 8.4 -- this script only checks it is present, at the
        path portico.conf's LoadModule line points to
      - editing .env / running artisan

.PARAMETER ApacheZip
    Path to a manually-downloaded Apache Lounge "httpd-2.4.x-Win64-VS17" zip.
    Required on the first run (when -ApacheDir does not exist yet). Get it from
    the VS17 line -- https://www.apachelounge.com/download/VS17/binaries/ -- NOT
    the main download page, which now serves VS18 builds that mod_php cannot pair
    with a VS17 PHP (they crash httpd on the first PHP request). The build
    triplet must match PHP: win64 / VS17 / x64. A known-good build:
    httpd-2.4.66-251206-Win64-VS17.zip.

.PARAMETER ApacheDir
    Where Apache lives / will be extracted. Default C:\Apache24. The zip's
    top-level folder must be "Apache24"; the script extracts to the parent.

.PARAMETER ServiceName
    Windows service name. Default "Apache-Portico".

.PARAMETER ServiceAccount
    Optional "DOMAIN\user" to run the service as (e.g. HOST\serviceaccount).
    Omit to leave the service as LocalSystem, which also works -- the service
    account's (OI)(CI)F grant on the app directory is what actually matters.
    When given, the password is prompted for unless -ServiceAccountPassword is
    supplied. "sc.exe config obj=" grants the account the "Log on as a service"
    right.

.PARAMETER ServiceAccountPassword
    Plaintext password for -ServiceAccount. Prefer the interactive prompt;
    passing this puts the password in your shell history and the process list.

.PARAMETER Force
    Re-extract Apache even if -ApacheDir already contains bin\httpd.exe.

.EXAMPLE
    # First run, from an elevated PowerShell (scripts are blocked by default):
    Set-ExecutionPolicy -Scope Process -ExecutionPolicy Bypass
    & 'C:\portico\scripts\apache\setup-apache.ps1' -ApacheZip 'C:\Users\you\Downloads\httpd-2.4.66-251206-Win64-VS17.zip' -ServiceAccount 'HOST\serviceaccount'

.EXAMPLE
    # Re-run after editing portico.conf -- repatches, revalidates, restarts:
    .\setup-apache.ps1
#>
[CmdletBinding(SupportsShouldProcess = $true)]
param(
    [string]$ApacheZip,
    [string]$ApacheDir = 'C:\Apache24',
    [string]$ServiceName = 'Apache-Portico',
    [string]$ServiceAccount,
    [string]$ServiceAccountPassword,
    [switch]$Force
)

$ErrorActionPreference = 'Stop'
Set-StrictMode -Version Latest

function Write-Step { param([string]$Message) Write-Host "==> $Message" -ForegroundColor Cyan }
function Write-Skip { param([string]$Message) Write-Host "    (already done) $Message" -ForegroundColor DarkGray }
function Write-Info { param([string]$Message) Write-Host "    $Message" -ForegroundColor Gray }

# --- preconditions ---------------------------------------------------------

$principal = New-Object Security.Principal.WindowsPrincipal(
    [Security.Principal.WindowsIdentity]::GetCurrent())
if (-not $principal.IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)) {
    throw 'Run this from an elevated PowerShell (Administrator). Installing a Windows service and a firewall rule both need it.'
}

$confSource = Join-Path $PSScriptRoot 'portico.conf'
if (-not (Test-Path $confSource)) {
    throw "Cannot find portico.conf next to this script (looked in $PSScriptRoot)."
}
$confText = Get-Content -Path $confSource -Raw

# Pull the LAN facts straight out of the vhost file so there's one source of truth.
$defs = @{}
foreach ($line in ($confText -split "`r?`n")) {
    if ($line -match '^\s*Define\s+(SITE_[A-Z]+)\s+"([^"]+)"') { $defs[$Matches[1]] = $Matches[2] }
}
foreach ($key in 'SITE_IP', 'SITE_HOST', 'SITE_LAN') {
    if (-not $defs.ContainsKey($key)) { throw "portico.conf is missing its 'Define $key' line." }
}
$bindIp     = $defs['SITE_IP']
$serverName = $defs['SITE_HOST']
$lanCidr    = $defs['SITE_LAN']

if ($confText -match '(?m)^\s*LoadModule\s+php_module\s+"([^"]+)"') {
    $phpApacheDll = $Matches[1]
} else {
    throw "portico.conf has no 'LoadModule php_module' line to locate the PHP Apache SAPI."
}
if (-not (Test-Path $phpApacheDll)) {
    throw "PHP Apache SAPI not found at $phpApacheDll. Install the Thread Safe PHP 8.4 build to C:\php before running this."
}

Write-Info "LAN bind IP  : $bindIp"
Write-Info "Server name  : $serverName"
Write-Info "LAN CIDR     : $lanCidr"
Write-Info "PHP SAPI DLL : $phpApacheDll"
Write-Info "Apache dir   : $ApacheDir"
Write-Info "Service      : $ServiceName$(if ($ServiceAccount) { " (as $ServiceAccount)" } else { ' (LocalSystem)' })"

$httpd = Join-Path $ApacheDir 'bin\httpd.exe'

# --- 1. extract Apache ---------------------------------------------------------

Write-Step 'Apache binaries'
if ((Test-Path $httpd) -and -not $Force) {
    Write-Skip "$httpd exists (use -Force to re-extract)"
} else {
    if (-not $ApacheZip) {
        throw "-ApacheZip is required on the first run: $httpd does not exist yet."
    }
    if (-not (Test-Path $ApacheZip)) {
        throw "-ApacheZip not found: $ApacheZip"
    }
    $parent = Split-Path -Path $ApacheDir -Parent
    if ($PSCmdlet.ShouldProcess($ApacheDir, "Expand $ApacheZip")) {
        Expand-Archive -Path $ApacheZip -DestinationPath $parent -Force
        if (-not (Test-Path $httpd)) {
            throw "After extracting, $httpd is still missing. The zip's top-level folder is expected to be 'Apache24'; extract it by hand and re-run without -ApacheZip if its layout differs."
        }
        Write-Info "extracted to $ApacheDir"
    }
}

# --- 2. patch httpd.conf -----------------------------------------------------

Write-Step 'httpd.conf (server root / Listen / Include)'
$mainConf = Join-Path $ApacheDir 'conf\httpd.conf'
if (-not (Test-Path $mainConf)) { throw "Not found: $mainConf" }

$text = Get-Content -Path $mainConf -Raw
$original = $text
$srvRoot = ($ApacheDir -replace '\\', '/')
$listenLine = "Listen ${bindIp}:80"
$includeLine = 'Include conf/extra/portico.conf'

# Point the server root at where we actually extracted. Older Apache Lounge
# builds carry  Define SRVROOT "..."  + ${SRVROOT} everywhere; 2.4.66+ dropped
# the Define and bake a literal  ServerRoot "C:/Apache24-64"  (their new default
# dir) into httpd.conf and every path under it. Handle both: capture whatever
# root the stock file shipped with, rewrite the Define line if there is one,
# then swap any literal occurrences of the stock root for $srvRoot.
$stockRoot = $null
if ($text -match '(?m)^\s*Define\s+SRVROOT\s+"([^"]*)"') {
    $stockRoot = $Matches[1]
    $text = [regex]::Replace($text, '(?m)^\s*Define\s+SRVROOT\s+".*"', "Define SRVROOT `"$srvRoot`"")
} elseif ($text -match '(?m)^\s*ServerRoot\s+"([^"]*)"') {
    $stockRoot = $Matches[1]
} else {
    Write-Info "no 'Define SRVROOT' or 'ServerRoot' line found -- leaving as-is"
}
if ($stockRoot -and $stockRoot -ne $srvRoot) {
    foreach ($variant in @($stockRoot, $stockRoot.Replace('/', '\'))) {
        $text = $text.Replace($variant, $srvRoot)
    }
    Write-Info "server root  $stockRoot  ->  $srvRoot"
}

if ($text -match [regex]::Escape($listenLine)) {
    Write-Skip $listenLine
} elseif ($text -match '(?m)^\s*Listen\s+80\s*$') {
    $text = [regex]::Replace($text, '(?m)^\s*Listen\s+80\s*$', $listenLine)
    Write-Info "Listen 80  ->  $listenLine"
} else {
    throw "Could not find a stock 'Listen 80' line in $mainConf to rewrite, and '$listenLine' is not present. Fix the Listen directive by hand and re-run."
}

if ($text -match [regex]::Escape($includeLine)) {
    Write-Skip $includeLine
} else {
    $text = $text.TrimEnd() + "`r`n`r`n# Portico -- vhost + mod_php, tracked at scripts/apache/portico.conf`r`n$includeLine`r`n"
    Write-Info "appended: $includeLine"
}

if ($text -ne $original) {
    if ($PSCmdlet.ShouldProcess($mainConf, 'Write patched httpd.conf')) {
        $backup = "$mainConf.portico-bak"
        if (-not (Test-Path $backup)) { Copy-Item $mainConf $backup; Write-Info "backed up original to $backup" }
        # ASCII, no BOM -- httpd will not parse a UTF-8 BOM on line 1.
        [IO.File]::WriteAllText($mainConf, $text, (New-Object Text.ASCIIEncoding))
    }
} else {
    Write-Skip 'httpd.conf already patched'
}

# --- 3. drop in the vhost file ---------------------------------------------

Write-Step 'conf\extra\portico.conf'
$confDest = Join-Path $ApacheDir 'conf\extra\portico.conf'
$destText = if (Test-Path $confDest) { Get-Content -Path $confDest -Raw } else { '' }
if ($destText -eq $confText) {
    Write-Skip "$confDest is up to date"
} elseif ($PSCmdlet.ShouldProcess($confDest, 'Copy portico.conf')) {
    Copy-Item -Path $confSource -Destination $confDest -Force
    Write-Info "copied from $confSource"
}

# --- 4. validate the config ----------------------------------------------------

Write-Step 'httpd -t (config syntax check)'
if ($WhatIfPreference) {
    Write-Info 'skipped under -WhatIf'
} else {
    & $httpd -t
    if ($LASTEXITCODE -ne 0) {
        throw "httpd -t failed (exit $LASTEXITCODE). Nothing else on the box is affected -- fix the reported line and re-run."
    }
}

# --- 5. install / configure the service ----------------------------------------

Write-Step "Windows service '$ServiceName'"
$svc = Get-Service -Name $ServiceName -ErrorAction SilentlyContinue
if ($svc) {
    Write-Skip "service exists"
} elseif ($PSCmdlet.ShouldProcess($ServiceName, 'httpd -k install')) {
    & $httpd -k install -n $ServiceName
    if ($LASTEXITCODE -ne 0) { throw "httpd -k install failed (exit $LASTEXITCODE)." }
    Write-Info 'service installed'
}

if ($PSCmdlet.ShouldProcess($ServiceName, 'sc config start= auto')) {
    & sc.exe config $ServiceName start= auto | Out-Null
}

if ($ServiceAccount -and -not $WhatIfPreference) {
    if (-not $ServiceAccountPassword) {
        $cred = Get-Credential -UserName $ServiceAccount -Message "Password for the '$ServiceName' service account"
        $ServiceAccountPassword = $cred.GetNetworkCredential().Password
    }
    if ($PSCmdlet.ShouldProcess($ServiceName, "sc config obj= $ServiceAccount")) {
        & sc.exe config $ServiceName obj= "$ServiceAccount" password= "$ServiceAccountPassword" | Out-Null
        if ($LASTEXITCODE -ne 0) { throw "sc config obj= failed (exit $LASTEXITCODE)." }
        Write-Info "service will log on as $ServiceAccount"
    }
    $ServiceAccountPassword = $null
}

# --- 6. firewall -------------------------------------------------------------

Write-Step 'inbound firewall rule (TCP 80 from the LAN)'
$ruleName = 'Portico HTTP (LAN)'
if (Get-NetFirewallRule -DisplayName $ruleName -ErrorAction SilentlyContinue) {
    Write-Skip "rule '$ruleName' exists"
} elseif ($PSCmdlet.ShouldProcess($ruleName, 'New-NetFirewallRule')) {
    New-NetFirewallRule -DisplayName $ruleName -Direction Inbound -Action Allow `
        -Protocol TCP -LocalPort 80 -RemoteAddress $lanCidr -Profile Any | Out-Null
    Write-Info "allow TCP 80 from $lanCidr"
}

# --- 7. (re)start ----------------------------------------------------------------

Write-Step "start / restart '$ServiceName'"
if ($WhatIfPreference) {
    Write-Info 'skipped under -WhatIf'
} elseif ($PSCmdlet.ShouldProcess($ServiceName, 'restart')) {
    $svc = Get-Service -Name $ServiceName
    if ($svc.Status -eq 'Running') { Restart-Service -Name $ServiceName -Force }
    else { Start-Service -Name $ServiceName }
    Start-Sleep -Seconds 2
    $svc = Get-Service -Name $ServiceName
    if ($svc.Status -ne 'Running') {
        throw "Service '$ServiceName' is '$($svc.Status)', not 'Running'. Check $ApacheDir\logs\error.log."
    }
    Write-Info 'running'
}

# --- next steps --------------------------------------------------------------

Write-Host ''
Write-Host 'Apache is up. Remaining steps (this script does NOT do them):' -ForegroundColor Green
Write-Host "  - .env -> APP_ENV=production / APP_DEBUG=false / APP_URL=http://$serverName"
Write-Host '    then:  php artisan config:clear  &&  php artisan optimize'
Write-Host "  - verify on the box:"
Write-Host "        curl -H `"Host: $serverName`" http://$bindIp"
Write-Host "    then load  http://$serverName  from a staff device on the staff Wi-Fi."
Write-Host '  - register the scheduled tasks (see docs/DEPLOYMENT.md).'
