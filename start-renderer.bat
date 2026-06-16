@echo off
rem ============================================================
rem  Auto-render new GOKZ replays to mp4 (output: renders\).
rem  - First run installs deps (Playwright + SourceUtils), then
rem    BASELINES existing replays (won't render the back-catalogue).
rem  - After that, any NEW replay that appears is rendered automatically
rem    with the mhud HUD, using ffmpeg (NVENC).
rem
rem  Options (uncomment / set before running):
rem    set PLAYERS=<steamid32>   -> only render that player's replays
rem    set RENDER_EXISTING=1     -> also render everything already present (slow)
rem    set POLL=15               -> poll interval seconds (default 30)
rem ============================================================
setlocal
cd /d "%~dp0"

rem set PLAYERS=

rem --- one-time: download SourceUtils (map exporter) if missing ---
if not exist "tools\SourceUtils\bin\SourceUtils.WebExport.exe" (
    echo Downloading SourceUtils...
    powershell -NoProfile -Command "$ErrorActionPreference='Stop'; New-Item -ItemType Directory -Force 'tools\SourceUtils' | Out-Null; $u='https://github.com/Metapyziks/SourceUtils/releases/latest/download/SourceUtils.zip'; Invoke-WebRequest $u -OutFile 'tools\SourceUtils\SourceUtils.zip'; Expand-Archive -Force 'tools\SourceUtils\SourceUtils.zip' 'tools\SourceUtils'; Remove-Item 'tools\SourceUtils\SourceUtils.zip'"
    if errorlevel 1 ( echo Failed to download SourceUtils - get it from https://github.com/Metapyziks/SourceUtils/releases & pause & exit /b 1 )
)

cd /d "%~dp0renderer"

rem --- one-time: renderer deps (Playwright + headless Chromium) ---
if not exist node_modules (
    echo Installing renderer dependencies ^(one time^)...
    call npm install --no-audit --no-fund
    call npx playwright install chromium
)

node watch.js
pause
