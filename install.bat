@echo off
rem ============================================================
rem  GOKZ Profile Viewer - installer / launcher
rem  Verifies dependencies, then starts the site. First run opens
rem  the setup wizard (writes your config.json).
rem ============================================================
setlocal enabledelayedexpansion
cd /d "%~dp0"
echo(
echo  === GOKZ Profile Viewer ===
echo(

rem --- required: PHP + pdo_sqlite ---
where php >nul 2>nul
if errorlevel 1 (
  echo  [X] PHP is not on your PATH.
  echo      Install:  winget install PHP.PHP    ^(then reopen this window^)
  echo(
  pause & exit /b 1
)
for /f "tokens=*" %%v in ('php -r "echo PHP_VERSION;"') do echo  [OK] PHP %%v
php -r "exit(extension_loaded('pdo_sqlite')?0:1)"
if errorlevel 1 (
  echo  [X] PHP extension 'pdo_sqlite' is disabled - enable it in php.ini.
  echo(
  pause & exit /b 1
)

rem --- optional: Node + ffmpeg (only needed for video rendering) ---
where node >nul 2>nul && (for /f "tokens=*" %%v in ('node --version') do echo  [OK] Node.js %%v) || echo  [--] Node.js not found ^(optional - video rendering^): https://nodejs.org
where ffmpeg >nul 2>nul && (echo  [OK] ffmpeg found) || echo  [--] ffmpeg not found ^(optional - video rendering^): winget install Gyan.FFmpeg

echo(
if not exist config.json (
  echo  No config.json yet - the setup wizard will open in your browser.
  start "" "http://localhost:8000/setup.php"
) else (
  start "" "http://localhost:8000/"
)
echo  Serving http://localhost:8000/   ^(close this window to stop^)
echo(
php -S localhost:8000 router.php
