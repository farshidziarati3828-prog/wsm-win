@echo off
setlocal
echo This installs the separate Local Bridge helper only; it is not the WP Store Manager Desktop Tauri installer.
echo The Desktop app must be installed from its Windows -setup.exe or .msi build artifact.
echo.
set "INSTALLER=%~dp0install-windows.ps1"
if not exist "%INSTALLER%" (
  echo Cannot find install-windows.ps1 next to this launcher.
  pause
  exit /b 1
)
powershell.exe -NoLogo -NoProfile -STA -ExecutionPolicy Bypass -File "%INSTALLER%"
if errorlevel 1 (
  echo Installer stopped with an error. Check the message above or the install log in %%TEMP%%.
  pause
)
endlocal
