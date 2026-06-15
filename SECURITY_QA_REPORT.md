# Portal SDK - Relatorio de Seguranca e QA da Fase 5

## Resumo Executivo

A revisao foi realizada sobre a base `0679752 Aplica hardening de seguranca para producao`, com foco em OWASP Top 10, ASVS e boas praticas de desenvolvimento seguro.

O hardening da Fase 4 esta presente: CSRF centralizado, sessoes com flags, headers de seguranca, CORS restrito, rate limit, auditoria, validacoes e bloqueios Apache para arquivos sensiveis.

Foi encontrada uma lacuna real de session management no fluxo CI: a sessao CI nao tinha timeout proprio de aplicacao. A recomendacao e aplicar timeout absoluto e por inatividade tambem para a sessao CI, mantendo compatibilidade com AD, CSRF e o fluxo atual.

## Arquivos Analisados

- Raiz: `index.php`, `login.php`, `logout.php`, `admin_login.php`, `admin_logout.php`, `admin.php`, `metricas.php`.
- Seguranca/configuracao: `security.php`, `config.php`, `init.php`, `db.php`, `auth_ldap.php`, `.htaccess`, `.env.example`.
- API: `api/*.php`.
- Dominio: `classes/Funcionario.php`, `classes/GerenciadorPausas.php`, `classes/Usuario.php`.
- Frontend: `static/js/script.js`, `static/js/admin.js`, `static/js/admin_users.js`, `static/js/metricas.js`, `static/js/csrf_fetch.js`, `static/js/theme.js`.
- Dados/scripts: `database.sql`, `database_SECURED.sql`, `update_users_table.sql`, scripts de setup/migracao.

## Mapeamento de Endpoints

| Endpoint | Metodo | Exposicao | Controle observado |
| --- | --- | --- | --- |
| `/index.php` | GET | Publico com tela CI | Emite CSRF; dashboard depende de sessao CI |
| `/api/status.php` | GET | Publico operacional | Sem auth por design; retorna status operacional |
| `/api/listar_funcionarios.php` | GET | Publico/admin | Sem auth; oculta `ad_login` exceto admin |
| `/api/login_ci.php` | POST | Publico sensivel | CSRF, JSON, rate limit, AD |
| `/api/logout_ci.php` | POST | CI | CSRF, JSON |
| `/api/iniciar_pausa.php` | POST | CI | CSRF, JSON, identidade CI/AD |
| `/api/finalizar_pausa.php` | POST | CI | CSRF, JSON, identidade CI/AD |
| `/api/solicitar_pausa_com_aprovacao.php` | POST | CI | CSRF, JSON, identidade CI/AD |
| `/login.php` | GET/POST | Gestor/metricas | CSRF no POST, rate limit, sessao |
| `/metricas.php` | GET | Gestor/admin | `verificar_login()` |
| `/api/metricas.php` | GET | Gestor/admin | `verificar_login()` |
| `/api/solicitacoes_pendentes.php` | GET | Gestor/admin | `verificar_login()` |
| `/api/aprovar_pausa.php` | POST | Gestor/admin | `verificar_login()`, CSRF, JSON |
| `/api/rejeitar_pausa.php` | POST | Gestor/admin | `verificar_login()`, CSRF, JSON |
| `/api/download_relatorio.php` | GET | Gestor/admin | GET-only, `verificar_login()` |
| `/admin_login.php` | GET/POST | Admin | CSRF no POST, rate limit, AD/local |
| `/admin.php` | GET | Admin | `verificar_admin_login()` |
| `/api/adicionar_funcionario.php` | POST | Admin | `verificar_admin_login()`, CSRF, JSON |
| `/api/atualizar_funcionario.php` | POST | Admin | `verificar_admin_login()`, CSRF, JSON |
| `/api/remover_funcionario.php` | POST | Admin | `verificar_admin_login()`, CSRF, JSON |
| `/api/salvar_configuracao.php` | POST | Admin | `verificar_admin_login()`, CSRF, JSON |
| `/api/alterar_senha_admin.php` | POST | Admin | `verificar_admin_login()`, CSRF, JSON |
| `/api/usuarios.php` | GET/POST | Admin | `verificar_admin_login()`, CSRF nos POSTs |

## Fluxos Principais

