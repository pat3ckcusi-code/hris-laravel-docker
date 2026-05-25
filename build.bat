@echo off
cd /d C:\hris-laravel-docker
echo Installing npm dependencies...
call npm install
echo.
echo Building Vite assets...
call npm run build
echo.
echo Build complete!
pause
