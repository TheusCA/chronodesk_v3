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
$phpExecutable = $null
if (Test-Path $PhpPath) {
    $phpExecutable = (Resolve-Path $PhpPath).Path
} else {
    $phpCommand = Get-Command php -ErrorAction SilentlyContinue
    if ($phpCommand) {
        $phpExecutable = $phpCommand.Source
    }
}

if (!$phpExecutable) {
    Fail "PHP nao encontrado em $PhpPath nem no PATH"
} else {
    $phpFiles = Get-ChildItem -Path $repoRoot -Recurse -Filter "*.php" -File |
        Where-Object {
            $_.FullName -notmatch "\\\.git\\" `
                -and $_.FullName -notmatch "\\node_modules\\" `
                -and $_.FullName -notmatch "\\vendor\\"
        }
    foreach ($file in $phpFiles) {
        & $phpExecutable -l $file.FullName | Out-Null
        if ($LASTEXITCODE -ne 0) {
            Fail "Erro de sintaxe PHP: $($file.FullName)"
        }
    }
    if (!$failed) { Pass "PHP lint concluido" }

    & $phpExecutable (Join-Path $repoRoot "scripts\qa-smoke.php")
    if ($LASTEXITCODE -ne 0) {
        Fail "Smoke test PHP falhou"
    } else {
        Pass "Smoke test PHP concluido"
    }

    # Exige a extensao ldap (extension=ldap no php.ini); falha se ausente.
    & $phpExecutable (Join-Path $repoRoot "scripts\qa-auth.php")
    if ($LASTEXITCODE -ne 0) {
        Fail "QA de autenticacao LDAP falhou (exige extensao ldap do PHP)"
    } else {
        Pass "QA de autenticacao LDAP concluido"
    }

    # Sem banco: abertura da conexao simulada e modelo da sessao MySQL.
    & $phpExecutable (Join-Path $repoRoot "scripts\qa-timezone.php")
    if ($LASTEXITCODE -ne 0) {
        Fail "QA de fuso horario falhou"
    } else {
        Pass "QA de fuso horario concluido"
    }

    & $phpExecutable (Join-Path $repoRoot "scripts\qa-overtime.php")
    if ($LASTEXITCODE -ne 0) {
        Fail "QA de horas extras e exportacoes falhou"
    } else {
        Pass "QA de horas extras e exportacoes concluido"
    }

    # Sem banco: historico de escala, Lideranca e cadastro fora do Mapa (F1).
    & $phpExecutable (Join-Path $repoRoot "scripts\qa-schedule-history.php")
    if ($LASTEXITCODE -ne 0) {
        Fail "QA do historico de escala falhou"
    } else {
        Pass "QA do historico de escala concluido"
    }

    # Sem banco: relatorio, CSV e Calendario iguais a referencia anterior ao F2B.
    & $phpExecutable (Join-Path $repoRoot "scripts\qa-schedule-legacy-output.php")
    if ($LASTEXITCODE -ne 0) {
        Fail "QA da saida antiga de escala falhou"
    } else {
        Pass "QA da saida antiga de escala concluido"
    }

    # Sem banco: ausencias (F3): escopo de leitura e, depois, escrita.
    & $phpExecutable (Join-Path $repoRoot "scripts\qa-absences.php")
    if ($LASTEXITCODE -ne 0) {
        Fail "QA de ausencias falhou"
    } else {
        Pass "QA de ausencias concluido"
    }

    # Sem banco: texto livre no Calendario e na lista de plantoes (SEC-07b).
    & $phpExecutable (Join-Path $repoRoot "scripts\qa-calendar-text.php")
    if ($LASTEXITCODE -ne 0) {
        Fail "QA do texto livre do Calendario falhou"
    } else {
        Pass "QA do texto livre do Calendario concluido"
    }

    # Sem banco: data de corte do motor (F5): migration 019 e registro do corte.
    & $phpExecutable (Join-Path $repoRoot "scripts\qa-presence-cutoff.php")
    if ($LASTEXITCODE -ne 0) {
        Fail "QA da data de corte falhou"
    } else {
        Pass "QA da data de corte concluido"
    }

    # Sem banco: Mapa PA no motor (F6): conflito por dia, presenca, P9, DF7, sem N+1.
    & $phpExecutable (Join-Path $repoRoot "scripts\qa-pa-conflicts.php")
    if ($LASTEXITCODE -ne 0) {
        Fail "QA do Mapa PA falhou"
    } else {
        Pass "QA do Mapa PA concluido"
    }

    # Sem banco: arquivamento de tecnicos (F4): migration 014 e, depois, o plano.
    & $phpExecutable (Join-Path $repoRoot "scripts\qa-archive.php")
    if ($LASTEXITCODE -ne 0) {
        Fail "QA do arquivamento falhou"
    } else {
        Pass "QA do arquivamento concluido"
    }

    # Sem banco: motor de presenca, presence.php e presenca no status.php (F2).
    & $phpExecutable (Join-Path $repoRoot "scripts\qa-presence-engine.php")
    if ($LASTEXITCODE -ne 0) {
        Fail "QA do motor de presenca falhou"
    } else {
        Pass "QA do motor de presenca concluido"
    }

    # Sem banco e sem AD: limite de login por usuario e testes negativos.
    & $phpExecutable (Join-Path $repoRoot "scripts\qa-security.php")
    if ($LASTEXITCODE -ne 0) {
        Fail "QA de seguranca (SEC-03 e testes negativos) falhou"
    } else {
        Pass "QA de seguranca concluido"
    }
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
$sensitiveStaged = @($staged | Where-Object {
    $_ -match $sensitivePattern `
        -and $_ -notmatch '^\.env(?:\..+)?\.example$' `
        -and $_ -notmatch '^migrations/'
})
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
        @{ Url = "$BaseUrl/"; Expect = @(302) },
        @{ Url = "$BaseUrl/index.php"; Expect = @(302) },
        @{ Url = "$BaseUrl/login.php"; Expect = @(302) },
        @{ Url = "$BaseUrl/admin_login.php"; Expect = @(302) },
        @{ Url = "$BaseUrl/admin.php"; Expect = @(302) },
        @{ Url = "$BaseUrl/metricas.php"; Expect = @(302) },
        @{ Url = "$BaseUrl/api/status.php"; Expect = @(200) },
        @{ Url = "$BaseUrl/api/session.php"; Expect = @(200) },
        @{ Url = "$BaseUrl/app/"; Expect = @(200) },
        @{ Url = "$BaseUrl/app/admin"; Expect = @(200) },
        @{ Url = "$BaseUrl/app/metricas"; Expect = @(200) },
        @{ Url = "$BaseUrl/app/calendario"; Expect = @(200) },
        @{ Url = "$BaseUrl/app/tecnicos"; Expect = @(200) },
        @{ Url = "$BaseUrl/app/escala-turnos"; Expect = @(200) },
        @{ Url = "$BaseUrl/app/escala-presencial"; Expect = @(200) },
        @{ Url = "$BaseUrl/app/ausencias"; Expect = @(200) },
        @{ Url = "$BaseUrl/app/horas-extras"; Expect = @(200) },
        @{ Url = "$BaseUrl/app/correcao-ponto"; Expect = @(200) },
        @{ Url = "$BaseUrl/app/avisos"; Expect = @(200) },
        @{ Url = "$BaseUrl/app/plantonistas"; Expect = @(200) },
        @{ Url = "$BaseUrl/app/sobreavisos"; Expect = @(200) },
        @{ Url = "$BaseUrl/app/documentacao"; Expect = @(200) },
        @{ Url = "$BaseUrl/app/chamados-criticos"; Expect = @(200) },
        @{ Url = "$BaseUrl/app/relatorios"; Expect = @(200) },
        @{ Url = "$BaseUrl/app/configuracoes"; Expect = @(200) },
        @{ Url = "$BaseUrl/app/integracoes"; Expect = @(200) },
        @{ Url = "$BaseUrl/api/portal/dashboard.php"; Expect = @(401) },
        @{ Url = "$BaseUrl/api/portal/calendar.php"; Expect = @(401) },
        @{ Url = "$BaseUrl/api/portal/schedules.php"; Expect = @(401) },
        @{ Url = "$BaseUrl/api/portal/overtime.php"; Expect = @(401) },
        @{ Url = "$BaseUrl/api/portal/time_corrections.php"; Expect = @(401) },
        @{ Url = "$BaseUrl/api/portal/oncall.php"; Expect = @(401) },
        @{ Url = "$BaseUrl/api/portal/reports.php"; Expect = @(401) },
        @{ Url = "$BaseUrl/api/portal/documents.php"; Expect = @(401) },
        @{ Url = "$BaseUrl/api/portal/critical_incidents.php"; Expect = @(401) },
        @{ Url = "$BaseUrl/api/portal/critical_incidents_export.php"; Expect = @(401) },
        @{ Url = "$BaseUrl/api/configuracoes.php"; Expect = @(401) },
        @{ Url = "$BaseUrl/.env"; Expect = @(403, 404) },
        @{ Url = "$BaseUrl/.git/config"; Expect = @(403, 404) },
        @{ Url = "$BaseUrl/frontend/src/App.jsx"; Expect = @(403, 404) },
        @{ Url = "$BaseUrl/frontend/package.json"; Expect = @(403, 404) },
        @{ Url = "$BaseUrl/frontend/vite.config.js"; Expect = @(403, 404) },
        @{ Url = "$BaseUrl/services/PortalService.php"; Expect = @(403, 404) },
        @{ Url = "$BaseUrl/services/OperationalService.php"; Expect = @(403, 404) },
        @{ Url = "$BaseUrl/services/DocumentService.php"; Expect = @(403, 404) },
        @{ Url = "$BaseUrl/services/SharePointSyncService.php"; Expect = @(403, 404) },
        @{ Url = "$BaseUrl/services/MailerService.php"; Expect = @(403, 404) },
        @{ Url = "$BaseUrl/services/AdCredentialProvider.php"; Expect = @(403, 404) },
        @{ Url = "$BaseUrl/services/CriticalIncidentService.php"; Expect = @(403, 404) },
        @{ Url = "$BaseUrl/migrations/20260612_001_portal_foundation.sql"; Expect = @(403, 404) },
        @{ Url = "$BaseUrl/migrations/20260613_003_operational_modules.sql"; Expect = @(403, 404) },
        @{ Url = "$BaseUrl/migrations/20260613_004_operational_hardening.sql"; Expect = @(403, 404) },
        @{ Url = "$BaseUrl/migrations/20260613_005_documents_and_employee_roles.sql"; Expect = @(403, 404) },
        @{ Url = "$BaseUrl/migrations/20260614_006_critical_incidents.sql"; Expect = @(403, 404) },
        @{ Url = "$BaseUrl/database_SECURED.sql"; Expect = @(403, 404) },
        @{ Url = "$BaseUrl/README.md"; Expect = @(403, 404) },
        @{ Url = "$BaseUrl/backup.bak"; Expect = @(403, 404) },
        @{ Url = "$BaseUrl/archive.zip"; Expect = @(403, 404) },
        @{ Url = "$BaseUrl/secret.csv"; Expect = @(403, 404) },
        @{ Url = "$BaseUrl/secret.json"; Expect = @(403, 404) },
        @{ Url = "$BaseUrl/secret.xlsx"; Expect = @(403, 404) },
        @{ Url = "$BaseUrl/secret.doc"; Expect = @(403, 404) },
        @{ Url = "$BaseUrl/secret.docx"; Expect = @(403, 404) },
        @{ Url = "$BaseUrl/uploads/test.pdf"; Expect = @(403, 404) }
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

    try {
        $legacyPost = Invoke-WebRequest `
            -Uri "$BaseUrl/login.php" `
            -Method POST `
            -ContentType "application/x-www-form-urlencoded" `
            -Body "" `
            -UseBasicParsing `
            -MaximumRedirection 0 `
            -ErrorAction Stop
        $legacyPostStatus = [int]$legacyPost.StatusCode
    } catch {
        if ($_.Exception.Response) {
            $legacyPostStatus = [int]$_.Exception.Response.StatusCode
        } else {
            Fail "Falha ao validar POST legado: $($_.Exception.Message)"
            $legacyPostStatus = 0
        }
    }
    if ($legacyPostStatus -ge 300 -and $legacyPostStatus -lt 400) {
        Fail "POST legado foi redirecionado com HTTP $legacyPostStatus"
    } elseif ($legacyPostStatus -gt 0) {
        Pass "POST legado nao foi redirecionado (HTTP $legacyPostStatus)"
    }

    try {
        Invoke-WebRequest `
            -Uri "$BaseUrl/api/logout.php" `
            -Method POST `
            -ContentType "application/json" `
            -Headers @{ "X-CSRF-Token" = "invalid-token" } `
            -Body "{}" `
            -UseBasicParsing `
            -ErrorAction Stop | Out-Null
        Fail "POST sem CSRF válido foi aceito"
    } catch {
        if ($_.Exception.Response -and [int]$_.Exception.Response.StatusCode -eq 403) {
            Pass "POST com CSRF inválido retornou HTTP 403"
        } else {
            Fail "Teste de CSRF retornou resposta inesperada: $($_.Exception.Message)"
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
