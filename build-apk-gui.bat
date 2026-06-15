@echo off
setlocal EnableExtensions
title ALinRadar APK Builder
cd /d "%~dp0"

set "BUILDER=%~dp0APP\wzry_overlay_apk\fixed-app-builder-gui.ps1"
set "PS=powershell.exe"

color 0B
cls
echo.
echo  ============================================================
echo                  ALinRadar Local APK Builder
echo  ============================================================
echo.
echo   Workspace : %CD%
echo   Builder   : %BUILDER%
echo.

if not exist "%BUILDER%" (
  color 0C
  echo  [ERROR] Builder script was not found.
  echo.
  echo  Please check this path:
  echo  %BUILDER%
  echo.
  pause
  exit /b 1
)

where %PS% >nul 2>nul
if errorlevel 1 (
  color 0C
  echo  [ERROR] PowerShell was not found on this system.
  echo.
  pause
  exit /b 1
)

echo  [START] Opening GUI builder...
start "ALinRadar APK Builder" %PS% -NoProfile -ExecutionPolicy Bypass -STA -WindowStyle Hidden -File "%BUILDER%"

if errorlevel 1 (
  color 0C
  echo.
  echo  [ERROR] Failed to start GUI builder.
  echo.
  pause
  exit /b 1
)

echo  [OK] GUI builder is running. This window will close soon.
timeout /t 2 /nobreak >nul
exit /b 0
