@echo off
REM Nightly backup entry point for Windows Task Scheduler.
REM See README.md "Backups" for how to register this as a scheduled task.

cd /d "%~dp0.."
php artisan backup:database >> storage\logs\backup.log 2>&1
