# ChronoDesk - Plano de Testes da Fase 5

## Escopo

Plano de testes para ChronoDesk PHP 8 / XAMPP / MySQL / JavaScript Vanilla, cobrindo fluxos CI, gestor/metricas e admin.

## Ambientes

- Local: Windows + XAMPP + MySQL.
- Base URL sugerida: `http://localhost/chronodesk_v3`.
- PHP: `C:\xampp\php\php.exe`.
- Navegadores: Chrome/Edge atuais.

## Testes Funcionais

| ID | Cenario | Passos | Resultado esperado |
| --- | --- | --- | --- |
| F-001 | Login CI via AD | Acessar `index.php`, informar login/senha AD validos | Dashboard CI aparece com nome da sessao |
| F-002 | Logout CI | Com CI logado, clicar em Sair | Sessao CI encerrada e tela de login CI exibida |
| F-003 | Iniciar pausa | Logar como CI, selecionar o proprio CI e motivo Cafe/Pessoal | Pausa iniciada e status atualizado |
| F-004 | Finalizar pausa | Com pausa ativa do proprio CI, finalizar | Registro persistido e status volta a disponivel |
| F-005 | Solicitar reuniao | Selecionar Reuniao, preencher observacao e solicitar | Solicitacao fica pendente |
| F-006 | Aprovar pausa | Logar em metricas como gestor/admin e aprovar solicitacao | Solicitacao muda para aprovada |
| F-007 | Rejeitar pausa | Logar em metricas como gestor/admin e rejeitar solicitacao | Solicitacao e removida/rejeitada |
| F-008 | Login admin AD | Acessar `admin_login.php` com admin AD autorizado | Painel admin abre |
| F-009 | Login admin local | Se habilitado, logar com admin local valido | Painel admin abre |
| F-010 | CRUD funcionarios | Admin cria, edita, desativa e lista funcionario | Operacoes persistem e respeitam validacoes |
| F-011 | CRUD usuarios | Admin cria gestor/admin e remove usuario nao critico | Operacoes persistem e ultimo admin nao e removido |
| F-012 | Download relatorio | Gestor acessa metricas e baixa relatorio | ZIP e baixado com CSVs esperados |

## Testes de Autenticacao

| ID | Cenario | Resultado esperado |
| --- | --- | --- |
| A-001 | Credenciais CI invalidas | HTTP 401/JSON com mensagem generica |
| A-002 | Usuario AD sem vinculo com funcionario | HTTP 401/403 sem criar sessao CI |
| A-003 | Admin local com role gestor em `admin_login.php` | Acesso negado |
| A-004 | Login gestor em `login.php` | Acesso permitido apenas a metricas |
| A-005 | Rate limit admin/gestor/CI | Apos limite, HTTP 429 ou mensagem de bloqueio |
| A-006 | `session_regenerate_id` apos login | ID de sessao muda apos login bem-sucedido |
| A-007 | Timeout gestor/admin | Sessao expira por inatividade/tempo absoluto |
| A-008 | Timeout CI | Sessao CI expira conforme politica da aplicacao |

## Testes de Autorizacao

| ID | Cenario | Resultado esperado |
| --- | --- | --- |
| Z-001 | Acessar `admin.php` sem sessao admin | Redireciona para `admin_login.php` |
| Z-002 | POST admin sem sessao admin | Redireciona/nega acesso |
| Z-003 | Acessar `metricas.php` sem login gestor | Redireciona para `login.php` |
| Z-004 | Aprovar/rejeitar sem login gestor | Redireciona/nega acesso |
| Z-005 | CI tenta iniciar pausa para outro `funcionario_id` | HTTP 403 JSON |
| Z-006 | CI tenta finalizar pausa de outro `funcionario_id` | HTTP 403 JSON |
| Z-007 | Admin endpoint chamado apenas pelo frontend alterado | Backend deve negar sem sessao admin |

## Testes de CSRF

| ID | Cenario | Resultado esperado |
| --- | --- | --- |
| C-001 | POST `api/login_ci.php` sem token | HTTP 403 JSON |
| C-002 | POST `api/iniciar_pausa.php` sem token | HTTP 403 JSON |
| C-003 | POST `api/finalizar_pausa.php` sem token | HTTP 403 JSON |
| C-004 | POST `api/solicitar_pausa_com_aprovacao.php` sem token | HTTP 403 JSON |
| C-005 | POST `api/aprovar_pausa.php` sem token | HTTP 403 JSON |
| C-006 | POST `api/rejeitar_pausa.php` sem token | HTTP 403 JSON |
| C-007 | POST CRUD admin sem token | HTTP 403 JSON |
| C-008 | Token emitido em HTML | Meta `csrf-token` ou input oculto presente |
| C-009 | Token enviado pelo JS | Header `X-CSRF-Token` presente em POST same-origin |

