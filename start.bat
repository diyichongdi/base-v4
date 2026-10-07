@echo off
setlocal

rem ============================================
rem  Digital Market v4 - local dev server launcher
rem  Usage: start.bat [port]
rem ============================================

cd /d "%~dp0"

set "PORT=%~1"
if "%PORT%"=="" set "PORT=80"

where php >nul 2>nul
if errorlevel 1 goto :nophp

echo ============================================
echo   Digital Market v4
echo   URL: http://127.0.0.1:%PORT%
echo   Press Ctrl+C to stop the server.
echo ============================================
echo.

rem Open the site in the default browser
start "" http://127.0.0.1:%PORT%/login.php

php -S 127.0.0.1:%PORT% router.php

echo.
echo Server stopped.
pause
exit /b 0

:nophp
echo [ERROR] PHP not found in PATH.
echo Install PHP 8.2+ (with sqlite3) and add it to PATH.
pause
exit /b 1
