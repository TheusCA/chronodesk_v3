# Sistema de Gerenciamento de Pausas - Instalação Completa
# Execute este script para instalar e iniciar o sistema automaticamente

Write-Host "========================================" -ForegroundColor Cyan
Write-Host "  Sistema de Gerenciamento de Pausas" -ForegroundColor Cyan
Write-Host "  Instalação e Inicialização Automática" -ForegroundColor Cyan
Write-Host "========================================" -ForegroundColor Cyan
Write-Host ""

# Verificar política de execução
$executionPolicy = Get-ExecutionPolicy
if ($executionPolicy -eq "Restricted") {
    Write-Host "[AVISO] Política de execução está restrita" -ForegroundColor Yellow
    Write-Host "Tentando alterar para RemoteSigned..." -ForegroundColor Yellow
    try {
        Set-ExecutionPolicy -ExecutionPolicy RemoteSigned -Scope CurrentUser -Force
        Write-Host "[OK] Política de execução alterada" -ForegroundColor Green
    } catch {
        Write-Host "[ERRO] Não foi possível alterar a política de execução" -ForegroundColor Red
        Write-Host "Execute manualmente: Set-ExecutionPolicy RemoteSigned -Scope CurrentUser" -ForegroundColor Yellow
    }
}

# Executar script de configuração
Write-Host ""
Write-Host "=== ETAPA 1: Configuração ===" -ForegroundColor Cyan
Write-Host ""

if (Test-Path ".\setup.ps1") {
    & ".\setup.ps1"
    if ($LASTEXITCODE -ne 0 -and $LASTEXITCODE -ne $null) {
        Write-Host ""
        Write-Host "[ERRO] Falha na configuração" -ForegroundColor Red
        Read-Host "Pressione Enter para sair"
        exit 1
    }
} else {
    Write-Host "[AVISO] Arquivo setup.ps1 não encontrado" -ForegroundColor Yellow
    Write-Host "Continuando com instalação básica..." -ForegroundColor Yellow
}

Write-Host ""
Write-Host "=== ETAPA 2: Verificação de Arquivos ===" -ForegroundColor Cyan
Write-Host ""

# Verificar arquivos essenciais
$arquivosEssenciais = @(
    "config.php",
    "init.php",
    "index.php",
    "login.php",
    "metricas.php",
    "classes\Funcionario.php",
    "classes\GerenciadorPausas.php"
)

$arquivosFaltando = @()
foreach ($arquivo in $arquivosEssenciais) {
    if (-not (Test-Path $arquivo)) {
        $arquivosFaltando += $arquivo
        Write-Host "[FALTANDO] $arquivo" -ForegroundColor Red
    } else {
        Write-Host "[OK] $arquivo" -ForegroundColor Green
    }
}

if ($arquivosFaltando.Count -gt 0) {
    Write-Host ""
    Write-Host "[ERRO] Alguns arquivos essenciais estão faltando!" -ForegroundColor Red
    Write-Host "Certifique-se de que todos os arquivos do projeto estão neste diretório." -ForegroundColor Yellow
    Read-Host "Pressione Enter para sair"
    exit 1
}

Write-Host ""
Write-Host "=== ETAPA 3: Inicialização ===" -ForegroundColor Cyan
Write-Host ""

# Perguntar se deseja iniciar o servidor
$iniciar = Read-Host "Deseja iniciar o servidor Apache agora? (S/N)"
if ($iniciar -eq "S" -or $iniciar -eq "s") {
    if (Test-Path ".\iniciar.ps1") {
        & ".\iniciar.ps1"
    } else {
        Write-Host "[AVISO] Arquivo iniciar.ps1 não encontrado" -ForegroundColor Yellow
        Write-Host "Inicie manualmente pelo Painel de Controle do XAMPP" -ForegroundColor Yellow
    }
} else {
    Write-Host ""
    Write-Host "Para iniciar o servidor depois, execute:" -ForegroundColor Yellow
    Write-Host "  .\iniciar.ps1" -ForegroundColor Cyan
    Write-Host ""
    Write-Host "Ou inicie manualmente pelo Painel de Controle do XAMPP" -ForegroundColor Yellow
}

Write-Host ""
Write-Host "========================================" -ForegroundColor Cyan
Write-Host "  Instalação Concluída!" -ForegroundColor Green
Write-Host "========================================" -ForegroundColor Cyan
Write-Host ""

