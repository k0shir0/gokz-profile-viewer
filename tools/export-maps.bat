@echo off
rem ============================================================
rem  Export GOKZ map geometry for the replay viewer.
rem  Writes static files to ..\mapdata (served same-origin by the
rem  app via router.php -- no separate server, no CORS, no admin).
rem  Run once per map you want to watch in 3D. Re-run to refresh.
rem  The game folder is read from ..\config.json (created by setup.php).
rem ============================================================
setlocal
cd /d "%~dp0"

rem --- Which maps to export. Wildcards allowed:
rem       *           = every .bsp in the maps folder
rem       kz_*,bkz_*  = only those prefixes
rem       kz_baxter   = a single map
set "MAPS=*"

rem --- CS:GO folder from config.json (needs Node; or hardcode GAME_DIR below).
set "GAME_DIR="
for /f "usebackq delims=" %%i in (`node -e "try{process.stdout.write(String(require('../config.json').csgo_dir||''))}catch(e){}"`) do set "GAME_DIR=%%i"
if not defined GAME_DIR (
  echo No csgo path found. Run the setup wizard first ^(setup.php^) to create config.json,
  echo or set GAME_DIR manually in this file.
  pause & exit /b 1
)

rem --- Extra options (space separated). Examples:
rem       --untextured  faster, gray geometry (most reliable)
rem       --overwrite   re-export maps that were already exported
rem       --verbose     detailed logging
set "OPTIONS="

echo Exporting maps "%MAPS%" -^> ..\mapdata  (this can take a while)...
echo.
"SourceUtils\bin\SourceUtils.WebExport.exe" export ^
    --maps "%MAPS%" ^
    --outdir "..\mapdata" ^
    --gamedir "%GAME_DIR%" ^
    --mapsdir maps ^
    --packages "pak01_dir.vpk" ^
    --url-prefix "/mapdata" %OPTIONS%

echo.
echo Done. Only maps whose .bsp is present in csgo\maps can be exported.
echo Start the app with start.bat and open the Replays tab.
pause
