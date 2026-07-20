@echo off
REM ============================================================
REM  BeLive Automation Engine — one-click dev/demo server
REM
REM  Starts MariaDB (if not already running) and the PHP dev
REM  server WITH the front-controller router script. The router
REM  argument (public/index.php) is required — without it, clean
REM  URLs like /tenant/login return PHP's built-in 404 page.
REM ============================================================
cd /d "%~dp0"

REM --- MariaDB (XAMPP) ---
tasklist /FI "IMAGENAME eq mysqld.exe" | find /I "mysqld.exe" >nul
if errorlevel 1 (
    echo Starting MariaDB...
    start "MariaDB" /B C:\xampp\mysql\bin\mysqld.exe --defaults-file=C:\xampp\mysql\bin\my.ini --standalone
    timeout /t 3 /nobreak >nul
) else (
    echo MariaDB already running.
)

REM --- PHP dev server (12 workers, front-controller routing) ---
REM 12 workers: AI pipelines hold a worker for 10-25s each, and Meta's
REM room-photo fetches (facebookexternalhit) must always find a free one.
set PHP_CLI_SERVER_WORKERS=12
echo.
echo BeLive Automation Engine running at http://127.0.0.1:8080
echo   Public site : http://127.0.0.1:8080/
echo   Admin panel : http://127.0.0.1:8080/admin/login
echo.
echo Keep this window open. Press Ctrl+C to stop.
php -S 127.0.0.1:8080 -t public public/index.php
