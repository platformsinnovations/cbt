@echo off
:: ============================================================================
:: CBT Offline — Server Launcher
:: Starts MariaDB (portable) + PHP built-in server and opens firewall rule.
:: This script is called by CBTManager.exe (the system tray app).
:: ============================================================================
setlocal

set "APP_DIR=%~dp0"
set "PHP_EXE=%APP_DIR%php\php.exe"
set "MARIADB_BIN=%APP_DIR%mariadb\bin"
set "MYSQL_EXE=%MARIADB_BIN%\mysqld.exe"
set "WWW_DIR=%APP_DIR%www"
set "DATA_DIR=%APP_DIR%data"
set "SESSIONS_DIR=%DATA_DIR%\sessions"
set "LOG_DIR=%DATA_DIR%\logs"
set "SERVER_PORT=8080"
set "MYSQL_PORT=3306"

:: -----------------------------------------------------------------------
:: Create required directories
:: -----------------------------------------------------------------------
if not exist "%DATA_DIR%"     mkdir "%DATA_DIR%"
if not exist "%SESSIONS_DIR%" mkdir "%SESSIONS_DIR%"
if not exist "%LOG_DIR%"      mkdir "%LOG_DIR%"

:: -----------------------------------------------------------------------
:: 1. Open Windows Firewall for the PHP server port (requires admin)
:: -----------------------------------------------------------------------
netsh advfirewall firewall show rule name="CBT Offline Server" >nul 2>&1
if %errorlevel% neq 0 (
    echo Opening firewall for port %SERVER_PORT%...
    netsh advfirewall firewall add rule ^
        name="CBT Offline Server" ^
        dir=in ^
        action=allow ^
        protocol=TCP ^
        localport=%SERVER_PORT% ^
        profile=private,domain ^
        description="Allows student computers on the LAN to connect to the CBT server"
)

:: -----------------------------------------------------------------------
:: 2. Start MariaDB (portable, binds to 127.0.0.1 only — not exposed to LAN)
:: -----------------------------------------------------------------------
echo Checking MariaDB...
tasklist /FI "IMAGENAME eq mysqld.exe" 2>nul | find /I "mysqld.exe" >nul
if %errorlevel% neq 0 (
    echo Starting MariaDB...
    :: Initialize data directory if this is the first run
    if not exist "%DATA_DIR%\mysql\" (
        echo First run: initializing MariaDB data directory...
        "%MARIADB_BIN%\mysql_install_db.exe" ^
            --datadir="%DATA_DIR%" ^
            --password=cbt_pass_2024! ^
            --default-user=cbt_user ^
            >> "%LOG_DIR%\mariadb_install.log" 2>&1
    )
    start "" /B "%MYSQL_EXE%" ^
        --defaults-file="%APP_DIR%my.ini" ^
        --datadir="%DATA_DIR%" ^
        --port=%MYSQL_PORT% ^
        --bind-address=127.0.0.1 ^
        --log-error="%LOG_DIR%\mariadb.log"
    :: Wait for MariaDB to be ready
    timeout /T 4 /NOBREAK >nul
    echo MariaDB started.
) else (
    echo MariaDB already running.
)

:: -----------------------------------------------------------------------
:: 3. Start PHP Built-in Server (listens on all interfaces for LAN access)
:: -----------------------------------------------------------------------
echo Checking PHP server...
tasklist /FI "IMAGENAME eq php.exe" 2>nul | find /I "php.exe" >nul
if %errorlevel% neq 0 (
    echo Starting PHP server on port %SERVER_PORT%...
    start "" /B "%PHP_EXE%" -S 0.0.0.0:%SERVER_PORT% -t "%WWW_DIR%" ^
        -c "%APP_DIR%php\php.ini" ^
        >> "%LOG_DIR%\php_server.log" 2>&1
    echo PHP server started.
) else (
    echo PHP server already running.
)

echo.
echo =====================================================
echo  CBT Offline Server is RUNNING
echo  Students connect to: http://[YOUR-IP]:%SERVER_PORT%
echo  Admin panel:         http://localhost:%SERVER_PORT%/admin/
echo =====================================================
echo.