## Testes de IDOR/BOLA

| ID | Cenario | Resultado esperado |
| --- | --- | --- |
| I-001 | Alterar `funcionario_id` no iniciar pausa para outro CI | HTTP 403 |
| I-002 | Alterar `funcionario_id` no finalizar pausa para outro CI | HTTP 403 |
| I-003 | Credenciais AD de um CI com ID de outro | HTTP 403 |
| I-004 | Endpoint `listar_funcionarios.php` sem admin | Nao retorna `ad_login` |
| I-005 | Endpoint `listar_funcionarios.php` com admin | Retorna `ad_login` apenas para admin |

## Testes de XSS

| ID | Payload | Locais | Resultado esperado |
| --- | --- | --- | --- |
| X-001 | `<script>alert(1)</script>` | Nome funcionario | Rejeitado ou renderizado escapado |
| X-002 | `"><img src=x onerror=alert(1)>` | Username/AD login/observacao | Rejeitado ou escapado |
| X-003 | `' onclick='alert(1)` | Campos usados em handlers admin | Rejeitado ou escapado |
| X-004 | Dados vindos do banco em metricas | Dashboard metricas | Sem execucao de HTML/JS |
| X-005 | Mensagens de erro da API | Areas de mensagem | Escape antes de `innerHTML` |

## Testes de SQL Injection

| ID | Payload | Campos | Resultado esperado |
| --- | --- | --- | --- |
| S-001 | `' OR '1'='1` | Login admin/gestor | Nao autentica |
| S-002 | `1 OR 1=1` | `funcionario_id` | Rejeitado por validacao inteira |
| S-003 | `admin', role='admin` | Username | Sem alteracao indevida |
| S-004 | `n1' UNION SELECT` | Equipe | Rejeitado |
| S-005 | Payload persistido em nome/observacao | Fluxo completo | Sem SQL dinamico inseguro |

## Testes de CORS

| ID | Cenario | Resultado esperado |
| --- | --- | --- |
| O-001 | Origin permitido em `.env` | Header `Access-Control-Allow-Origin` ecoa origem |
| O-002 | Origin nao permitido | Sem header CORS |
| O-003 | Preflight OPTIONS | HTTP 204 somente para origens configuradas |
| O-004 | Credenciais cross-origin nao permitidas por wildcard | Nunca usar `*` com credenciais |

## Testes de Sessao

| ID | Cenario | Resultado esperado |
| --- | --- | --- |
| SS-001 | Cookie HttpOnly | Flag presente |
| SS-002 | SameSite | Valor Strict/Lax/None conforme ambiente |
| SS-003 | Secure em HTTPS/producao | Flag presente quando HTTPS |
| SS-004 | Logout gestor/admin | Sessao destruida e cookie expirado |
| SS-005 | Logout CI | Dados CI removidos da sessao |
| SS-006 | Sessao CI inativa | Acoes de pausa negadas apos timeout |

## Testes de Arquivos Sensiveis

| ID | URL | Resultado esperado |
| --- | --- | --- |
| FS-001 | `/.env` | 403/404 |
| FS-002 | `/.git/config` | 403/404 |
| FS-003 | `/database_SECURED.sql` | 403/404 |
| FS-004 | `/pausas.csv` | 403/404 |
| FS-005 | `/config_sistema.json` | 403/404 |
| FS-006 | `/README.md` | 403/404 |
| FS-007 | `/migrar_csv_para_mysql.php` | 403/404 |
| FS-008 | `/classes/Usuario.php` | 403/404 |

## Testes de Deploy

| ID | Cenario | Resultado esperado |
| --- | --- | --- |
| D-001 | `APP_ENV=production` sem `SECRET_KEY` | Aplicacao falha de forma segura |
| D-002 | Producao com `APP_DEBUG=true` | Aplicacao bloqueia configuracao |
| D-003 | Producao com DB root/senha vazia | Aplicacao bloqueia configuracao |
| D-004 | `.htaccess` ativo | Arquivos sensiveis bloqueados |
| D-005 | Extensoes PHP necessarias | PDO MySQL, LDAP e Zip habilitadas |
| D-006 | Logs | Erros e auditoria gerados sem secrets |
