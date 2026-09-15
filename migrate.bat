@echo off
setlocal
cd /d "%~dp0"

where php >nul 2>nul
if %errorlevel%==0 (
    php spark migrate
) else if exist "C:\xampp\php\php.exe" (
    "C:\xampp\php\php.exe" spark migrate
) else (
    echo Tidak ketemu php.exe. Buka file ini, ganti baris di bawah dengan path PHP Anda:
    echo   "C:\xampp\php\php.exe" spark migrate
)

pause
