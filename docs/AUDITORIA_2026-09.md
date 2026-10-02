# Auditoria Técnica — ChronoDesk / Portal SDK

**Data:** 2026-09-28
**Fase:** 0 — auditoria (concluída) · **Lote 1 aplicado em 2026-09-28**
**Revisão:** 2026-09-28 — removido o achado sobre documentos de contexto fora do repositório (intencional, decisão do responsável); evidência do SEC-01 corrigida; SEC-04 verificado
**Branch / HEAD:** `codex/modernizacao-ui-backend` / `596db67`
**Escopo:** backend PHP, frontend React, migrations, `.htaccess`, QA, aderência aos requisitos corporativos
**Responsável pela execução:** Claude Opus 5 (Claude Code), sob revisão humana de Matheus Camargo

> **Sanitização:** este documento não reproduz domínios internos, hostnames de AD, IPs, usuários nominais nem segredos. Onde a evidência é a própria exposição de um valor sensível, é citado apenas `arquivo:linha`, com descrição genérica (`<AD_DOMAIN>`, `<AD_SERVER_1>`, `<APP_INTERNAL_IP>`).

---

## 1. Resumo executivo

O ChronoDesk está **acima da média** em controles de aplicação. A auditoria confirmou, com evidência de código, que os pontos historicamente mais frágeis deste tipo de sistema já estão tratados:

- **Sem SQL injection.** 100% do acesso a dados usa prepared statements; os únicos pontos com interpolação em SQL (`OperationalService`, `PortalService`, `DocumentService`) resolvem tabela e colunas a partir de `match`/constantes com `throw` no default, nunca de entrada do usuário. `PDO::ATTR_EMULATE_PREPARES=false` e `ERRMODE_EXCEPTION` confirmados em `db.php:25-27`.
- **CSRF completo.** Todos os endpoints que alteram estado exigem token válido, direta ou indiretamente via `portal_json_input()` (`api/portal/_bootstrap.php:57`). A varredura endpoint a endpoint não encontrou nenhuma rota de escrita sem verificação.
- **Bind LDAP com senha vazia é rejeitado** antes do `ldap_bind` (`auth_ldap.php:56`); filtros usam `ldap_escape`; o bind é por UPN, sem concatenação de DN. O bypass clássico de AD **não se aplica**.
- **Upload/download de documentos é robusto:** allowlist de extensão, validação MIME por `finfo`, nome aleatório de 64 hex, armazenamento fora do webroot com verificação explícita, proteção contra zip bomb e macros Office, verificação de integridade SHA-256 e `Content-Disposition: attachment` + `nosniff` (`services/DocumentService.php`).
- **Exports CSV neutralizam injeção de fórmula** (`= + - @`) nos três caminhos de exportação.
- **Frontend React cumpre as regras do projeto:** zero `innerHTML`, zero `dangerouslySetInnerHTML`, zero `localStorage`, `fetch()` centralizado em `lib/api.ts`. `npm run lint` e `npm run typecheck` passam sem erro; 12 dos 13 scripts `qa:*` passam.
- **Concorrência de pausas e de aprovações está protegida no servidor:** `with_pause_state_lock()` (flock exclusivo) envolve todo o ciclo read-modify-write das pausas, e `decideWorkflow()` usa transação + `SELECT ... FOR UPDATE` + bloqueio de autoaprovação.

O risco real **não está no código de aplicação — está na fronteira de infraestrutura, no ciclo de vida do dado e na governança.** O sistema trata dados pessoais de colaboradores identificados, autentica com credenciais de domínio corporativo e opera sem TLS, sem backup e fora da esteira formal de Engenharia.

### Os 5 riscos principais

| # | Risco | Por que é o mais grave |
|---|---|---|
| 1 | **Credenciais de domínio trafegam sem criptografia** (LDAP 389, `AD_USE_TLS=false`, portal só em HTTP, cookie de sessão sem `Secure`) | Qualquer captura de tráfego na rede interna entrega a senha de AD do colaborador — que é a mesma do Windows, e-mail e VPN. O impacto extrapola o ChronoDesk. |
| 2 | **Topologia do AD corporativo versionada em repositório de conta pessoal** — 9 arquivos, incluindo o bundle do SPA em produção (`LoginCI.jsx`) | Domínio, sufixo UPN e hostnames de DC em texto claro, fora da governança corporativa. **Código sanitizado no Lote 1 (2026-09-28); histórico do git preservado por decisão do responsável — os valores seguem acessíveis em commits anteriores.** |
| 3 | **Dados pessoais de colaboradores identificados versionados e sem backup** (`config.php:466-483`, `database_SECURED.sql:95+`) | Nomes completos no código-fonte + base com pausas, ausências, horas extras e correções de ponto **sem nenhuma rotina de backup**. Exposição LGPD somada a risco de perda irreversível. |
| 4 | **Duas fontes de verdade independentes para o perfil Admin** (`api/login_ci.php:53-63` + regra `equipe='lideranca' ⇒ access_role='admin'`) | A allowlist `AD_ADMIN_USERS` deixa de ser o controle efetivo: mudar a equipe de um colaborador para "Liderança" concede Admin total, silenciosamente. |
| 5 | **Relatório de horas extras soma lançamentos pendentes e rejeitados no total** (`services/OperationalService.php:867-871`) | O CSV é insumo de processo de pagamento. O total por colaborador está inflado sempre que há lançamento não aprovado no período. |

---

## 2. Tabela de achados

Legenda de esforço: **P** ≤ 4h · **M** ≤ 2 dias · **G** > 2 dias.
Coluna "Altera crítico?" = altera regra de negócio, autenticação, RBAC, CSRF ou auditoria.

### 2.1 Críticos

