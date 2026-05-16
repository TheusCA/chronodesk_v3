@echo off
chcp 65001 >nul
echo ========================================
echo   Sistema de Gerenciamento de Pausas
echo   Configuração Automática - Windows
echo ========================================
echo.

:: Verificar se o XAMPP está instalado
echo [1/5] Verificando instalação do XAMPP...
set XAMPP_PATH=C:\xampp
if not exist "%XAMPP_PATH%\apache\bin\httpd.exe" (
    echo [ERRO] XAMPP não encontrado em %XAMPP_PATH%
    echo.
    echo Por favor, instale o XAMPP ou ajuste o caminho no script.
    echo Download: https://www.apachefriends.org/
    pause
    exit /b 1
)
echo [OK] XAMPP encontrado!

:: Verificar se o PHP está disponível
echo [2/5] Verificando PHP...
"%XAMPP_PATH%\php\php.exe" -v >nul 2>&1
if errorlevel 1 (
    echo [ERRO] PHP não encontrado no XAMPP
    pause
    exit /b 1
)
echo [OK] PHP encontrado!

:: Verificar extensão ZipArchive
echo [3/5] Verificando extensão ZipArchive...
"%XAMPP_PATH%\php\php.exe" -m | findstr /i "zip" >nul
if errorlevel 1 (
    echo [AVISO] Extensão ZipArchive não encontrada. Relatórios ZIP podem não funcionar.
) else (
    echo [OK] Extensão ZipArchive encontrada!
)

:: Criar diretório de destino se não existir
echo [4/5] Preparando diretório de instalação...
set INSTALL_DIR=%XAMPP_PATH%\htdocs\Sistema_Pausas
if not exist "%INSTALL_DIR%" (
    mkdir "%INSTALL_DIR%"
    echo [OK] Diretório criado: %INSTALL_DIR%
) else (
    echo [OK] Diretório já existe: %INSTALL_DIR%
)

:: Verificar permissões
echo [5/5] Verificando permissões...
if not exist "%INSTALL_DIR%\pausas.csv" (
    echo [INFO] Arquivo pausas.csv será criado automaticamente na primeira execução
)
if not exist "%INSTALL_DIR%\estado.json" (
    echo [INFO] Arquivo estado.json será criado automaticamente na primeira execução
)

echo.
echo ========================================
echo   Configuração Concluída!
echo ========================================
echo.
echo Diretório de instalação: %INSTALL_DIR%
echo.
echo Próximos passos:
echo 1. Copie todos os arquivos do projeto para: %INSTALL_DIR%
echo 2. Execute o script 'iniciar.bat' para iniciar o servidor
echo.
pause

