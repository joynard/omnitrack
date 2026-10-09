@echo off
REM ---------------------------------------------------------------------------
REM One-click launcher for the local Omnitrack stack.
REM
REM Double-click this file, or use the Start Menu shortcut that points at it.
REM All the real logic lives in start-omnitrack.ps1; this wrapper only exists to
REM give Explorer something double-clickable, for two reasons:
REM
REM   1. .ps1 files open in Notepad when double-clicked, not in PowerShell.
REM   2. The project path contains spaces and an ampersand ("Projects & Event
REM      Management"), which breaks naive command lines. %~dp0 is quoted and the
REM      script resolves the project root from its own location instead.
REM
REM -NoProfile keeps a slow or broken user profile from delaying startup, and
REM -ExecutionPolicy Bypass is scoped to this process only -- it changes no
REM machine or user setting.
REM ---------------------------------------------------------------------------

setlocal

powershell.exe -NoProfile -ExecutionPolicy Bypass -File "%~dp0start-omnitrack.ps1" %*

REM Keep the window open if the script exited with an error, so its message can
REM actually be read instead of flashing past.
if errorlevel 1 (
    echo.
    echo Tekan Enter untuk menutup.
    pause >nul
)

endlocal
