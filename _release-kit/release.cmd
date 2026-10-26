@echo off
rem Wrapper so the kit runs the same way from Explorer, cmd, PowerShell, or any editor.
powershell.exe -NoProfile -ExecutionPolicy Bypass -File "%~dp0release.ps1" %*
exit /b %ERRORLEVEL%
