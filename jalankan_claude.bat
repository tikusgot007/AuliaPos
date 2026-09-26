@echo off
setlocal enabledelayedexpansion
cls

:: Konfigurasi dasar 9Router
set "ANTHROPIC_BASE_URL=http://localhost:20128/v1"
set "ANTHROPIC_API_KEY=9router-local-key"

echo ===================================================
echo     MENGAMBIL DAFTAR COMBO DARI 9ROUTER...
echo ===================================================

:: Membuat file sementara untuk menyimpan daftar model
set "TEMP_JSON=%TEMP%\9router_models.json"
curl -s http://localhost:20128/v1/models > "%TEMP_JSON%"

if %ERRORLEVEL% neq 0 (
    echo [ERROR] Gagal terhubung ke 9Router! 
    echo Pastikan aplikasi 9Router sudah dijalankan di latar belakang.
    pause
    exit /b
)

cls
echo ===================================================
echo         STEP 1: PILIH COMBO 9ROUTER ANDA
echo ===================================================
echo.

set count=0
for /f "tokens=*" %%i in ('powershell -Command "(Get-Content '%TEMP_JSON%' | ConvertFrom-Json).data.id | Where-Object { $_ -notmatch '/' }"') do (
    set /a count+=1
    set "model[!count!]=%%i"
    echo  !count!. %%i
)

echo.
echo ===================================================
if %count%==0 (
    echo [!] Tidak ada nama Combo kustom yang terdeteksi di 9Router Anda.
    del "%TEMP_JSON%" 2>nul
    pause
    exit /b
)

set /p nomor="Pilih nomor combo Anda (1-%count%): "
if not defined model[%nomor%] (
    echo Pilihan tidak valid.
    del "%TEMP_JSON%" 2>nul
    pause
    exit /b
)
set "COMBO_NAME=!model[%nomor%]!"
del "%TEMP_JSON%" 2>nul

cls
echo ===================================================
echo         STEP 2: PILIH TINGKAT PERIZINAN AI
echo ===================================================
echo  1. Manual (Selalu tanya izin y/n setiap kali edit file)
echo  2. Auto / Accept Edits (Otomatis edit file tanpa nanya - REKOMENDASI)
echo  3. Full Autopilot (Bypass semua izin terminal/YOLO Mode)
echo ===================================================
set /p perm_opt="Pilih opsi perizinan (1-3): "

set "PERM_FLAG="
if "%perm_opt%"=="2" set "PERM_FLAG=--permission-mode acceptEdits"
if "%perm_opt%"=="3" set "PERM_FLAG=--permission-mode bypassPermissions"

cls
echo ===================================================
echo  Menjalankan Claude Code dengan REMOTE CONTROL (RC)
echo ===================================================
echo  Combo Terpilih : %COMBO_NAME%
echo  Status Izin    : Opsi %perm_opt%
echo  Status RC      : MENGHUBUNGKAN...
echo ===================================================
echo.

:: PERBAIKAN: Menggunakan flag resmi --remote-control
npx @anthropic-ai/claude-code --model %COMBO_NAME% %PERM_FLAG% 

echo.
echo [INFO] Sesi Claude Code telah selesai atau terputus.
pause