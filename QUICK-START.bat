@echo off
chcp 65001 >nul
echo ========================================
echo   Sistema de Gerenciamento de Pausas
echo   Inicializacao Rapida
echo ========================================
echo.
echo Este script vai:
echo 1. Verificar requisitos
echo 2. Configurar o sistema
echo 3. Iniciar o servidor Apache
echo.
pause

:: Executar setup
call setup.bat
if errorlevel 1 (
    echo.
    echo [ERRO] Falha na configuracao
    pause
    exit /b 1
)

echo.
echo ========================================
echo   Iniciando servidor...
echo ========================================
echo.

:: Executar iniciar
call iniciar.bat

pause

