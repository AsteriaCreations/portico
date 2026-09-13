#Requires -Version 5.1
<#
.SYNOPSIS
    Pull down and apply an updated `main` on a Portico production box.

.DESCRIPTION
    Automates the box-side half of docs/DEPLOYMENT.md, "Updating" (the steps
    that used to be copy-pasted by hand one at a time):

      - stop the Apache service (if present)
      - require a clean working tree, then `git pull --ff-only`
      - composer install --no-dev --optimize-autoloader
      - npm install && npm run build (unless -SkipNpm)
      - php artisan migrate --force (or migrate:fresh --seed with -MigrateFresh,
        for a pre-launch box with no real data yet)
      - storage:link / config:clear / optimize
      - restart the Apache service

    Stops on the first failure ($ErrorActionPreference = 'Stop') and always
    tries to restart Apache in a `finally` block, even on failure, so a box
    is never left down because a later step errored.

    Also reports its own outcome via `php artisan deploy:record-result` (skipped
    under -WhatIf) -- this is what lets App\Filament\Admin\Pages\UpstreamUpdates
    show whether a web-triggered run (see docs/DEPLOYMENT.md §7, "Web-triggered
    updates") actually succeeded, since that page has no other way to know once
    this script detaches from the request that fired it.

    Deliberately NOT automated: reading the sync-log entry for what actually
    changed (new .env keys, whether this update needs a migration/npm build at
    all), and the post-deploy smoke test. Both still need a human. Run this
    from the box itself (or an RDP session to it), in the project root or with
    -ProjectRoot pointed at it.

.PARAMETER ProjectRoot
    Path to the Portico checkout. Default: the parent of this script's
    directory (i.e. run it in place from scripts\deploy.ps1 and it finds its
    own repo root).

.PARAMETER ServiceName
    Windows service name to stop/restart. Default "Apache-Portico" (matches
    setup-apache.ps1's default), unless the PORTICO_APACHE_SERVICE environment
    variable is set, in which case that wins -- set it once as a machine-level
    variable (`setx PORTICO_APACHE_SERVICE YourService /M`) on a box whose
    service isn't named the default, and both an interactive run and the
    Task-Scheduler-triggered "Web-triggered updates" path (which never passes
    -ServiceName at all -- see scripts\run-deploy.bat) pick it up automatically.
    Pass -ServiceName explicitly to override either for one run. Pass
    -ServiceName '' (empty string) to skip the service stop/restart entirely
    (e.g. a non-Windows / non-Apache deployment) -- useful with -WhatIf too,
    since a real service restart is not simulated.

.PARAMETER MigrateFresh
    Run `migrate:fresh --seed --force` instead of `migrate --force`. Only
    correct for a pre-launch box with no real check-ins yet -- it destroys
    existing data. Default is the safe forward-only `migrate --force`.

.PARAMETER SkipNpm
    Skip `npm install`/`npm run build`. Use when the sync log says the update
    touched no front-end asset (most updates don't) and Node isn't set up /
    you'd rather not wait on it.

.PARAMETER SkipMigrate
    Skip the `php artisan migrate` step entirely. Use when the sync log
    confirms no schema change.

.EXAMPLE
    # Ordinary update, from an elevated PowerShell in the project root:
    .\scripts\deploy.ps1

.EXAMPLE
    # This update's sync-log entry says "no migration, no npm":
    .\scripts\deploy.ps1 -SkipMigrate -SkipNpm

.EXAMPLE
    # Pre-launch box, rebuilding the schema from scratch:
    .\scripts\deploy.ps1 -MigrateFresh

.EXAMPLE
    # See what would happen without changing anything:
    .\scripts\deploy.ps1 -WhatIf
#>
[CmdletBinding(SupportsShouldProcess = $true)]
param(
    [string]$ProjectRoot = (Split-Path -Path $PSScriptRoot -Parent),
    [string]$ServiceName = $(if ($env:PORTICO_APACHE_SERVICE) { $env:PORTICO_APACHE_SERVICE } else { 'Apache-Portico' }),
    [switch]$MigrateFresh,
    [switch]$SkipNpm,
    [switch]$SkipMigrate
)

$ErrorActionPreference = 'Stop'
Set-StrictMode -Version Latest

function Write-Step { param([string]$Message) Write-Host "==> $Message" -ForegroundColor Cyan }
function Write-Skip { param([string]$Message) Write-Host "    (skipped) $Message" -ForegroundColor DarkGray }
function Write-Info { param([string]$Message) Write-Host "    $Message" -ForegroundColor Gray }

if (-not (Test-Path $ProjectRoot)) {
    throw "-ProjectRoot not found: $ProjectRoot"
}
Push-Location $ProjectRoot
try {
    if (-not (Test-Path 'artisan')) {
        throw "$ProjectRoot doesn't look like a Portico checkout (no 'artisan' file). Pass -ProjectRoot."
    }

    $service = $null
    if ($ServiceName) {
        $service = Get-Service -Name $ServiceName -ErrorAction SilentlyContinue
        if (-not $service) {
            Write-Info "No service named '$ServiceName' found -- continuing without stopping/restarting one. Pass -ServiceName '' to silence this, or the right name."
        }
    }

    # --- 1. stop the web server -------------------------------------------------

    Write-Step "Stop '$ServiceName'"
    if ($service -and $service.Status -eq 'Running') {
        if ($PSCmdlet.ShouldProcess($ServiceName, 'Stop-Service')) {
            Stop-Service -Name $ServiceName
            Write-Info 'stopped'
        }
    } else {
        Write-Skip $(if ($service) { "already $($service.Status)" } else { 'no such service' })
    }

    try {
        # Reports the outcome of steps 2-5 via `php artisan deploy:record-result`
        # so App\Filament\Admin\Pages\UpstreamUpdates can show it -- this is the
        # only thing that lets a web-triggered run (see docs/DEPLOYMENT.md §7,
        # "Web-triggered updates") report back, since it's fully detached from
        # the request that fired it. Skipped entirely under -WhatIf: recording a
        # result is itself a side effect, and nothing real happened to report.
        try {
            # --- 2. pull -----------------------------------------------------------

            Write-Step 'git pull --ff-only'
            # --untracked-files=no deliberately: this check exists to catch an
            # uncommitted CHANGE to a tracked file (the real "--ff-only fails or
            # silently mixes local changes into what ships" risk) -- a stray
            # untracked file (an npm-generated package-lock.json, a manually
            # staged import spreadsheet under storage/) can't do either, since
            # pulling never touches something git doesn't already track.
            $dirty = git status --porcelain --untracked-files=no
            if ($dirty) {
                throw "Working tree has uncommitted changes to tracked files:`n$dirty`nCommit, stash, or discard before deploying -- a dirty tree can make --ff-only fail or, worse, silently mix local changes into what ships."
            }
            if ($PSCmdlet.ShouldProcess('origin/main', 'git pull --ff-only')) {
                git pull --ff-only origin main
                if ($LASTEXITCODE -ne 0) {
                    throw "git pull --ff-only failed (exit $LASTEXITCODE). If main diverged (e.g. someone committed on the box), resolve that by hand -- this script won't force or rebase for you."
                }
            }
            Write-Info "now at $(git rev-parse --short HEAD) ($(git log -1 --format=%s))"

            # --- 3. dependencies ---------------------------------------------------

            Write-Step 'composer install --no-dev --optimize-autoloader'
            if ($PSCmdlet.ShouldProcess('composer.lock', 'composer install')) {
                composer install --no-dev --optimize-autoloader
                if ($LASTEXITCODE -ne 0) { throw "composer install failed (exit $LASTEXITCODE)." }
            }

            Write-Step 'npm install && npm run build'
            if ($SkipNpm) {
                Write-Skip '-SkipNpm'
            } elseif ($PSCmdlet.ShouldProcess('public/build', 'npm install && npm run build')) {
                npm install
                if ($LASTEXITCODE -ne 0) { throw "npm install failed (exit $LASTEXITCODE)." }
                npm run build
                if ($LASTEXITCODE -ne 0) { throw "npm run build failed (exit $LASTEXITCODE)." }
            }

            # --- 4. database ---------------------------------------------------------

            Write-Step 'database migration'
            if ($SkipMigrate) {
                Write-Skip '-SkipMigrate'
            } elseif ($MigrateFresh) {
                Write-Host '    -MigrateFresh: this destroys existing data. Only correct pre-launch.' -ForegroundColor Yellow
                if ($PSCmdlet.ShouldProcess('database', 'migrate:fresh --seed --force')) {
                    php artisan migrate:fresh --seed --force
                    if ($LASTEXITCODE -ne 0) { throw "migrate:fresh failed (exit $LASTEXITCODE)." }
                }
            } elseif ($PSCmdlet.ShouldProcess('database', 'migrate --force')) {
                php artisan migrate --force
                if ($LASTEXITCODE -ne 0) { throw "migrate failed (exit $LASTEXITCODE)." }
            }

            # --- 5. caches -----------------------------------------------------------

            Write-Step 'storage:link / config:clear / optimize'
            if ($PSCmdlet.ShouldProcess($ProjectRoot, 'artisan storage:link / config:clear / optimize')) {
                php artisan storage:link
                php artisan config:clear
                php artisan optimize
                if ($LASTEXITCODE -ne 0) { throw "php artisan optimize failed (exit $LASTEXITCODE)." }
            }

            if (-not $WhatIfPreference) {
                php artisan deploy:record-result
            }
        } catch {
            if (-not $WhatIfPreference) {
                php artisan deploy:record-result --failed --message $_.Exception.Message
            }
            throw
        }
    } finally {
        # --- 6. restart the web server, even on failure above -----------------------

        Write-Step "Start '$ServiceName'"
        if ($service) {
            if ($PSCmdlet.ShouldProcess($ServiceName, 'Start-Service')) {
                Start-Service -Name $ServiceName
                Start-Sleep -Seconds 2
                $service = Get-Service -Name $ServiceName
                if ($service.Status -ne 'Running') {
                    Write-Host "    '$ServiceName' is '$($service.Status)', not 'Running'. Check its error log." -ForegroundColor Red
                } else {
                    Write-Info 'running'
                }
            }
        } else {
            Write-Skip 'no such service'
        }
    }
} finally {
    Pop-Location
}

Write-Host ''
Write-Host 'Deploy finished. This script does NOT do these -- do them now:' -ForegroundColor Green
Write-Host '  - Read this update''s sync-log entry (docs/DEPLOYMENT_RUNBOOK.md or CHANGELOG.md) for anything it calls out by hand.'
Write-Host '  - Smoke test: load the app from a second device, confirm it looks and behaves right.'
Write-Host '  - If -MigrateFresh was used, recreate any non-seeded staff logins -- they were wiped.'
