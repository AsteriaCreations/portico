@echo off
REM On-demand deploy entry point for Windows Task Scheduler -- fired via
REM `schtasks /run` from App\Filament\Admin\Pages\UpstreamUpdates's
REM "Run update now" action (App\Services\DeployTrigger), never on a timer.
REM See docs/DEPLOYMENT.md "Web-triggered updates".

cd /d "%~dp0.."
powershell.exe -ExecutionPolicy Bypass -File scripts\deploy.ps1 >> storage\logs\deploy.log 2>&1
