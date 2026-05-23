param(
    [string]$BaseUrl = "http://localhost/chronodesk_v3",
    [string]$PhpPath = "C:\xampp\php\php.exe",
    [switch]$SkipHttp
)

$ErrorActionPreference = "Stop"
$failed = $false

function Write-Section {
    param([string]$Title)
    Write-Host ""
    Write-Host "== $Title =="
}

function Fail {
    param([string]$Message)
    Write-Host "[FAIL] $Message" -ForegroundColor Red
    $script:failed = $true
}

function Pass {
    param([string]$Message)
    Write-Host "[OK] $Message" -ForegroundColor Green
}

function Warn {
    param([string]$Message)
    Write-Host "[WARN] $Message" -ForegroundColor Yellow
}

$repoRoot = Resolve-Path (Join-Path $PSScriptRoot "..")
Set-Location $repoRoot

Write-Section "PHP lint"
if (!(Test-Path $PhpPath)) {
    Fail "PHP nao encontrado em $PhpPath"
} else {
    $phpFiles = Get-ChildItem -Path $repoRoot -Recurse -Filter "*.php" -File |
        Where-Object { $_.FullName -notmatch "\\\.git\\" }
    foreach ($file in $phpFiles) {
        & $PhpPath -l $file.FullName | Out-Null
        if ($LASTEXITCODE -ne 0) {
            Fail "Erro de sintaxe PHP: $($file.FullName)"
        }
    }
    if (!$failed) { Pass "PHP lint concluido" }
}

Write-Section "JavaScript syntax"
$node = Get-Command node -ErrorAction SilentlyContinue
if (!$node) {
    Warn "Node.js nao encontrado; pulando node --check"
} else {
    $jsFiles = Get-ChildItem -Path (Join-Path $repoRoot "static\js") -Recurse -Filter "*.js" -File
    foreach ($file in $jsFiles) {
        & node --check $file.FullName | Out-Null
        if ($LASTEXITCODE -ne 0) {
            Fail "Erro de sintaxe JS: $($file.FullName)"
        }
    }
    Pass "node --check concluido"
}

Write-Section "Whitespace diff"
& git diff --check
if ($LASTEXITCODE -ne 0) {
    Fail "git diff --check encontrou problemas"
} else {
    Pass "git diff --check sem problemas"
}

Write-Section "Arquivos sensiveis staged"
$sensitivePattern = '(^|/)(\.env(\..*)?|.*\.(sql|csv|log|bak|backup|zip|rar|7z|old|orig|save)|config_sistema\.json|estado\.json|pausas\.csv|funcionarios\.json)$'
$staged = & git diff --cached --name-only
$sensitiveStaged = @($staged | Where-Object { $_ -match $sensitivePattern -and $_ -ne ".env.example" })
if ($sensitiveStaged.Count -gt 0) {
    $sensitiveStaged | ForEach-Object { Fail "Arquivo sensivel staged: $_" }
} else {
    Pass "Nenhum arquivo sensivel staged detectado"
}

Write-Section "HTTP basico"
if ($SkipHttp) {
    Warn "Checks HTTP pulados por parametro"
} else {
    $targets = @(
        @{ Url = "$BaseUrl/index.php"; Expect = @(200) },
        @{ Url = "$BaseUrl/api/status.php"; Expect = @(200) },
        @{ Url = "$BaseUrl/.env"; Expect = @(403, 404) },
        @{ Url = "$BaseUrl/.git/config"; Expect = @(403, 404) },
        @{ Url = "$BaseUrl/database_SECURED.sql"; Expect = @(403, 404) },
        @{ Url = "$BaseUrl/README.md"; Expect = @(403, 404) }
    )

    foreach ($target in $targets) {
        try {
            $response = Invoke-WebRequest -Uri $target.Url -Method GET -UseBasicParsing -MaximumRedirection 0 -ErrorAction Stop
            $statusCode = [int]$response.StatusCode
        } catch {
            if ($_.Exception.Response) {
                $statusCode = [int]$_.Exception.Response.StatusCode
            } else {
                Fail "Falha HTTP em $($target.Url): $($_.Exception.Message)"
                continue
            }
        }

        if ($target.Expect -contains $statusCode) {
            Pass "$($target.Url) retornou HTTP $statusCode"
        } else {
            Fail "$($target.Url) retornou HTTP $statusCode; esperado $($target.Expect -join '/')"
        }
    }
}

Write-Section "Resultado"
if ($failed) {
    Write-Host "Validacao local concluida com falhas." -ForegroundColor Red
    exit 1
}

Write-Host "Validacao local concluida com sucesso." -ForegroundColor Green
exit 0
