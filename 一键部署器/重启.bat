@echo off
title Radar Deployer
cd /d "%~dp0"

echo Killing old process...
taskkill /f /im node.exe >nul 2>&1

timeout /t 2 /nobreak >nul

echo Starting deployer...
node server.js

pause
