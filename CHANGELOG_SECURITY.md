# 🛡️ Security Changelog — ChronoDesk v2.1.0 (SECURED)

## Arquivos Novos
| Arquivo | Descrição |
|---------|-----------|
| `.env.example` | Template de variáveis de ambiente (copiar para `.env`) |
| `.htaccess` | Bloqueia acesso a `.json`, `.csv`, `.sql`, `.env`, etc. |
| `static/js/csrf_fetch.js` | Wrapper fetch() com CSRF automático |
| `database_SECURED.sql` | Schema atualizado com tabela `audit_log` |
| `CHANGELOG_SECURITY.md` | Este arquivo |

## Arquivos Modificados

### 🔴 Correções Críticas
| Arquivo | VULN | Correção |
|---------|------|----------|
| `config.php` | VULN-001 | SECRET_KEY carregada de `.env` (fixa) |
| `config.php` | VULN-002 | Credenciais DB via variáveis de ambiente |
| `config.php` | VULN-004 | CORS com lista explícita de origens |
| `config.php` | VULN-025 | `limpar_estado_antigo()` reseta pausas individualmente |
| `security.php` | VULN-006 | Rate limiting por IP (file-based, não contornável) |
| `api/aprovar_pausa.php` | VULN-003 | Autenticação obrigatória (`verificar_login()`) |
| `api/rejeitar_pausa.php` | VULN-003 | Autenticação obrigatória |
| `api/solicitacoes_pendentes.php` | VULN-003 | Autenticação obrigatória |
| Todos endpoints POST | VULN-005 | CSRF token via `require_csrf_token()` |
| `.htaccess` | VULN-007 | Bloqueia acesso direto a arquivos sensíveis |

### 🟠 Correções Altas
| Arquivo | VULN | Correção |
|---------|------|----------|
| `api/alterar_senha_admin.php` | VULN-008 | Removido `sanitize_input()` da senha |
| `config.php` | VULN-009 | Timeout absoluto (8h/4h) + inatividade (30m/20m) |
| `api/usuarios.php` | VULN-011 | Impede exclusão do último admin |
| `iniciar_SECURED.sh` | VULN-012 | Removidas credenciais do output |
| `security.php` | VULN-013 | CSP com suporte a nonce |
| `config.php` | VULN-014 | `AUTH_SOURCE` configurável via `.env` |

### 🟡 Correções Médias
| Arquivo | VULN | Correção |
|---------|------|----------|
| `security.php` | VULN-015 | HSTS + redirect HTTP→HTTPS em produção |
| `security.php` | VULN-017 | Sistema de auditoria (`audit_log()`) |
| `GerenciadorPausas_PATCH.php` | VULN-018 | `LOCK_EX` em `salvar_estado()` |
| Endpoints API | VULN-019 | `validate_motivo_pausa()` + validação centralizada |
| `config_assets.php` | VULN-020 | DEBUG via variável de ambiente |
| `security.php` | VULN-022 | `require_json_content_type()` em POSTs |

### 🔵 Correções Baixas
| Arquivo | VULN | Correção |
|---------|------|----------|
| `csrf_fetch.js` | VULN-021 | Controle centralizado de intervals |
| `csrf_fetch.js` | VULN-020 | `debugLog()` condicional |

## Como Aplicar

### Passo 1: Configurar ambiente
```bash
cp .env.example .env
# Editar .env com valores reais
php -r "echo bin2hex(random_bytes(32));"  # Gerar SECRET_KEY
```

### Passo 2: Criar usuário MySQL dedicado
```sql
CREATE USER 'chronodesk_app'@'localhost' IDENTIFIED BY 'SuaSenhaForte!';
GRANT SELECT, INSERT, UPDATE, DELETE ON sistema_pausas.* TO 'chronodesk_app'@'localhost';
FLUSH PRIVILEGES;
```

### Passo 3: Importar tabela de auditoria
```sql
SOURCE database_SECURED.sql;
```

### Passo 4: Substituir arquivos
Copiar os arquivos desta pasta para o projeto, substituindo os originais.

### Passo 5: Aplicar patch do GerenciadorPausas
Editar `classes/GerenciadorPausas.php` e adicionar `LOCK_EX` no `salvar_estado()`.

### Passo 6: Incluir csrf_fetch.js nas páginas HTML
Adicionar ANTES dos outros scripts em index.php, metricas.php, admin.php:
```html
<meta name="csrf-token" content="<?php echo generate_csrf_token(); ?>">
<meta name="app-debug" content="<?php echo APP_DEBUG ? 'true' : 'false'; ?>">
<script src="static/js/csrf_fetch.js"></script>
```
