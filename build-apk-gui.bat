@echo off
setlocal
title ALinRadar APK Builder
cd /d "%~dp0"
set "BUILDER=%~dp0APP\wzry_overlay_apk\fixed-app-builder-gui.ps1"
if not exist "%BUILDER%" (
  echo Cannot find builder: %BUILDER%
  pause
  exit /b 1
)
start "ALinRadar APK Builder" powershell.exe -NoProfile -ExecutionPolicy Bypass -STA -WindowStyle Hidden -File "%BUILDER%"