| ID | Categoria | Evidência | Classificação | Impacto | Correção proposta | Esforço | Altera crítico? | Requisito corporativo (§3.8) |
|---|---|---|---|---|---|---|---|---|
| **SEC-01** | Exposição de infraestrutura | **Evidência corrigida em 2026-09-28** — a lista original estava incompleta. Ocorrências reais: `.env.example:18-19`; `.env.production.example:21-23`; `auth_ldap.php:42,136`; `login.php:101`; `admin_login.php:105`; `README.md:114`; `DEPLOY_LINUX.md:248`; `PROMPT_CHATGPT.md:16,337`; **`frontend/src/components/LoginCI.jsx:24` (SPA em produção)** | Fato | Domínio AD, sufixo UPN e hostnames dos dois DCs em texto claro em repositório de conta pessoal. Reconhecimento direto da infraestrutura de identidade. | **Código: RESOLVIDO no Lote 1 (2026-09-28)** — todos os valores substituídos por placeholders e o fallback literal trocado por falha fechada com auditoria. **Pendente:** reescrita do histórico (dispensada pelo responsável) e migração para o repositório oficial. | P (código, feito) / G (governança) | Não | Código no repositório oficial (Azure); credenciais em cofre; documentação técnica |
| **SEC-02** | Criptografia em trânsito | `auth_ldap.php:50-51,61`; `.env.example:21-22`; CONTEXTO §7 | Fato (código) / Hipótese (valores em runtime — confirmar no `.env` do servidor) | Senha de AD do colaborador trafega em claro até o DC. Cookie de sessão sem `Secure` sobre HTTP puro permite captura da sessão. Agravante: `auth_ldap.php:61` monta `ldap://` fixo — definir `AD_PORT=636` **não** habilita LDAPS, então a mitigação está bloqueada no código. | (1) Suportar `ldaps://` derivado da porta/esquema e validar StartTLS; (2) habilitar `AD_USE_TLS=true` após validar o certificado do DC; (3) publicar o ChronoDesk em HTTPS com certificado próprio e então ativar `SESSION_COOKIE_SECURE=true`. Itens 2 e 3 dependem de infraestrutura. | M (código) / G (infra) | **Sim** — autenticação | APIs autenticadas e criptografadas; ambiente homologado |
| **LGPD-01** | Proteção de dados | `config.php:466-483`; `database_SECURED.sql:95+` | Fato | Nome completo de 17 colaboradores identificados versionado no código-fonte, em repositório fora da governança corporativa. A base em produção contém ainda pausas, ausências, horas extras e correções de ponto vinculados a pessoa identificada. | Remover o array de funcionários padrão do `config.php` (a carga já vem do MySQL; o array é apenas último fallback) e substituir os `INSERT` de dados reais por um seed genérico. Inventário de dados pessoais deve ir para `docs/DOCUMENTACAO_TECNICA.md`. | M | Não | Proteção de dados (LGPD); documentação técnica |
| **OPS-01** | Continuidade | Ausência de script/cron de backup no repositório; confirmado no prompt (28/09/2026, item 25) | Fato | Perda total e irreversível da base em falha de disco, corrupção do contêiner ou erro operacional. Não há teste de restauração. | Entregar script de backup diário (`mysqldump --single-transaction` via `docker exec` com usuário de backup de privilégio mínimo + tar dos diretórios privados), retenção configurável, log em syslog e procedimento documentado de restauração. **Gerar para execução humana; não executar.** | M | Não | Ambiente homologado com monitoramento e backup; mudança com rollback |

### 2.2 Altos

