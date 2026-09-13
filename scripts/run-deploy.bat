@echo off
REM On-demand deploy entry point for Windows Task Scheduler -- fired via
REM `schtasks /run` from App\Filament\Admin\Pages\UpstreamUpdates's
REM "Run update now" action (App\Services\DeployTrigger), never on a timer.
REM See docs/DEPLOYMENT.md "Web-triggered updates".
REM
REM Passes -ProjectRoot explicitly (%CD%, resolved by cmd.exe after the `cd`
REM below) rather than relying on deploy.ps1's own $PSScriptRoot-based
REM default -- that PowerShell automatic variable comes back empty under
REM some non-interactive, no-console hosts (confirmed under a real
REM Task-Scheduler-launched run), where cmd.exe's own path resolution has no
REM such ambiguity.

cd /d "%~dp0.."
powershell.exe -ExecutionPolicy Bypass -File "%~dp0deploy.ps1" -ProjectRoot "%CD%" >> storage\logs\deploy.log 2>&1
