@echo off
rem Double-click to enroll phones. Runs enroll.ps1 from this folder; see README.txt.
powershell.exe -NoProfile -ExecutionPolicy Bypass -File "%~dp0enroll.ps1" %*
echo.
pause