| ID | Categoria | Evidência | Classificação | Impacto | Correção proposta | Esforço | Altera crítico? | Requisito corporativo |
|---|---|---|---|---|---|---|---|---|
| **SEC-03** | Força bruta / AD | `security.php:340-344` (`rate_limit_file_path` sempre concatena `REMOTE_ADDR`); consumidores em `api/login_ci.php:21-25`, `api/login_admin.php:21-28`, `admin_login.php:19`, `auth_ldap.php:482-485` | Fato | **Nenhuma chave de rate limit é global por usuário.** `ci_login_global` e `admin_api_global` também são por IP. Password spray distribuído não é contido; pior, tentativas repetidas contra a mesma conta podem disparar o bloqueio de conta no AD corporativo a partir de IPs distintos. | Separar duas dimensões de contador: por IP (como hoje) e **por usuário, independente de IP**, com limiar por usuário abaixo do limite de bloqueio do AD. Persistir o contador por usuário no MySQL (arquivo por IP não serve). **Lote 7 (2026-10-01, commit local): contador por usuario em `login_user_throttle` (migration 015), antes do bind nos cinco pontos que chamam o AD; padrao 5 tentativas sem sucesso, zerado apos 15 min sem tentativa (regra do AD); limite por IP mantido; falha fechada com 503. Ver `docs/LIMITE_LOGIN_POR_USUARIO.md`.** | M | **Sim** — autenticação | Perfis com menor privilégio; tratamento seguro de erros |
| **SEC-04** | RBAC / propagação de privilégio | `api/login_ci.php:53-63`; `config.php:676-678`; `api/adicionar_funcionario.php:7,48-50`; `api/atualizar_funcionario.php:6,46-48`. **Verificação de 2026-09-28 (seção 2.5): severidade mantida em Alto — nenhum perfil não-Admin consegue definir `lideranca`.** | Fato | Login CI de um colaborador com `access_role` admin/gestor promove a sessão a `admin_logged_in`/`portal_role`, **sem passar por `AD_ADMIN_USERS`**. E `equipe='lideranca'` sobrescreve o `access_role` para `admin` em três pontos independentes. Resultado: mudar a equipe de alguém para "Liderança" concede Admin total do portal, sem registro explícito dessa intenção. | Decidir uma fonte de verdade única para o perfil administrativo. Recomendação: manter `AD_ADMIN_USERS` como allowlist obrigatória e tratar `funcionarios.access_role` apenas como perfil funcional (técnico/gestor), exigindo interseção com a allowlist para conceder admin. Remover a regra implícita `lideranca ⇒ admin` ou torná-la explícita na UI com auditoria `CRITICAL`. **Requer aprovação — altera RBAC.** **Lote 5 (decisões L1 a L9 aprovadas em 2026-10-02; 5a em commit local `91a57a6`): admin só pela allowlist, normalizada como o bind, em todos os logins; `access_role = 'admin'` legado vira `lideranca` ou `gestor`; perfil revalidado a cada requisição. 5b (commits locais de 2026-10-02): a regra `lideranca ⇒ admin` saiu dos endpoints, do `config.php` e do formulário; só admin promove à Liderança, com auditoria CRITICAL; `access_role = 'admin'` não é mais gravável. Ver `docs/DESENHO_PERFIL_LIDERANCA.md`.** | M | **Sim** — RBAC | Perfis com menor privilégio; autenticação AD com usuários nominais |
| **SEC-05** | Configuração | `config.php:58,123-138` | Fato | Todos os guard-rails de produção (`APP_DEBUG` proibido, usuário MySQL dedicado obrigatório, `AD_ADMIN_USERS` obrigatório) só executam quando `APP_ENV === 'production'`. O ambiente atual roda `APP_ENV=staging` (CONTEXTO §7) e o default do código é `development`: a configuração **falha aberta**. Com `APP_DEBUG=true`, `public_error_message()` passa a devolver a mensagem bruta da exceção ao cliente. | Inverter a lógica: aplicar os guard-rails em qualquer ambiente que não seja explicitamente `development`, e reconciliar `APP_ENV` com o ambiente real. | P | Não | Tratamento seguro de erros; ambiente homologado |
| **BIZ-01** | Regra de negócio | `services/OperationalService.php:865-901` (acumulador `$totalsByEmployee`) + `:945-961` (`listWorkflowRecords` só filtra `status` se informado); `api/portal/overtime.php:11-15` não injeta filtro | Fato | A coluna de total por colaborador no CSV de horas extras soma **pendentes e rejeitadas** junto com as aprovadas. Como o CSV alimenta processo de pagamento, o total sai inflado sempre que há lançamento não aprovado no período. | Calcular o total apenas sobre `status = 'approved'`, ou emitir colunas separadas (total aprovado / total no período). **Requer confirmação do negócio sobre qual é o total esperado.** **Lote 6 (2026-09-30, commit local): total aprovado e total pendente em colunas separadas; rejeitados fora de todo total (CSV, tela e relatorio). Ver `docs/HORAS_EXTRAS_LOTE6.md`.** | P | **Sim** — regra de negócio | Homologação com evidências |
| **BIZ-02** | Fuso horário | `config.php:53` (`America/Sao_Paulo`); `db.php:23-28` (DSN sem `SET time_zone`); usos de `CURRENT_DATE` em `services/OperationalService.php:376,1420` e `services/CriticalIncidentService.php:240`; `NOW()` em `:1007` | Fato (ausência de `SET time_zone`) / Hipótese (fuso efetivo do contêiner — confirmar) | PHP opera em BRT; a sessão MySQL nunca define fuso e o contêiner `mysql:8.0` tipicamente roda em UTC. Entre 21:00 e 00:00 (BRT), `CURRENT_DATE` já é o dia seguinte: a remoção de escala grava `effective_until` um dia adiante do pretendido, `room_date` de chamado crítico nasce no dia errado e `approved_at` fica 3h à frente. | Fonte única de tempo: definir `SET time_zone = '-03:00'` (ou `America/Sao_Paulo`, se as tabelas de fuso estiverem carregadas no contêiner) via `PDO::MYSQL_ATTR_INIT_COMMAND` em `db.php`, e adicionar teste de regressão na virada do dia. **Hipótese confirmada no servidor em 2026-09-30 (sessão em UTC, 180 min de diferença). Código: implementado no lote de fuso (`9957496`, aprovado em 2026-09-30), aguardando deploy. Pendente: correção dos `DATETIME` históricos, por script separado. Ver `docs/FUSO_HORARIO_BIZ02.md`.** | P (código) + P (validação) | **Sim** — regra de negócio | Homologação com evidências |
| **BIZ-03** | Modelo de dados | `migrations/20260613_003_operational_modules.sql:17` (`UNIQUE KEY uq_schedule_rule_employee (employee_id)`); `services/OperationalService.php:320-337` (upsert) | Fato | As colunas `effective_from`/`effective_until` sugerem histórico, mas a UNIQUE por colaborador permite **uma única linha**. Não existe histórico de escala: relatórios e o mapa de PA de datas passadas são calculados com a regra vigente hoje. Toda reconstituição histórica de presencial/remoto é incorreta após qualquer mudança de escala. | Decisão de negócio: (a) aceitar e documentar que a escala não tem histórico, ou (b) migrar para `UNIQUE(employee_id, effective_from)` com encerramento da linha anterior e leitura por data de referência. Opção (b) exige migration com rollback e revisão de todos os consumidores. | G | **Sim** — regra de negócio | Homologação com evidências; documentação técnica |
| **OPS-02** | Observabilidade | Ausência de `api/health.php` no repositório | Fato | Não há endpoint de saúde. O incidente confirmado de 503 (socket PHP-FPM, CONTEXTO §5) só foi percebido pelo usuário final. Sem sinal de disponibilidade não há monitoramento possível. | Criar `api/health.php` com resposta mínima (status + banco ok/falha), sem versões, caminhos ou dados, com acesso restrito por IP/rede de monitoração. | P | Não | Ambiente homologado com monitoramento |

### 2.5 Verificação solicitada — SEC-04: quem pode alterar a equipe de um colaborador

**Pergunta:** algum perfil não-Admin consegue definir `equipe = 'lideranca'` (e, por consequência, `access_role = 'admin'`)?

**Resposta: não.** Severidade mantida em **Alto** — não há reclassificação para Crítico.

#### Caminhos de escrita em `funcionarios.equipe` — inventário completo

