@echo off
rem ============================================================
rem  GOKZ Profile Viewer launcher
rem  Starts the PHP dev server and opens the site in your browser.
rem  (For dependency checks + first-run setup, use install.bat.)
rem ============================================================
setlocal
cd /d "%~dp0"

set "PAGE=/"
if not exist config.json set "PAGE=/setup.php"
set "URL=http://localhost:8000%PAGE%"

rem --- start the PHP server in its own window (inherits this folder as CWD) ---
start "GOKZ Profile Viewer  (close this window to stop)" cmd /k "php -S localhost:8000 router.php"

rem --- give the server a second, then open the browser (Firefox if present, else default) ---
timeout /t 1 /nobreak >nul
set "FF="
if exist "%ProgramFiles%\Mozilla Firefox\firefox.exe" set "FF=%ProgramFiles%\Mozilla Firefox\firefox.exe"
if not defined FF if exist "%ProgramFiles(x86)%\Mozilla Firefox\firefox.exe" set "FF=%ProgramFiles(x86)%\Mozilla Firefox\firefox.exe"
if defined FF ( start "" "%FF%" "%URL%" ) else ( start "" "%URL%" )

exit /b
