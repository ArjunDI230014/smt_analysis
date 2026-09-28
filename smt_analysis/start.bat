@echo off
setlocal
cd /d "%~dp0"

where php >nul 2>&1
if errorlevel 1 (
    echo PHP was not found. Add your PHP installation to PATH and try again.
    pause
    exit /b 1
)

if not exist "vendor\autoload.php" (
    echo Dependencies are missing. Run composer install in this folder first.
    pause
    exit /b 1
)

if not exist ".env" (
    echo Database configuration is missing. Create .env with your MySQL settings first.
    pause
    exit /b 1
)

php spark migrate
if errorlevel 1 goto failed

php spark csat:user --if-empty
if errorlevel 1 goto failed

echo.
echo Open http://localhost:8080 in your browser.
echo Keep this window open while using the app. Press Ctrl+C to stop.
echo.
php -d memory_limit=512M -d upload_max_filesize=20M -d post_max_size=22M -d max_execution_time=120 -S localhost:8080 -t public vendor/codeigniter4/framework/system/rewrite.php
if errorlevel 1 goto failed
exit /b 0

:failed
echo.
echo Startup failed. Check the error above and make sure MySQL is running.
echo If port 8080 is already in use, stop the existing server before starting again.
pause
exit /b 1