| # | Caminho | Guarda de acesso | Alcançável por não-Admin? |
|---|---|---|---|
| 1 | `api/adicionar_funcionario.php:108,115` | `verificar_admin_login_api()` (linha 7) | **Não** |
| 2 | `api/atualizar_funcionario.php:103,116` | `verificar_admin_login_api()` (linha 6) | **Não** |
| 3 | `config.php:593-614` (`salvar_funcionarios_mysql`) | Nenhuma guarda HTTP — mas só é chamado por `salvar_funcionarios_sistema()`, e este apenas em `config.php:495`, dentro de `carregar_funcionarios_sistema()`, **quando MySQL e JSON retornam zero registros** (seed de emergência com os 17 nomes fixos) | **Não** — não aceita entrada do usuário |
| 4 | `migrar_funcionarios_json_para_mysql.php:119-139` | Script CLI; bloqueado por HTTP em `.htaccess:43` (`^migrar_.*\.php$` → `F`) | **Não** |
| 5 | Importação de escalas (`OperationalService::previewScheduleImport`, linhas 520-528) | — | **Não.** A coluna `equipe`/`team` da planilha é apenas **lida para validação** (`'Equipe divergente do cadastro.'`). A importação grava em `portal_schedule_rules`, **nunca** em `funcionarios.equipe`. |
| 6 | `classes/GerenciadorPausas.php:686` | Em memória apenas, chamado a partir do caminho 2 | **Não** |

`verificar_admin_login_api()` (`config.php:342-351`) exige `$_SESSION['admin_logged_in'] === true`. Um `gestor` autenticado tem essa chave definida como `false` (`api/login_ci.php:55`, `api/login_admin.php:73`), portanto recebe **403**. Confirmado.

#### O risco real, reafirmado com precisão

Não é escalada a partir de um perfil inferior — é **propagação de privilégio fora da allowlist**:

1. Admin legítimo (presente em `AD_ADMIN_USERS`) define `equipe='lideranca'` para o colaborador X → `access_role` é forçado a `admin` em `api/atualizar_funcionario.php:46-48`.
2. X faz login pelo fluxo CI (`api/login_ci.php`) e recebe `admin_logged_in = true` (linha 55) **sem nunca constar em `AD_ADMIN_USERS`**.
3. X agora passa em `verificar_admin_login_api()` e pode promover outros colaboradores da mesma forma.

O conjunto efetivo de administradores é `AD_ADMIN_USERS ∪ {colaboradores com access_role='admin'}`, e o segundo conjunto se autoexpande. A allowlist deixa de ser o controle efetivo depois da primeira concessão. Agrava-se pelo fato de a promoção ser um **efeito colateral implícito** de mudar a equipe — a interface não comunica que "Liderança" concede administração total (`scripts/qa-smoke.php:143` confirma que a UI define `access_role: 'admin'` ao selecionar Liderança).

#### Vetor secundário condicional

`auth_ldap.php:299-356` (`tentar_vincular_funcionario_ad`): com `ENABLE_AD_AUTO_LINK=true`, um usuário do AD cujo `displayName` normalizado coincida com o de um cadastro **sem `ad_login`** é vinculado automaticamente a esse cadastro e **herda o `access_role` dele**. Se existir um registro órfão com `equipe='lideranca'`, o vínculo concede administração sem ação humana. **Mitigado hoje:** o padrão é `false` no código (linha 301) e em ambos os `.env.example`. Classificação: **hipótese condicionada** — confirmar que `ENABLE_AD_AUTO_LINK` não está ativo no `.env` do servidor.

#### Observação adicional (fora do escopo do SEC-04)

O caminho 3 é um risco de integridade independente: se o MySQL ficar indisponível e `funcionarios.json` não existir, `carregar_funcionarios_sistema()` grava os 17 colaboradores fixos de `config.php:466-483` como seed. Reforça a remediação do LGPD-01.


### 2.3 Médios

