@echo off
REM Periodic entry point for Windows Task Scheduler.
REM See README.md "Comp-reward vouchers" for how to register this as a scheduled task.

cd /d "%~dp0.."
php artisan vouchers:grant-comp-rewards >> storage\logs\vouchers-grant-comp-rewards.log 2>&1
