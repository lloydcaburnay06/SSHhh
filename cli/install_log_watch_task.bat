@echo off
REM Registers the SSHhh automatic log checker in Windows Task Scheduler (every 10 minutes).
REM Double-click this file. To remove the task later: schtasks /Delete /TN "SSHhh Log Watch" /F
setlocal
set "PHPDIR=C:\xampp\php"
set "PHPRUN=%PHPDIR%\php-win.exe"
if not exist "%PHPRUN%" set "PHPRUN=%PHPDIR%\php.exe"
set "SCRIPT=%~dp0log_watch.php"

if not exist "%PHPDIR%\php.exe" (
    echo PHP was not found in %PHPDIR%. Edit PHPDIR at the top of this file.
    pause
    exit /b 1
)

schtasks /Create /TN "SSHhh Log Watch" /SC MINUTE /MO 10 /TR "\"%PHPRUN%\" \"%SCRIPT%\"" /F
if errorlevel 1 (
    echo.
    echo Could not create the scheduled task.
    pause
    exit /b 1
)

echo.
echo Scheduled task "SSHhh Log Watch" created: runs every 10 minutes while you are signed in.
echo Running one check now so you can see the result:
echo.
"%PHPDIR%\php.exe" "%SCRIPT%"
echo.
pause