| ID | Categoria | Evidência | Classificação | Impacto | Correção proposta | Esforço | Altera crítico? | Requisito corporativo |
|---|---|---|---|---|---|---|---|---|
| **PERF-01** | Desempenho | `api/status.php:8`; `api/portal/dashboard.php:3`; `init.php:7-10`; `config.php:186-225`; `security.php:593-617`; polling em `frontend/src/hooks/useLivePauses.js:7` (15s), `frontend/src/pages/DashboardPage.jsx:26` (15s), `frontend/src/components/PortalLayout.jsx:41` (60s), `frontend/src/pages/AdminPage.jsx:741` (30s) | Fato | 18 endpoints incluem `init.php`, que a cada requisição inicializa o CSV, carrega todos os funcionários do MySQL e roda `limpar_estado_antigo()` — que adquire o **flock exclusivo global** do estado de pausas. Com `pm.max_children=5`, cada poll de 15s por usuário serializa nesse lock. Um detentor lento do lock trava todo o pool. | Tirar `limpar_estado_antigo()` do caminho de requisição (mover para tarefa agendada ou executar de forma probabilística/por TTL) e tornar a carga de funcionários preguiçosa. Revisar os intervalos: dashboard e pausas podem ir para 20–30s. | M | Não | Ambiente homologado com monitoramento |
| **PERF-02** | Paginação | `services/OperationalService.php:961,1025` (`LIMIT 500`); `services/PortalService.php:51` (200); `services/DocumentService.php:119` (300); `services/ShiftAttachmentService.php:66` (200) | Fato | Listas e **exports CSV** truncam silenciosamente. Um período com mais de 500 lançamentos gera um CSV incompleto sem nenhum aviso ao usuário — falha silenciosa em insumo de pagamento. | Paginação explícita nas listas e, no mínimo, sinalização de truncamento no export (ou streaming por cursor sem limite para o CSV). **Lote 6 (2026-09-30, commit local): exportacoes CSV percorrem o filtro inteiro em paginas; listas de tela avisam quando cortam.** | M | Não | Homologação com evidências |
| **PERF-03** | Cache | `.htaccess:21-31` (sem regra para `/app/assets/*`) | Fato | Assets com hash no nome são rebaixados por revalidação desnecessária a cada navegação, gastando workers do pool de 5. | Adicionar, sob `<IfModule mod_headers.c>`, `Cache-Control: public, max-age=31536000, immutable` para `/app/assets/*` e `no-cache` para `app/index.html`. | P | Não | — |
| **SEC-06** | Roteamento | `.htaccess:34,52-67` — `RewriteEngine On` sem `RewriteBase /`; substituições relativas com `[R=302]` | Fato | Sem `RewriteBase`, o Apache resolve a substituição relativa contra o caminho físico e emite `Location:` com caminho de filesystem, revelando a estrutura do servidor. A correção foi aplicada **apenas localmente na VM, sem commit** (prompt, item 24): o próximo `git pull` a desfaz. | Trazer `RewriteBase /` para o repositório. No deploy, descartar a versão local (`git checkout -- .htaccess`, com backup antes) **antes** do pull. Observação de fato: os redirects legados `^var/www/chronodesk` e `^chronodesk` citados no CONTEXTO §19 **não existem** no `.htaccess` atual, e a regra `^$ /app/` não está duplicada — as instruções do prompt (item 3.1) descrevem uma versão anterior do arquivo. | P | Não | Mudança com rollback |
| **SEC-07** | Autorização / LGPD | `api/portal/absences.php:3`; `api/portal/announcements.php:3`; `api/portal/_bootstrap.php:18-20` (`$roles = []`); `services/PortalService.php:17` (coluna `reason`) | Fato (exposição) / Hipótese (sensibilidade do conteúdo de `reason`) | `portal_list_response('absences')` sem restrição de perfil: qualquer sessão autenticada, inclusive `tecnico` e `somente_leitura`, lista as ausências de **todos** os colaboradores, incluindo o campo `reason` — que pode conter motivo de afastamento (dado pessoal sensível sob a LGPD). | Restringir a listagem completa a `admin`/`gestor` e limitar o técnico às próprias ausências, no mesmo padrão já aplicado em `listWorkflowRecords` (`OperationalService.php:955-957`). Confirmar com o negócio o que `reason` armazena hoje. | P | **Sim** — RBAC | Proteção de dados (LGPD); perfis com menor privilégio |
| **SEC-08** | Autorização | `api/portal/shift_attachments_download.php:6,12`; `services/ShiftAttachmentService.php:153` (`download(int $id)` sem `$actor`) | Fato | O download de anexo de escala não recebe o ator e não tem noção de visibilidade: qualquer perfil autenticado baixa qualquer anexo por ID. Além disso, `?preview=1` serve PDF/PNG/JPG como `inline` na própria origem. | Avaliar com o negócio se escalas são de fato coletivas. Se forem, documentar a decisão; se não, aplicar escopo por equipe. Independentemente disso, manter `nosniff` e considerar servir PDF sempre como `attachment`. | P | **Sim** — RBAC (se houver restrição) | Perfis com menor privilégio |
| **SEC-09** | Dependências | Saída de `npm audit` (2026-09-28): 6 vulnerabilidades — 5 high, 1 moderate | Fato | `brace-expansion`, `browserslist`, `js-yaml`, `nanoid`, `postcss` (high) e `baseline-browser-mapping` (moderate). **Todas em devDependencies** (cadeia de build do Vite/ESLint/Tailwind): não são embarcadas no bundle servido. O risco é de DoS/leitura de `.map` na máquina de build, não em runtime de produção. | `npm audit fix` (npm indica correção sem breaking change), seguido de `npm run lint && npm run typecheck && npm run build` e commit do `package-lock.json`. | P | Não | Ambiente homologado |
| **SEC-10** | Configuração | `config.php:110`; único consumo em `api/configuracoes.php:16`; `.env.example:11` e `.env.production.example` definem `AUTH_SOURCE=ad`; CONTEXTO §10 indica `ldap` | Fato | `AUTH_SOURCE` é **configuração inerte**: nada ramifica por ela. O default do código (`json_fallback`) diverge do `.env` (`ad`) e do contexto (`ldap`), e o objetivo declarado da VULN-014 ("eliminar dual auth") não foi implementado — a dupla autenticação continua existindo via `ENABLE_LOCAL_ADMIN`. | Remover a constante ou implementá-la de fato, alinhando os três valores. Documentar que o controle efetivo da autenticação local é `ENABLE_LOCAL_ADMIN`. **Lote 5 (L7, aprovada em 2026-10-02): remover `AUTH_SOURCE`; implementação no 5c. O admin local fica como acesso de emergência (L4), com procedimento em `DEPLOY_LINUX.md`.** | P | **Sim** — autenticação (se implementada) | Autenticação AD/SSO com usuários nominais |
| **BIZ-04** | Regra de negócio | `services/OperationalService.php:1592-1603` | Fato | `minutesBetween()` trata `end <= start` como virada de dia. Com `start == end` (erro de digitação comum), o resultado é **1440 minutos = 24h de hora extra**, dentro do limite aceito, indo para aprovação como lançamento válido. | Rejeitar `start == end` explicitamente e avaliar um teto de negócio (ex.: 12h) para lançamento único. **Requer definição do negócio sobre o teto.** **Lote 6 (2026-09-30, commit local): inicio igual ao fim recusado; sem teto por lancamento (decisao do negocio).** | P | **Sim** — regra de negócio | Homologação com evidências |
| **BIZ-05** | Regra de negócio | `services/OperationalService.php:74-83` (`presenceForRule`, `even_days`/`odd_days`) | Fato | A paridade é calculada sobre o dia do calendário. Na virada de meses com 31 dias (7 vezes por ano), o dia 31 (ímpar) é seguido pelo dia 1 (ímpar): o grupo `odd_days` fica presencial **dois dias seguidos** e o grupo `even_days` remoto dois dias seguidos. Fevereiro com 28 dias não tem o problema; anos bissextos também não. | Decisão de negócio: aceitar a assimetria (7 ocorrências/ano) ou migrar para alternância por dia sequencial desde uma data-âncora. `fixed_weekdays` já existe como alternativa determinística. | M (se migrar) | **Sim** — regra de negócio | Homologação com evidências |
| **QA-01** | Qualidade | `scripts/qa-smoke.php` (156 asserts, majoritariamente `strpos` sobre código-fonte); ausência de testes negativos | Fato | A cobertura atual valida RBAC em unidade e a **presença de strings** nos arquivos — o que quebra em qualquer refatoração e não prova comportamento. Faltam exatamente os testes negativos pedidos: login com senha vazia, POST sem CSRF → 403, técnico em endpoint de gestor → 403, célula CSV iniciada por `=` neutralizada. Faltam também testes de horas extras, totais de CSV e virada de fuso. | Criar testes comportamentais no padrão existente (sem framework novo), um por correção de segurança e um por regra de negócio crítica. **Lote 7 (2026-10-01, commit local): `scripts/qa-security.php` roda os endpoints reais em processo filho: senha vazia (400), POST sem CSRF (403), tecnico em endpoint de gestor (403), sem sessao (401) e CSV com formula neutralizada. Horas extras e totais de CSV: `qa-overtime.php` (Lote 6); virada de fuso: `qa-timezone.php` (BIZ-02).** | M | Não | Homologação com evidências |
| **INT-01** | Integridade | `security.php:99-104` (`min_range 1, max_range 999`); `api/adicionar_funcionario.php:32`; `api/atualizar_funcionario.php:20` | Fato | O cadastro de funcionários está limitado a 999 registros por validação de entrada. Pior: `config.php:681` aplica `validate_funcionario_id(...) ?? 0` — um registro com ID fora da faixa é **normalizado para 0** em vez de rejeitado, o que pode colidir registros na persistência por `ON DUPLICATE KEY`. | Elevar o teto e trocar o `?? 0` por rejeição explícita. | P | Não | — |
| **OPS-04** | Superfície legada | `index.php`, `admin.php`, `metricas.php`, `static/js/*`, `database.sql`, `database_SECURED.sql`, `update_users_table.sql`, `migrar_csv_para_mysql.php`, `migrar_funcionarios_json_para_mysql.php`, `ersmmdcamargochronodesk_v3` | Fato | A UI legada continua no DocumentRoot (o `.htaccess` só redireciona GET; POST continua alcançável). O arquivo `ersmmdcamargochronodesk_v3` é saída de `git` capturada por redirecionamento acidental, **sem extensão — logo não coberto por nenhuma regra de bloqueio do `.htaccess`** — e lista nomes de arquivos do projeto. Dumps SQL e scripts de migração ficam no webroot (bloqueados, mas presentes). | Remover `ersmmdcamargochronodesk_v3`; mover dumps e scripts de migração para fora do DocumentRoot; planejar a remoção da UI legada após confirmar que nenhum fluxo POST depende dela. | M | Não | Documentação técnica; superfície mínima |

