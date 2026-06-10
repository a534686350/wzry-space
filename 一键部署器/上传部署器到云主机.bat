@echo off
set /p HOST=IP: 
set /p PASS=Password: 
set /p CODE=AccessCode: 
node "%~dp0upload.js" --host=%HOST% --password=%PASS% --code=%CODE%
pause
