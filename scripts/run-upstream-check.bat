@echo off
REM Periodic upstream-fetch entry point for Windows Task Scheduler.
REM Optional -- only useful if this fork tracks an upstream remote. See
REM docs/DEPLOYMENT.md "Checking for upstream updates".

cd /d "%~dp0.."
php artisan upstream:check >> storage\logs\upstream-check.log 2>&1