### 2.4 Baixos

| ID | Categoria | Evidência | Classificação | Impacto | Correção proposta | Esforço | Altera crítico? | Requisito |
|---|---|---|---|---|---|---|---|---|
| **SEC-11** | CSV | `services/OperationalService.php:1678`; `api/portal/critical_incidents_export.php:41`; `api/download_relatorio.php:18` | Fato | O regex neutraliza `= + - @` mesmo precedidos de espaço/controle, mas **não** neutraliza célula iniciada apenas por TAB ou CR, cenário citado na norma do projeto. | Incluir TAB/CR como gatilho de prefixação. **Lote 7 (2026-10-01, commit local): implementacao unica `csv_neutralize_cell()` em `security.php`, usada pelas tres exportacoes; texto com UTF-8 invalido deixava de ser neutralizado e passou a ser.** | P | Não | Proteção de dados |
| **SEC-12** | CSP | `security.php:228-229` | Fato | `script-src` combina `'unsafe-inline'` com nonce. Navegadores modernos (CSP3) ignoram `'unsafe-inline'` na presença do nonce, então o efeito prático é baixo — mas a diretiva é enganosa e não protege navegadores antigos. Aplica-se só às páginas PHP legadas; o SPA recebe CSP estrita via `.htaccess:28`. | Remover `'unsafe-inline'` após confirmar que nenhum handler inline permanece nas páginas legadas — ou junto com a remoção da UI legada (OPS-04). | P | Não | — |
| **QA-02** | UX / erro | `frontend/src/lib/api.ts:50-57` | Fato | 401 dispara evento global `chronodesk:unauthorized`; **403 não tem tratamento central** — aparece como erro genérico por chamada, com mensagem inconsistente entre telas. | Tratar 403 de forma consistente (mensagem padrão de permissão insuficiente), reaproveitando o canal de feedback da Fase 17. | P | Não | Tratamento seguro de erros |
| **OPS-05** | Auditoria | `database_SECURED.sql:78-89`; `security.php:395-423` | Fato (esquema) / Hipótese (grants — requer `SHOW GRANTS`) | `audit_log.username VARCHAR(50)` versus `portal_username()` sanitizando para 100 caracteres: risco de truncamento/erro em modo estrito. Não há política de retenção, e a imutabilidade depende dos grants do usuário de aplicação — **não verificados**. Positivo: nenhum ponto registra senha ou token. | Alinhar o tamanho da coluna, definir retenção e restringir os grants do usuário de aplicação a `INSERT`/`SELECT` em `audit_log`. | P | **Sim** — auditoria | Logs sem dados sensíveis |
| **PERF-04** | Cache | `index.php:23`; `config_assets.php:39-41` | Fato | `?v=time()` invalida o cache dos assets legados a cada requisição. Restrito à UI legada. | Resolvido junto com OPS-04. | P | Não | — |
| **BIZ-06** | Robustez | `services/OperationalService.php:1593-1594` | Fato | `minutesBetween()` constrói `DateTimeImmutable` com a data fixa `2000-01-01` no fuso local. Em 2000-01-01 não há transição de horário de verão, então **não há bug hoje**; a construção é frágil por depender de um dado histórico do tz database. | Construir os dois instantes em UTC explicitamente. **Lote 6: o calculo de horas extras passou a contar em segundos do dia, sem DateTime.** | P | Não | — |

---

## 3. Evidências de execução (Fase 0)

Todos os comandos abaixo são somente leitura e foram executados no clone Windows. **Nenhum comando foi executado contra o servidor.**

