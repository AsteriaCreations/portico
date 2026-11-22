@echo off
REM Hourly entry point for Windows Task Scheduler.
REM See docs/DEPLOYMENT.md "Scheduled tasks" for how to register this as a scheduled task.

cd /d "%~dp0.."
php artisan events:depart-ended >> storage\logs\depart-ended.log 2>&1
