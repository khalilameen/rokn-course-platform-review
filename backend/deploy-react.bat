@echo off
setlocal ENABLEEXTENSIONS ENABLEDELAYEDEXPANSION
REM React App Deployment Script for Windows

echo === React App Deployment Script ===

if "%~1"=="" (
    echo Error: Please provide the path to your React app
    echo Usage: deploy-react.bat C:\path\to\your\react-app
    exit /b 1
)

set "REACT_APP_PATH=%~1"
set "LARAVEL_PUBLIC_PATH=%cd%\public\react"

REM Check if React app directory exists
if not exist "%REACT_APP_PATH%" (
    echo Error: React app directory not found: %REACT_APP_PATH%
    exit /b 1
)

REM Navigate to React app directory
cd /d "%REACT_APP_PATH%" || exit /b 1

echo Building React app...

REM Check if package.json exists
if not exist "package.json" (
    echo Error: package.json not found in React app directory
    exit /b 1
)

REM Resolve bun and npm absolute paths (PowerShell-safe)
set "BUN_PATH="
for /f "delims=" %%i in ('where bun.exe 2^>nul') do set "BUN_PATH=%%i"

set "NPM_PATH="
for /f "delims=" %%i in ('where npm.cmd 2^>nul') do set "NPM_PATH=%%i"

if not defined NPM_PATH if exist "%ProgramFiles%\nodejs\npm.cmd" set "NPM_PATH=%ProgramFiles%\nodejs\npm.cmd"
if not defined NPM_PATH if exist "%ProgramFiles(x86)%\nodejs\npm.cmd" set "NPM_PATH=%ProgramFiles(x86)%\nodejs\npm.cmd"
if not defined NPM_PATH if exist "%UserProfile%\AppData\Roaming\npm\npm.cmd" set "NPM_PATH=%UserProfile%\AppData\Roaming\npm\npm.cmd"

REM Build the React app (prefer bun if available, else npm)
if defined BUN_PATH (
    echo Using bun to build... ^("!BUN_PATH!"^)
    call "!BUN_PATH!" run build
) else if defined NPM_PATH (
    echo Using npm to build... ^("!NPM_PATH!"^)
    call "!NPM_PATH!" run build
) else (
    echo Error: Neither npm nor bun found in PATH or common install locations.
    echo        Ensure Node.js is installed and restart your terminal.
    exit /b 1
)

REM Check if build was successful
if not exist "dist" (
    echo Error: Build failed. 'dist' directory not found
    exit /b 1
)

echo [SUCCESS] React app built successfully

REM Create Laravel public/react directory if it doesn't exist
echo Creating Laravel public/react directory...
if not exist "%LARAVEL_PUBLIC_PATH%" mkdir "%LARAVEL_PUBLIC_PATH%"

REM Copy built files to Laravel public directory
echo Copying files to Laravel...
xcopy "dist\*" "%LARAVEL_PUBLIC_PATH%\" /E /I /Y >nul

if %errorlevel% equ 0 (
    echo [SUCCESS] Files copied successfully to: %LARAVEL_PUBLIC_PATH%
    
    REM List deployed files
    echo Deployed files:
    dir "%LARAVEL_PUBLIC_PATH%"
    
    echo === Deployment Complete! ===
    echo Visit http://127.0.0.1:8000/ to see your React app
) else (
    echo Error: Failed to copy files
    exit /b 1
)

endlocal