```
npm run lint                    → EXIT 0  (eslint, sem findings)
npm run typecheck               → EXIT 0  (tsc --noEmit, sem erros)
npm audit                       → 6 vulnerabilidades (5 high, 1 moderate) — todas em devDependencies
php -l (81 arquivos)            → nenhum erro de sintaxe
```

QA estático do frontend:

```
qa:api PASS · qa:operational PASS · qa:query PASS · qa:resource PASS · qa:table PASS
qa:schedule-rules PASS · qa:schedule-form PASS · qa:forms PASS
qa:action-feedback PASS · qa:action-runner PASS · qa:ux-hardening PASS · qa:bundle PASS
qa:visual FAIL — falha de ambiente: asserta a existência de app/index.html, que não é versionado
                 e só existe após o build no servidor. Não é regressão de código.
```

**Ressalvas honestas sobre as evidências:**

- O PHP local é **8.2.31**; o servidor roda **8.4.23**. `php -l` valida sintaxe, não compatibilidade de runtime entre as versões.
- `scripts/qa-smoke.php` **não foi executado**: ele instancia `OperationalService` e toca o banco. Sem MySQL local e sem acesso à VM, executá-lo produziria falha de ambiente sem valor diagnóstico.
- `gitleaks` não está disponível no ambiente. Foi feita varredura por padrões sobre o HEAD e sobre `git log -p --all`. **Nenhuma credencial real foi encontrada** no código ou no histórico — a única ocorrência é `SECRET_KEY=qa-only-secret-key` em `scripts/qa-smoke.php:6`, fixture de teste. O que está exposto são domínios e hostnames (SEC-01), não segredos.
- `SECRET_KEY` é definida em `config.php:101` e **nunca utilizada** em nenhum ponto do código. O fallback determinístico de desenvolvimento (`config.php:98`) portanto não representa risco hoje — mas é configuração morta que aparenta proteção.

### Validação da Fase 17 (conforme solicitado no prompt)

A Fase 17 **está implementada**, com evidência de código — não apenas de commit:

- `frontend/src/lib/actionRunner.ts` e `frontend/src/lib/actionFeedback.ts` existem e são consumidos por `App.jsx`, `AdminPage.jsx`, `CriticalIncidentsPage.jsx` e `OperationalPages.jsx`.
- `qa:action-feedback`, `qa:action-runner` e `qa:schedule-form` passam.
- O formulário de escala com React Hook Form + Zod está presente (`596db67`, validado por `qa:schedule-form`).

A cobertura do action runner, porém, **não é uniforme**: `PaMapPage.jsx`, `DocumentsPage.jsx`, `ShiftSchedulesPage.jsx`, `CalendarPage.jsx` e `PausasPage.jsx` não o utilizam. Item para o lote de padronização, não um defeito.

---

## 4. Aderência aos requisitos corporativos (§3.8)

| Requisito | Situação | Evidência / achado |
|---|---|---|
| Autenticação AD/Entra/SSO com usuários nominais | **Atende** | Bind AD por UPN nominal; sem conta de serviço no login (`auth_ldap.php`; `AD_CREDENTIALS.md`) |
| Perfis com menor privilégio | **Parcial** | RBAC implementado e testado, mas com duas fontes de verdade para Admin (SEC-04) e listagens sem escopo (SEC-07, SEC-08) |
| Credenciais em cofre (SenhaSegura), nunca em código | **Parcial** | Nenhum segredo no código; `.env` fora do webroot; senha root do MySQL em arquivo no servidor, não em cofre. `AdCredentialProvider` já prevê `runtime_file` como ponte para cofre |
| Código no repositório oficial (Azure) | **Não atende** | Repositório em conta pessoal do GitHub (prompt, item 26); agravado por SEC-01 e LGPD-01 |
| Documentação técnica mínima | **Atende (Lote 1)** | `docs/DOCUMENTACAO_TECNICA.md` criado em 2026-09-28 |
| Proteção de dados (LGPD) | **Não atende** | LGPD-01, SEC-07; sem inventário de dados pessoais |
| APIs autenticadas e criptografadas | **Parcial** | Autenticação e CSRF completos; **sem criptografia em trânsito** (SEC-02) |
| Logs sem dados sensíveis | **Atende** | Nenhum registro de senha ou token; auditoria grava apenas identificadores (verificado em `security.php:395-423` e em todos os `audit_log`) |
| Tratamento seguro de erros | **Parcial** | `portal_operational_error()` não vaza stack trace; risco condicionado a `APP_DEBUG` (SEC-05) |
| Homologação com evidências | **Parcial** | QA automatizado existe e passa, mas sem testes negativos de segurança nem de regra de negócio (QA-01). **Lote 7: testes negativos de endpoint em `qa-security.php`; segue Parcial até a validação em servidor.** |
| Registro do uso de IA | **Atende (Lote 1)** | `docs/REGISTRO_USO_IA.md` criado em 2026-09-28 |
| Ambiente homologado com monitoramento e backup | **Não atende** | Sem backup (OPS-01), sem endpoint de saúde (OPS-02) |
| Mudança com rollback | **Parcial** | Migrations idempotentes e bem escritas, mas sem scripts de rollback pareados |
| Dono e suporte definidos | **Desconhecido** | `UNKNOWN / TO CONFIRM` |

---

## 5. Plano de execução proposto (lotes)

Ordenado por risco/benefício, priorizando o que **não depende de decisão de negócio nem de infraestrutura**. Cada lote é pequeno, revisável e entregue no formato da seção 7 do prompt, com QA completo e comandos de deploy/rollback em linha única.