- Login CI via AD: `index.php` emite token, `static/js/script.js` chama `api/login_ci.php`, backend autentica em `auth_ldap.php`, vincula `ad_login` ao funcionario ativo e grava sessao CI.
- Logout CI: `static/js/script.js` chama `api/logout_ci.php`, que remove chaves `ci_*`.
- Iniciar/finalizar pausa: JS envia `funcionario_id`; backend valida ID, CSRF, JSON e chama `exigir_autenticacao_ci_pausa()`, que compara sessao/credenciais AD com o funcionario alvo.
- Solicitar pausa com aprovacao: mesmo controle CI, motivo validado e observacao limitada.
- Login admin: `admin_login.php` tenta local habilitado e AD autorizado por allowlist.
- Login gestor/metricas: `login.php` autentica usuario local e cria sessao `logged_in`.
- CRUD funcionarios: endpoints admin alteram MySQL/fallback por funcoes validadas.
- CRUD usuarios: `api/usuarios.php` exige admin e protege exclusao do ultimo admin.
- Download relatorio: `api/download_relatorio.php` exige login de metricas e gera ZIP temporario fora do webroot.

## Vulnerabilidades Encontradas

| ID | Severidade | Status | Evidencia | Impacto | Recomendacao |
| --- | --- | --- | --- | --- | --- |
| V5-001 | Media | Corrigido | `api/login_ci.php` agora define `ci_login_time` e `ci_last_activity`; `auth_ldap.php::ci_sessao_autenticada_para_funcionario()` valida expiracao antes de permitir acoes CI; `api/logout_ci.php` remove os campos de timeout. | Em terminal compartilhado, sessao CI poderia continuar valida alem da politica de aplicacao enquanto a sessao PHP existisse. | Manter `CI_SESSION_ABSOLUTE_TIMEOUT` e `CI_SESSION_IDLE_TIMEOUT` ajustados por ambiente quando necessario. |

## Itens Aceitos Como Risco Residual

| ID | Severidade | Evidencia | Justificativa | Recomendacao futura |
| --- | --- | --- | --- | --- |
| R-001 | Baixa | `api/status.php` e `api/listar_funcionarios.php` sao publicos. | O comentario do endpoint declara uso operacional em terminal compartilhado. `listar_funcionarios.php` nao retorna `ad_login` sem admin. | Se o ambiente deixar de ser terminal compartilhado, exigir sessao CI ou reduzir campos publicos. |
| R-002 | Baixa | CSP mantem `unsafe-inline`. | Comentario em `security.php` indica compatibilidade com handlers inline existentes. | Remover handlers inline e eliminar `unsafe-inline` em fase futura. |
| R-003 | Baixa | Fallback JSON/local admin existe por configuracao. | Necessario para contingencia/migracao; producao pode desabilitar via `.env`. | Em producao madura, usar `AUTH_SOURCE=mysql` e `ENABLE_LOCAL_ADMIN=false` quando operacionalmente viavel. |

## Evidencias Positivas

- CSRF: `require_csrf_token()` retorna JSON em falha e os POSTs sensiveis revisados chamam a funcao.
- SQL Injection: consultas com entrada externa usam `prepare()` e parametros. `query()`/`exec()` encontrados sao DDL ou SQL fixo.
- XSS: saidas PHP revisadas usam `htmlspecialchars`; JS revisado usa `escapeHtml` antes de dados variaveis em `innerHTML`.
- IDOR/BOLA: acoes CI usam identidade de sessao/AD para conferir o `funcionario_id`.
- Arquivos sensiveis: `.htaccess` bloqueia extensoes e diretorios criticos.
- Sessoes: admin/gestor regeneram ID apos login e possuem timeout; cookies usam HttpOnly/SameSite/Secure conforme ambiente.
- CORS: origem e ecoada apenas quando esta em allowlist.
- Rate limit: login admin, gestor e CI possuem limitacao file-based por IP/chave.

## Proximos Passos

1. Rodar lint PHP/JS e checks de diff.
2. Executar o plano `TEST_PLAN.md` em XAMPP com AD/MySQL reais.
3. Validar via DevTools os headers, cookies e CSRF.
4. Revisar risco residual dos endpoints publicos antes de exposicao fora da rede controlada.
