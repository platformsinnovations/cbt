@echo off
:: ============================================================================
:: CBT Offline — Server Stop Script
:: Gracefully stops the PHP server and MariaDB.
:: ============================================================================
echo Stopping CBT Offline Server...

:: Stop PHP server
taskkill /F /IM php.exe >nul 2>&1
if %errorlevel% equ 0 (
    echo PHP server stopped.
) else (
    echo PHP server was not running.
)

:: Stop MariaDB
taskkill /F /IM mysqld.exe >nul 2>&1
if %errorlevel% equ 0 (
    echo MariaDB stopped.
) else (
    echo MariaDB was not running.
)

echo CBT server stopped.
