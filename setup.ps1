# Sistema de Gerenciamento de Pausas - Script de Configuração (PowerShell)
# Requer PowerShell 5.1 ou superior
#
# COMO USAR:
#   .\setup.ps1
# (Note o ".\" antes do nome do arquivo)

Write-Host "========================================" -ForegroundColor Cyan
Write-Host "  Sistema de Gerenciamento de Pausas" -ForegroundColor Cyan
Write-Host "  Configuração Automática - Windows" -ForegroundColor Cyan
Write-Host "========================================" -ForegroundColor Cyan
Write-Host ""

# Verificar se está executando como administrador (opcional)
$isAdmin = ([Security.Principal.WindowsPrincipal] [Security.Principal.WindowsIdentity]::GetCurrent()).IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)
if (-not $isAdmin) {
    Write-Host "[AVISO] Executando sem privilégios de administrador" -ForegroundColor Yellow
    Write-Host "Algumas operações podem requerer permissões elevadas" -ForegroundColor Yellow
    Write-Host ""
}

# Caminho padrão do XAMPP
$XAMPP_PATH = "C:\xampp"
$INSTALL_DIR = "$XAMPP_PATH\htdocs\Sistema_Pausas"

# Verificar se o XAMPP está instalado
Write-Host "[1/5] Verificando instalação do XAMPP..." -ForegroundColor Yellow
if (-not (Test-Path "$XAMPP_PATH\apache\bin\httpd.exe")) {
    Write-Host "[ERRO] XAMPP não encontrado em $XAMPP_PATH" -ForegroundColor Red
    Write-Host ""
    Write-Host "Por favor, instale o XAMPP ou ajuste o caminho no script." -ForegroundColor Yellow
    Write-Host "Download: https://www.apachefriends.org/" -ForegroundColor Cyan
    Read-Host "Pressione Enter para sair"
    exit 1
}
Write-Host "[OK] XAMPP encontrado!" -ForegroundColor Green

# Verificar PHP
Write-Host "[2/5] Verificando PHP..." -ForegroundColor Yellow
$phpPath = "$XAMPP_PATH\php\php.exe"
if (-not (Test-Path $phpPath)) {
    Write-Host "[ERRO] PHP não encontrado no XAMPP" -ForegroundColor Red
    Read-Host "Pressione Enter para sair"
    exit 1
}

try {
    $phpVersion = & $phpPath -v 2>&1 | Select-Object -First 1
    Write-Host "[OK] PHP encontrado: $phpVersion" -ForegroundColor Green
} catch {
    Write-Host "[ERRO] Erro ao verificar versão do PHP" -ForegroundColor Red
    exit 1
}

# Verificar extensão ZipArchive
Write-Host "[3/5] Verificando extensão ZipArchive..." -ForegroundColor Yellow
$zipCheck = & $phpPath -m 2>&1 | Select-String -Pattern "zip" -Quiet
if ($zipCheck) {
    Write-Host "[OK] Extensão ZipArchive encontrada!" -ForegroundColor Green
} else {
    Write-Host "[AVISO] Extensão ZipArchive não encontrada. Relatórios ZIP podem não funcionar." -ForegroundColor Yellow
}

# Criar diretório de instalação
Write-Host "[4/5] Preparando diretório de instalação..." -ForegroundColor Yellow
if (-not (Test-Path $INSTALL_DIR)) {
    try {
        New-Item -ItemType Directory -Path $INSTALL_DIR -Force | Out-Null
        Write-Host "[OK] Diretório criado: $INSTALL_DIR" -ForegroundColor Green
    } catch {
        Write-Host "[ERRO] Falha ao criar diretório: $_" -ForegroundColor Red
        exit 1
    }
} else {
    Write-Host "[OK] Diretório já existe: $INSTALL_DIR" -ForegroundColor Green
}

# Verificar arquivos necessários
Write-Host "[5/5] Verificando arquivos do sistema..." -ForegroundColor Yellow
$requiredFiles = @(
    "config.php",
    "init.php",
    "index.php",
    "login.php",
    "metricas.php",
    "classes\Funcionario.php",
    "classes\GerenciadorPausas.php"
)

$missingFiles = @()
foreach ($file in $requiredFiles) {
    if (-not (Test-Path "$INSTALL_DIR\$file")) {
        $missingFiles += $file
    }
}

if ($missingFiles.Count -gt 0) {
    Write-Host "[AVISO] Alguns arquivos não foram encontrados:" -ForegroundColor Yellow
    foreach ($file in $missingFiles) {
        Write-Host "  - $file" -ForegroundColor Yellow
    }
    Write-Host ""
    Write-Host "Por favor, copie todos os arquivos do projeto para:" -ForegroundColor Yellow
    Write-Host "$INSTALL_DIR" -ForegroundColor Cyan
} else {
    Write-Host "[OK] Todos os arquivos necessários encontrados!" -ForegroundColor Green
}

# Verificar permissões de escrita
Write-Host ""
Write-Host "Verificando permissões de escrita..." -ForegroundColor Yellow
try {
    $testFile = "$INSTALL_DIR\.test_write"
    "test" | Out-File -FilePath $testFile -ErrorAction Stop
    Remove-Item $testFile -Force
    Write-Host "[OK] Permissões de escrita OK!" -ForegroundColor Green
} catch {
    Write-Host "[AVISO] Problemas com permissões de escrita: $_" -ForegroundColor Yellow
}

Write-Host ""
Write-Host "========================================" -ForegroundColor Cyan
Write-Host "  Configuração Concluída!" -ForegroundColor Green
Write-Host "========================================" -ForegroundColor Cyan
Write-Host ""
Write-Host "Diretório de instalação: $INSTALL_DIR" -ForegroundColor Cyan
Write-Host ""
Write-Host "Próximos passos:" -ForegroundColor Yellow
Write-Host "1. Se ainda não fez, copie todos os arquivos para: $INSTALL_DIR" -ForegroundColor White
Write-Host "2. Execute o script 'iniciar.ps1' ou 'iniciar.bat' para iniciar o servidor" -ForegroundColor White
Write-Host ""
Write-Host "Ou inicie manualmente pelo Painel de Controle do XAMPP" -ForegroundColor Gray
Write-Host ""

Read-Host "Pressione Enter para sair"

