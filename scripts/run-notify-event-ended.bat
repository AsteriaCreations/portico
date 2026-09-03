@echo off
REM Hourly entry point for Windows Task Scheduler.
REM See README.md "Post-event notifications" for how to register this as a scheduled task.

cd /d "%~dp0.."
php artisan events:notify-ended >> storage\logs\notify-event-ended.log 2>&1