| Lote | Conteúdo | Itens | Depende de |
|---|---|---|---|
| **1 — Governança e sanitização** | Sanitizar `.example`, `README.md`, `DEPLOY_LINUX.md`, `PROMPT_CHATGPT.md` e `LoginCI.jsx`; remover fallback literal de domínio AD; criar `docs/DOCUMENTACAO_TECNICA.md` e `docs/REGISTRO_USO_IA.md` | SEC-01 (código), OPS-04 (parcial) | **CONCLUÍDO em 2026-09-28** |
| **2 — Correções de baixo risco, alto retorno** | `RewriteBase /` no `.htaccess`; cache `immutable` para `/app/assets/*`; guard-rails de produção fora do `if production`; `SET time_zone` no PDO; `npm audit fix`; TAB/CR no CSV; tratamento global de 403 | SEC-05, SEC-06, BIZ-02, SEC-09, SEC-11, QA-02, PERF-03 | Nada |
| **3 — Continuidade e observabilidade** | Script de backup diário + teste de restauração (entregue para execução humana); `api/health.php`; fluxo de deploy documentado com rollback | OPS-01, OPS-02 | Nada no código; execução do backup é do operador |
| **4 — Autorização e força bruta** | Rate limit por usuário independente de IP (persistido no MySQL); escopo em `absences`/`announcements`; escopo em anexos de escala | SEC-03, SEC-07, SEC-08 | **Aprovação item a item — altera RBAC e autenticação** |
| **5 — Fonte única de verdade do Admin** | Consolidar `AD_ADMIN_USERS` como allowlist obrigatória; tornar explícita ou remover a regra `lideranca ⇒ admin`; resolver `AUTH_SOURCE` | SEC-04, SEC-10 | **Aprovação explícita — altera RBAC** |
| **6 — Correções de regra de negócio** | Total de horas extras só sobre aprovados; rejeitar `start == end`; teto de lançamento | BIZ-01, BIZ-04 | **Definição do negócio** (qual é o total esperado; qual o teto) |
| **7 — Desempenho** | Tirar `limpar_estado_antigo()` do caminho de requisição; carga preguiçosa de funcionários; revisão de intervalos de polling; paginação/sinalização de truncamento em exports | PERF-01, PERF-02 | Nada, mas requer validação em ambiente |
| **8 — QA de segurança e negócio** | Testes negativos (senha vazia, POST sem CSRF → 403, técnico em rota de gestor → 403, CSV com `=`); testes de horas extras, totais de CSV e virada de fuso; matriz CI/Gestor/Admin × funcionalidade | QA-01 | Preferencialmente após os lotes 2, 4 e 6 |
| **9 — Decisões estruturais** | Histórico de escala; paridade par/ímpar na virada de mês; remoção da UI legada; limite de 999 IDs | BIZ-03, BIZ-05, OPS-04 (final), INT-01 | **Decisão de negócio** |
| **Fora de lote — infraestrutura** | LDAPS/StartTLS no código; HTTPS próprio para o ChronoDesk; `SESSION_COOKIE_SECURE=true`; migração para Azure DevOps; adoção de cofre | SEC-02, LGPD-01, requisitos corporativos | **Infraestrutura + governança.** Comandos serão gerados para execução humana. |

---

## 6. Perguntas em aberto

**Dependem de decisão do negócio:**

1. **Total de horas extras (BIZ-01):** o total por colaborador no CSV deve considerar apenas aprovados, ou o relatório precisa mostrar aprovado e pendente em colunas separadas?
2. **Histórico de escala (BIZ-03):** é aceitável que relatórios de datas passadas usem a escala vigente hoje, ou é necessário histórico real? A segunda opção muda o modelo de dados.
3. **Paridade par/ímpar (BIZ-05):** a repetição do mesmo grupo em dois dias seguidos na virada de meses de 31 dias é aceitável, ou a alternância deve ser corrigida?
4. **Regra `lideranca ⇒ admin` (SEC-04):** é intencional que mover um colaborador para a equipe "Liderança" conceda Admin total do portal, fora da allowlist `AD_ADMIN_USERS`?
5. **Campo `reason` de ausências (SEC-07):** ele armazena motivo de afastamento (potencial dado de saúde)? Quem deve poder lê-lo?
6. **Anexos de escala (SEC-08):** escalas são documentos coletivos — qualquer colaborador pode baixar qualquer anexo — ou precisam de escopo por equipe?
7. **Teto de hora extra (BIZ-04):** existe limite máximo por lançamento único?
8. **Dono e suporte:** quem é o responsável formal pela solução e qual o canal de suporte?

**Dependem de informação de infraestrutura (a confirmar no servidor):**

9. **Fuso do contêiner MySQL (BIZ-02):** `docker exec Chrono_Desk_DB mysql -uroot -e "SELECT @@global.time_zone, @@session.time_zone, NOW(), CURDATE();"` — para dimensionar o impacto real da divergência.
10. **`APP_ENV` efetivo (SEC-05):** o `.env` do servidor ainda está em `staging`? Qual é a designação correta deste ambiente?
11. **Grants do usuário de aplicação (OPS-05):** `SHOW GRANTS FOR 'chronodesk_app'@'%';` — para confirmar se a auditoria é imutável pela aplicação e se o princípio do menor privilégio está aplicado.
12. **Viabilidade de LDAPS (SEC-02):** os controladores de domínio aceitam 636/StartTLS? Existe CA interna cujo certificado possa ser validado pela VM?
13. **Certificado para o ChronoDesk (SEC-02):** o HTTPS atual pertence ao sdk-tools. É possível emitir certificado próprio para o ChronoDesk?
14. **Banco `CHRONO_DESK` vazio:** registrado conforme instrução (prompt, item 27). Sem referência no código; tratado como legado. **Não remover.**
15. **Jobs agendados:** `systemctl list-timers --all` e `crontab -l` — inventário permanece `UNKNOWN / TO CONFIRM`.

---

## 7. O que não foi feito nesta fase

Por instrução explícita do usuário, esta fase gerou **apenas este arquivo**. Nenhum outro arquivo foi criado, alterado ou removido; nenhum commit ou push foi realizado; nenhum comando foi executado contra o servidor.

Ficam pendentes para lote aprovado:

- `docs/DOCUMENTACAO_TECNICA.md` (inclui o inventário de dados pessoais exigido pela avaliação LGPD)
- `docs/REGISTRO_USO_IA.md`

**Fim da Fase 0 — aguardando aprovação item a item.**
