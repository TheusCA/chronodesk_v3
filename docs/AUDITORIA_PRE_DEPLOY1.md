# Auditoria técnica independente do ChronoDesk, antes do deploy 1

**Data:** 2026-11-09 · **Commit auditado:** `783ddc4` (branch `feat/employee-form-rhf-zod`) · **Base no servidor:** `596db67`
**Método:** leitura do repositório, somente leitura. Nada foi executado contra servidor, banco ou rede. Nenhum QA foi rodado (seção 8). Toda afirmação traz arquivo:linha; o que não foi lido está na seção 8.
**Cobertura:** roteiro do deploy 1 inteiro (`DEPLOY_LINUX.md:2573-4040`); todas as migrations do deploy, os rollbacks da 011, 016, 016b e 019 e os quatro scripts do BIZ-02; `config.php`, `security.php`, `db.php`, `auth_ldap.php`, `init.php`, `.htaccess`, vhost e `.env` de exemplo; os 50 arquivos de `api/` e `api/portal/` na íntegra; `DocumentService`, `ShiftAttachmentService`, `PortalService`, `SharePointSyncService` na íntegra; trechos de `OperationalService`, `PaMapService`, `AbsenceService`, `CriticalIncidentService`. Os QAs foram medidos e amostrados, não lidos por inteiro.

---

## 1. Veredito

**O deploy de 11/11 pode seguir, com condição.** Não encontrei bloqueador de código no que li: todo endpoint confere sessão e perfil no servidor, todo POST passa por CSRF, o SQL é parametrizado, os uploads são bem tratados, as migrations são idempotentes e têm parada antes de alterar, e a correção de fuso é segura para rodar duas vezes.

A condição são **três correções de procedimento (C1, C2, C3)**, todas pequenas, que precisam entrar no roteiro e na folha **antes da véspera**. Sem elas: (C1) o portal pode cair às 19h, fora da indisponibilidade avisada, no instante em que o `APP_ENV` é trocado; (C2) se o `npm ci` falhar, não há volta que não dependa do mesmo `npm ci`; (C3) o portal reabre para o turno da noite antes de alguém ter feito um login ou uma gravação com o código novo num MySQL de verdade.

Atenção ao prazo: o roteiro vive no mesmo commit que é validado no passo 0o. Editá-lo muda o hash e exige repetir o 0o e manter a trava `qa-deploy-runbook.php` passando. Se não der tempo até 10/11, C1 a C3 podem ser executadas como adendo operacional, sem mudar o commit; a decisão é do responsável.

Fora do deploy, o que mais pesa é estrutural: o portal trafega senha do AD em HTTP e LDAP sem TLS (C4), e a suíte de testes do backend nunca executa o SQL da aplicação num banco real (seção 6).

---

## 2. Bloqueadores

**Nenhum encontrado no que foi lido.** Isso não é o mesmo que "não há": cerca de dois terços do código de serviço não foram lidos linha a linha (seção 8).

---

## 3. Críticos (corrigir antes do deploy; não bloqueiam com a mitigação indicada)

### C1. A troca do `APP_ENV` vale na hora, com o código antigo no ar, antes do preflight

- **Evidência.** O passo 3b troca `staging` por `production` no `.env` (`DEPLOY_LINUX.md:3272`; folha, comando 49, `docs/DEPLOY1_FOLHA_DO_DIA.md:321-324`) e o roteiro afirma que "o valor vale para o PHP-FPM na recarga do passo 6e" (`DEPLOY_LINUX.md:3277`). O código não faz isso: `load_env()` lê o arquivo a cada requisição (`config.php:20-37`, `44-49`), e o `596db67` tem a mesma função (`git show 596db67:config.php`, linha 34). O preflight só roda depois, no 3c (`DEPLOY_LINUX.md:3283`).
- **Por que importa.** No `596db67`, as travas só existem com `APP_ENV === 'production'` (`596db67:config.php:94`, `123-136`; `596db67:security.php:255`). Com `staging`, o servidor roda hoje sem nenhuma delas. No segundo seguinte ao 3b, o código antigo passa a exigir: `APP_DEBUG` desligado, `DB_USER` diferente de `root`, `DB_PASS` sem texto de exemplo, `AD_ADMIN_USERS` preenchido, e passa a obedecer `FORCE_HTTPS`. Qualquer um desses fora do esperado derruba o portal inteiro (exceção em `config.php` ou `301` para um HTTPS que não atende, como o próprio `.env.production.example:16-22` avisa), **por volta das 19h, com o turno em operação e fora da janela de 20h–21h avisada**. O passo 0j da véspera só confere `APP_ENV`, `SECRET_KEY` e, "a olho", três variáveis (`DEPLOY_LINUX.md:2840-2850`); não confere `FORCE_HTTPS`, `APP_DEBUG`, `DB_USER` nem `DB_PASS`.
- **O que fazer.** (a) Rodar o preflight **na véspera** (ele só lê e só imprime OK/FALHA): `cd /var/www/chronodesk; sudo git show origin/feat/employee-form-rhf-zod:scripts/preflight-deploy.sh | sudo sh -s -- /var/www/.env`. As checagens dele valem para qualquer `APP_ENV` diferente de `development` (`scripts/preflight-deploy.sh:62-90`, `133-137`), então já respondem com `staging`. (b) No dia, inverter a ordem: 3c antes do 3b; ou, melhor, mover o 3b para depois do `merge` (6b), dentro da manutenção, já que o código novo aplica as travas em `staging` também (`config.php:126`). (c) Corrigir a frase da linha 3277.
- **Mitigação se nada mudar.** Ter o comando de volta (`DEPLOY_LINUX.md:3293`) pronto no terminal antes de executar o 3b e conferir o portal de uma estação logo depois.

### C2. Não há cópia do `app/` nem plano sem `npm` para o build e para o rollback de código

- **Evidência.** `app/` e `node_modules/` não são versionados (`.gitignore`, linhas `/app/` e `node_modules/`). O 6e roda `sudo npm ci && sudo npm run build` no lugar (`DEPLOY_LINUX.md:3604`; folha `:576`). O roteiro só trata a falha por falta de espaço e admite que "o degrau 2 também não resolve" (`DEPLOY_LINUX.md:3594-3601`). O degrau 2 repete `npm ci` e build do commit anterior (`DEPLOY_LINUX.md:3866`; folha `:771`). O 3a copia só o `.htaccess` (`:3260`) e o 3d só o banco e quatro arquivos de estado (`:3307`).
- **Impacto.** Registro npm fora do ar, proxy, DNS ou pacote corrompido às 20h30: `node_modules` apagado, `app/` antigo ou pela metade, portal em manutenção, e o caminho de volta depende exatamente do que falhou. O disco em `/var` está com 88% de uso (`DEPLOY_LINUX.md:2629`).
- **O que fazer.** Antes do 6b: `sudo cp -a /var/www/chronodesk/app /var/backups/chronodesk/app-antes-deploy1` (0,9 MB) e conferir com `diff -rq`. No degrau 2, restaurar essa cópia em vez de reconstruir. Na véspera, conferir que a VM alcança o registro (`cd /var/www/chronodesk/frontend; sudo npm ping`). Acrescentar ao 6e o "PARE se" para falha de rede, não só `ENOSPC`.

### C3. O portal reabre antes de qualquer validação funcional com banco real

- **Evidência.** Antes da reabertura, o roteiro valida só o health (`SELECT 1`, `api/health.php:35-39`) e o fuso (`DEPLOY_LINUX.md:3608-3621`). A reabertura é o 6g (`:3623-3627`). A primeira conferência por perfil, o 7i, vem depois (`:3702-3748`). Não existe ambiente de homologação: `staging` é só o valor do `APP_ENV` da própria produção (`:2831-2833`). E os testes automatizados não executam o SQL da aplicação em MySQL: todos usam um PDO simulado que casa o **texto** do comando (por exemplo `scripts/qa-schedule-history.php:192-225`; `scripts/qa-security.php:31`, `77`, `587`, `718`).
- **Impacto.** Coluna ausente, collation, modo estrito, CHECK ou gatilho que o simulador não reproduz só aparecem para o usuário, às 21h. O login tem um caso concreto: a consulta principal usa a coluna gerada `ad_login_ativo` (`auth_ldap.php:289`), que existe no `database_SECURED.sql:26` mas não é conferida no 0b; se faltar no servidor, todo login cai no caminho de reserva em silêncio (`auth_ldap.php:302-322`).
- **O que fazer.** O loopback passa pela manutenção (`.htaccess`, regra `RewriteCond %{REMOTE_ADDR} !^(?:127\.0\.0\.1|::1)$`). Entre o 6f e o 6g, por túnel SSH ou `curl` na própria VM: login de admin, `GET api/status.php`, `GET api/portal/schedules.php`, `GET api/portal/pa_map.php`, `GET api/portal/absences.php`, uma gravação reversível (lançar e cancelar uma ausência de teste) e conferir o log de erros do PHP sem linha nova. Só então reabrir.
- **A CONFIRMAR (só contagens):** `SELECT (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'funcionarios' AND COLUMN_NAME = 'ad_login_ativo') AS ad_login_ativo, (SELECT CHARACTER_MAXIMUM_LENGTH FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'audit_log' AND COLUMN_NAME = 'username') AS audit_username_tamanho;` Esperado `1` e, idealmente, `100` (ver M10).

### C4. Senha do AD trafega sem cifra nas duas pontas (pré-existente; o deploy não piora)

- **Evidência.** "Hoje o ChronoDesk roda apenas em HTTP" (`.env.production.example:16-24`); o cookie de sessão sai sem `Secure` (`security.php:290-294`, `306`). Do servidor ao AD: "Bind direto com a credencial do usuário, porta 389 sem TLS" (`docs/DOCUMENTACAO_TECNICA.md:150`); o código só cifra com `AD_SCHEME=ldaps` ou `AD_USE_TLS=true` (`auth_ldap.php:64-70`, `115-121`). Os dois exemplos divergem entre si: `AD_USE_TLS=true` em `.env.production.example:46` e `false` em `.env.example:25`.
- **Impacto.** Qualquer posição na rede entre estação, VM e controlador de domínio lê login e senha corporativos de todos os usuários e o cookie de sessão. É o achado mais grave de segurança do projeto, independente deste deploy.
- **Mitigação para seguir.** Confirmar que o tráfego fica em rede interna segmentada e registrar o risco como aceito por quem responde por segurança, com prazo. Depois do deploy: vhost HTTPS próprio, `SESSION_COOKIE_SECURE=true`, HSTS e LDAPS ou StartTLS com a CA instalada.
- **A CONFIRMAR:** `sudo grep -cE '^(AD_USE_TLS=true|AD_SCHEME=ldaps)$' /var/www/.env` (`0` = bind em texto claro).

---

## 4. Altos, médios e baixos

Esforço: **B** até 2 h · **M** até 1 dia · **A** mais de 1 dia.

### Altos

| # | Onde | O que é e impacto | O que fazer | Esf. |
|---|---|---|---|---|
| A1 | `api/portal/schedules.php:13-19`; `services/OperationalService.php:372-394`, `1647-1649`, `1702`, `1722-1723` | O `GET` da escala é aberto a qualquer perfil e devolve, de **todos** os colaboradores, o login AD (`ad_login`), os logins de quem criou e alterou a regra e a **observação em texto livre** de cada exceção com o autor. É a mesma classe de vazamento que a DF7 fechou no Mapa PA e o SEC-07b nos plantões, e não está no inventário da `DOCUMENTACAO_TECNICA.md:122-131`. As exceções antigas dos tipos folga, ausência, férias e licença continuam sendo lidas da mesma tabela; se têm observação preenchida é o que a consulta ao lado responde. O aviso à gestão diz que técnico e leitura "deixam de ver as observações e os textos livres dos colegas" (`DEPLOY_LINUX.md:3068-3070`); para a escala, isso não é verdade. A tela só usa `note` no formulário da gestão (`frontend/src/pages/OperationalPages.jsx:386`). | Passar o ator a `scheduleData()` e tirar `ad_login`, `created_by`, `updated_by` e `note` de quem não é gestão nem o próprio, como em `listOncall()` (`:1016-1022`). **A CONFIRMAR:** `SELECT exception_type, COUNT(*) AS n, SUM(note IS NOT NULL AND note <> '') AS com_observacao FROM portal_schedule_exceptions GROUP BY exception_type;` | B |
| A2 | `DEPLOY_LINUX.md:3307`, `3530-3531`, `3946-3948` | O único backup do dia é tirado com o portal aberto e o turno gravando; a manutenção só vale a partir do `merge`. Não existe dump com o portal parado. Restaurar o 3d perde tudo o que foi gravado desde ele, pausas inclusive. | Segundo `mysqldump` logo depois do `merge` (6b), antes do `biz02_01` e da 016b. | B |
| A3 | `DEPLOY_LINUX.md:3868` (degrau 2); `migrations/correcao_dados/biz02_03_corrigir.sql:136-141` | O degrau 2 apaga a fotografia do BIZ-02 (`DROP TABLE manutencao_biz02_datetime`) sem conferir se a correção histórica já rodou. Depois do passo 9c, esse comando torna a correção **irreversível** (o script 4 depende da fotografia, `biz02_04_rollback.sql:26-31`) e deixa o histórico corrigido sob código que volta a gravar em UTC. O texto avisa da ordem (`:3857-3859`), o comando não a garante. | Condicionar o `DROP` a `SELECT COUNT(*) FROM manutencao_marcadores WHERE chave = 'biz02_correcao'` igual a `0`; com `1`, rodar o script 4 antes. | B |
| A4 | `security.php:1013-1020`; `api/portal/_bootstrap.php:101-104` | Qualquer `PDOException` (coluna inexistente, chave duplicada, CHECK, deadlock) vira "Banco de dados indisponível" com 503, e o log grava só o nome da classe, sem a mensagem. Na noite do deploy, um erro de SQL do código novo é indistinguível de banco fora do ar, na tela e no log. | Logar `getMessage()` (sem dados de entrada) e separar erro de conexão de erro de consulta. Até lá, ter à mão como ligar o log de erros do MySQL. | B |
| A5 | `scripts/qa-*.php`; `frontend/scripts/qa-*.mjs` | Nenhum teste executa o SQL da aplicação em MySQL real (detalhe na seção 6). O que protege o deploy hoje são os ensaios de migration no servidor e a revisão humana. | Suíte mínima de integração contra um MySQL 8.0 descartável (container local), começando por login, escala, ausência e Mapa PA. | A |

### Médios

| # | Onde | O que é e impacto | O que fazer | Esf. |
|---|---|---|---|---|
| M1 | `DEPLOY_LINUX.md:3209` x `3259`; folha `:312` | O passo 2 grava `deploy1-commit` em `/var/backups/chronodesk`, que só é criado no 3a; o 0a aceita o diretório "como estiver" (`:2700`). Se não existir, parada evitável no começo da janela. | Mover o `install -d` para antes do passo 2. | B |
| M2 | `DEPLOY_LINUX.md:3705-3746` x `2612` | O 7i exige contas de líder fora da allowlist, gestor e técnico de N2 por volta das 21h; "quem acompanha" é só a infraestrutura. Não há critério para seguir sem um perfil. | Nomear por papel quem valida cada perfil e o que fazer na falta. | B |
| M3 | `services/OperationalService.php:611-663` (sem checagem de data) x `:471` (regra) | Regra com início retroativo exige `escalas.retroativa` (admin e Liderança); ausência retroativa também. **Exceção de escala em data passada não tem regra nenhuma:** qualquer gestor altera, e depois do corte isso reescreve Calendário e relatório de competência fechada. Não achei decisão no desenho nem teste. | Aplicar a mesma permissão ou registrar a decisão de não aplicar. | B |
| M4 | `services/OperationalService.php:897-909`, `961-972`, `1067-1070`; `services/SharePointSyncService.php:50-58` | Cada hora extra, ajuste de ponto e plantão é copiado para `portal_sync_queue.payload_json` com nome, motivo, justificativa e observação. A fila não tem consumidor (o processamento só lança exceção) nem retenção. É uma segunda cópia de texto livre pessoal sem finalidade ativa. Além disso, falha ao enfileirar desfaz a gravação principal (`:44-49`). | Enfileirar só IDs, ou desligar o enfileiramento até existir integração. **A CONFIRMAR:** `SELECT record_type, status, COUNT(*) FROM portal_sync_queue GROUP BY record_type, status;` | M |
| M5 | Todo o código (busca por `row_version` sem resultado) | Não há controle otimista em nenhuma tabela. As gravações travam a linha (`FOR UPDATE`), então não corrompem, mas duas pessoas editando o mesmo cadastro, vínculo de PA ou ausência resultam em "a última vence", sem 409. Em `api/atualizar_funcionario.php:98-108` o perfil atual é lido **fora** da transação que começa na linha 150; uma promoção feita nesse intervalo é sobrescrita pelo valor antigo. | Reler o perfil dentro da trava; avaliar coluna de versão nas telas de edição. | M |
| M6 | `migrations/20260613_003_operational_modules.sql`, `20260617_009_*.sql`, `database_SECURED.sql:16-38`; `migrations/20260612_001_portal_foundation.sql:41-60` | O banco aceita o que o serviço proíbe: vínculo de PA com fim antes do início e sobreposto para o mesmo colaborador; jornada com entrada depois da saída e almoço fora da jornada; hora extra com início igual ao fim. `pause_approval_requests.employee_id` não tem FK. Só a 012, a 014 e a 019 têm CHECK. `funcionarios.equipe` é `VARCHAR(10)` livre, e o código convive com sete grafias (`security.php:132-144`). | CHECKs para período e jornada; normalizar `equipe` e torná-la ENUM. | M |
| M7 | `config.php:606-639`, `734-749` | Se a leitura de funcionários do MySQL voltar vazia (tabela vazia, ou erro em que a escrita ainda funciona) e não houver `funcionarios.json`, o código **grava sozinho** 17 cadastros fixos, por `INSERT ... ON DUPLICATE KEY UPDATE` nos IDs 1 a 17, zerando login AD e perfil desses IDs. Isso roda em toda requisição que inclui o `init.php`, inclusive sem sessão (`api/login_ci.php:2`). Um `DB_NAME` apontado para um schema vazio ou uma restauração parcial semeia a produção. Não reproduzi; li o caminho. | Remover a semeadura automática; cadastro inicial só por script de instalação. | B |
| M8 | `config.php:608-624` | Os 17 cadastros fixos têm nome de pessoas no código-fonte e, portanto, no histórico do git e no remoto. Dado pessoal fora do banco, sem como eliminar. | Trocar por dados fictícios; decidir sobre o histórico com o responsável. | B |
| M9 | `migrations/20261002_016b_lideranca_profile_data.sql:53`, `58` x `security.php:132-144` | A 016b reconhece a Liderança por `equipe = 'lideranca'` (a collation cobre maiúsculas e acento), mas o PHP também trata `na`, `não se aplica`, `nao_se_aplica` e `sem_equipe` como Liderança. Um admin com rótulo antigo não vira `lideranca`; se estiver inativo, é rebaixado a técnico. | **A CONFIRMAR antes do 6d:** `SELECT COUNT(*) FROM funcionarios WHERE access_role = 'admin' AND LOWER(TRIM(equipe)) IN ('na','não se aplica','nao se aplica','nao_se_aplica','sem_equipe');` Esperado `0`. | B |
| M10 | `database_SECURED.sql:82` x `security.php:715`; `config.php:570` | `audit_log.username` é `VARCHAR(50)`; o login aceito tem até 100 caracteres. Com modo estrito, o evento de auditoria de um login longo falha e some no `catch` (`security.php:720-723`). | Ampliar a coluna (consulta em C3). | B |
| M11 | `auth_ldap.php:137`, `152`, `160` | O login digitado vai para o log de erros do PHP em todo sucesso e toda falha. O `audit_log` foi protegido contra senha digitada no campo de usuário (`security.php:632-648`); o `error_log` não. Login é dado pessoal e o log não tem retenção definida. | Logar só o resultado, sem o login. | B |
| M12 | `DEPLOY_LINUX.md:127` | A imagem é `mysql:8.0`. Pelo que conheço, a série 8.0 saiu do suporte da Oracle em abril de 2026: sem correção de segurança desde então. **Não confirmei na documentação oficial** (sem rede nesta auditoria). | Confirmar e planejar a ida para a 8.4 LTS. | A |
| M13 | Repositório (sem `.github/`, `composer.json`, `phpunit`, `phpstan`) | Não há CI, análise estática nem verificação de dependências automática. Tudo depende de alguém rodar o `test-local.ps1` na estação. | Pipeline mínimo: `php -l`, QAs, `eslint`, `tsc`, `npm audit`, `gitleaks`. | M |
| M14 | `DEPLOY_LINUX.md:2688` e todos os `docker exec -e MYSQL_PWD="$P"` | A senha do root do MySQL entra no argv do cliente `docker` e fica legível em `/proc/<pid>/cmdline` para outros usuários da VM durante o comando, cerca de 45 vezes no dia. | `export MYSQL_PWD="$P"` e `docker exec -e MYSQL_PWD` (só o nome). | B |
| M15 | `DEPLOY_LINUX.md:3036-3038` x `docs/FUSO_HORARIO_BIZ02.md:121-124` | O aviso diz que as datas "de aprovação" passam a aparecer três horas mais cedo logo na volta. Para registros antigos isso só acontece depois da correção histórica (9c, semanas depois); até lá aprovações, decisões e auditoria antigas continuam três horas adiante, misturadas com as novas, inclusive no CSV de ponto. | Ajustar a frase do aviso. | B |

### Baixos

| # | Onde | O que é | Esf. |
|---|---|---|---|
| B1 | `security.php:245`, `235` | CSP das respostas PHP com `'unsafe-inline'` em `script-src`; `X-XSS-Protection` obsoleto. O `index.html` do React tem CSP estrita pelo `.htaccess`. | B |
| B2 | `services/CriticalIncidentService.php:824-827`; `DocumentService.php:85`; `ShiftAttachmentService.php:91` | `LIKE` com `%` e `_` do usuário sem escape: só muda o que a busca casa. | B |
| B3 | `config.php:479-481` | Grava no `config_sistema.json` o hash de uma senha fixa conhecida. Nenhum login lê esse campo: código morto, mas é o tipo de resto que volta a ser usado por engano. | B |
| B4 | `auth_ldap.php:519-559`; `api/iniciar_pausa.php:36`, `finalizar_pausa.php:25`, `solicitar_pausa_com_aprovacao.php:38` | Três endpoints de pausa ainda aceitam login e senha do AD no corpo, sem sessão: superfície de autenticação triplicada. | M |
| B5 | `api/login_ci.php:22`, `42` | O limite "global" é por IP e é zerado por qualquer login bem-sucedido do mesmo IP. Restam o limite por login e o contador por usuário no banco. | B |
| B6 | `api/login_ci.php:2`, `api/iniciar_pausa.php:6` | O `init.php` (carga de todos os cadastros e limpeza do estado) roda antes da autenticação. | B |
| B7 | `api/listar_funcionarios.php:56-67`; `DocumentService.php:71-73` | Jornada e almoço de todos, e logins de quem publicou documentos, para qualquer perfil. Minimização. | B |
| B8 | `api/alterar_senha_admin.php:21-40` | Troca de senha local sem pedir a atual. Admin local fica desligado por padrão. | B |
| B9 | `config.php:430-439` x `security.php:1282-1305` | Duas implementações da mesma guarda de admin (`verificar_admin_login_api` e `require_portal_auth(['admin'])`), hoje equivalentes. | B |
| B10 | `api/portal/_bootstrap.php:18-30`; `PortalService.php:9-55` | `portal_list_response()` e `PortalService::list()` apontam para tabelas da primeira versão; não achei chamador nos 50 endpoints. Na raiz ficam instaladores e scripts antigos (`setup.*`, `INSTALAR.ps1`, `migrar_*.php`, `database.sql` sem as proteções do `_SECURED`). | M |
| B11 | `api/adicionar_funcionario.php:28-31`; `api/portal/notifications.php:20-21` | `trim()` em valor não textual e índice em corpo não-array: resposta 500 em vez de 400. | B |
| B12 | `scripts/preflight-deploy.sh:5` | O cabeçalho cita "linhas 123-145" do `config.php`; hoje são `126-141`. | B |
| B13 | `DEPLOY_LINUX.md:3604` | `npm ci` como root executa scripts de instalação dos pacotes com privilégio total. | B |
| B14 | `PaMapService.php` (gravação com `id` de vínculo de outro colaborador) | Duas gravações cruzadas podem entrar em deadlock; o MySQL resolve, o usuário vê 503 "banco indisponível" (A4), sem nova tentativa. | B |

### O que conferi e está certo (com a prova)

- **Sessão em todo endpoint.** Sem sessão só respondem `api/session.php` (devolve o token CSRF e campos vazios), os dois logins, os dois logouts, o health (lista de IPs, `api/health.php:23-29`) e as três pausas (sessão do próprio ou credencial do AD). Os outros 40 chamam `require_portal_auth()` ou `verificar_admin_login_api()` antes de qualquer efeito.
- **CSRF em todo POST.** Os 30 endpoints que gravam chamam `require_csrf_token()` direto ou via `portal_json_input()` (`_bootstrap.php:54`), inclusive os três uploads `multipart`.
- **Por objeto.** Técnico só age na própria pausa (`auth_ldap.php:461-474`), só lança hora extra e ajuste para si (`OperationalService.php:1468-1482`), só lista os próprios (`:1369-1371`) e não aprova o próprio lançamento (`:1416-1419`). Perfil revalidado a cada requisição (`security.php:1244-1280`).
- **SQL.** Todos os pontos com interpolação (`OperationalService.php:272-284`, `1323-1443`, `2529-2533`; `CriticalIncidentService.php:409-425`, `805`, `826`; `PortalService.php:47`, `71`) usam nomes vindos de listas fixas no código; valores sempre por parâmetro.
- **Upload.** Nome gerado no servidor, fora do webroot com checagem, MIME por conteúdo, lista de extensões, limite de tamanho, proteção contra pacote Office inflado e macros (`DocumentService.php:125-222`, `385-474`; `ShiftAttachmentService.php:101-183`, `266-276`). Download com `attachment`, `nosniff` e hash conferido.
- **Travas.** Ordem consistente, colaborador primeiro (`OperationalService.php:1519-1549`, `AbsenceService.php` `lockEmployee`, `PaMapService.php` `employee`, `EmployeeArchiveService.php:152-177`).
- **BIZ-02.** Script 1 não fotografa duas vezes (`biz02_01:64`, `81-87`); script 3 só altera linha que ainda tem o valor fotografado e marca cada uma (`biz02_03:38-43`); tudo numa transação; o script 4 devolve o valor exato. As migrations que gravam auditoria depois do corte fixam o fuso (`016b:22`, `019_registrar_corte:49`).
- **Frontend.** Nenhum `dangerouslySetInnerHTML`, `innerHTML` ou `eval` em `frontend/src`; nada em `localStorage`.
- **Histórico.** Busca por padrões de segredo nos commits desde o `596db67`: só uma constante de teste. Não substitui o `gitleaks` do passo 0k.

---

## 5. Riscos do dia que o roteiro não cobre

1. **Não há ensaio do roteiro inteiro.** Banco e migrations são ensaiados na cópia; `merge`, `.htaccess` novo, build e código sob Apache e PHP-FPM reais acontecem pela primeira vez na janela (C3).
2. **Dependência de rede externa dentro da janela:** `git fetch` no passo 2 e `npm ci` no 6e (C2).
3. **Ponto sem volta.** Até o 6g, voltar é limpo: a 012 roda com a tabela vazia, a 016b tem registro próprio, o corte do BIZ-02 só fotografa. **Depois da reabertura**, cada gravação do código novo (ausência, regra com histórico ou ciclo, arquivamento, linhas no fuso novo) transforma o degrau 2 em perda de dado ou em exposição que os lotes fecharam (`DEPLOY_LINUX.md:3877-3903`). O segundo ponto sem volta é o passo 9c (A3).
4. **Gravações entre o 3d e o 6b** não estão em nenhum backup (A2).
5. **Requisição em andamento no instante do `merge`.** O procedimento original do BIZ-02 esperava 10 segundos depois de ligar a manutenção (`docs/FUSO_HORARIO_BIZ02.md:137`); o roteiro consolidado não espera. Uma requisição do código antigo que grave auditoria logo depois do corte fica em UTC fora da fotografia. Um `sleep 10` antes do 6c resolve.
6. **Processos fora do Apache que gravam no banco** não são barrados pela manutenção. **A CONFIRMAR:** `systemctl list-timers --all | grep -ci chronodesk; sudo crontab -l 2>/dev/null | grep -ci chronodesk; sudo crontab -l -u www-data 2>/dev/null | grep -vc '^#'`. Esperado `0` nos três.
7. **Colunas `DATETIME` fora do inventário do BIZ-02.** O inventário veio do repositório, e o servidor tem tabelas que o repositório não descreve (as quatro cópias de junho). **A CONFIRMAR, só estrutura:** `SELECT TABLE_NAME, COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND DATA_TYPE = 'datetime' AND TABLE_NAME NOT REGEXP '_[0-9]{8}_[0-9]{6}$' ORDER BY 1, 2;` Pelo schema do repositório no `596db67` contei 27; qualquer coluna além das listadas em `docs/FUSO_HORARIO_BIZ02.md:82-109` precisa de decisão antes do corte.
8. **Diagnóstico às cegas** se o código novo errar SQL (A4).
9. **Fadiga do operador:** uma pessoa, quatro horas, comandos de uma linha com centenas de caracteres e a senha do root digitada dezenas de vezes. A folha numerada ajuda; um segundo par de olhos lendo o esperado de cada comando ajudaria mais.
10. **Compatibilidade do `estado.json`** entre o `596db67` e o código novo com pausas abertas no momento do `merge`: não verifiquei.

---

## 6. Saúde do projeto

| Eixo | Nota | Justificativa |
|---|---|---|
| **Segurança** | 6 | Aplicação cuidadosa: autorização no servidor, CSRF, SQL parametrizado, upload sólido, revalidação de sessão. Pesa contra: senha do AD em HTTP e LDAP sem TLS (C4) e o vazamento da escala (A1), que três lotes de privacidade não pegaram. |
| **Integridade** | 6,5 | Transações com trava e ordem consistente, migrations idempotentes com parada antes de alterar. Pesa contra: o banco quase não espelha as regras (M6), não há detecção de edição concorrente (M5) e existe semeadura automática de cadastros (M7). |
| **Testes** | 4,5 | Volume grande e disciplina de rodar; parte do frontend executa o código de verdade. A prova é fraca onde mais importa: o backend roda contra um banco simulado por texto. O que mais pesa: nada executa o SQL real, e código e teste têm o mesmo autor. |
| **Documentação** | 8 | Rara em projeto deste porte: decisões rastreadas, riscos de LGPD e de rollback escritos. Pesa contra: o volume virou risco (roteiro de 294 KB, desenho de 726 KB) e achei três pontos em que o texto diverge do código (C1, M15, A1). |
| **Operação** | 5 | Roteiro com paradas, esperados e degraus de rollback; ensaio das migrations em cópia. Pesa contra: sem homologação, sem CI, rotina de backup ainda não instalada (`DEPLOY_LINUX.md:2699`), MySQL possivelmente sem suporte, um operador só. |

### Qualidade dos testes, em detalhe

- **Os QAs conferem texto em vez de comportamento?** Em boa parte, sim. No backend, os serviços rodam de verdade, mas contra classes que estendem `PDO` e respondem por expressão regular sobre o texto do SQL (`scripts/qa-schedule-history.php:213`, `225`; `scripts/qa-security.php:118`). O teste prova que o PHP emite aquele texto, não que o MySQL o aceita nem que restrição, gatilho ou trava se comportam como o simulador supõe. Há também asserções diretas sobre o fonte (`scripts/qa-security.php:2556`) e sobre a posição de trechos nas migrations (`scripts/qa-schedule-history.php:494-510`). No frontend o quadro é misto: 11 dos 19 `qa-*.mjs` carregam módulos reais de `src/` por import dinâmico, alguns com renderização estática do componente (por exemplo `frontend/scripts/qa-schedule-rules.mjs:199-207`, `qa-employee-form.mjs:172`, `201`); os outros 8 (`qa-action-feedback`, `qa-action-runner`, `qa-bundle`, `qa-forms`, `qa-query`, `qa-resource`, `qa-table`, `qa-ux-hardening`) só leem o fonte como texto. Mesmo nos que executam código, a busca de texto pesa (o `qa-unsaved-changes.mjs` tem 207 em 756 linhas).
- **`qa-deploy-runbook.php`** (545 linhas, cerca de 100 buscas de texto) confere que roteiro e folha dizem o mesmo. É útil contra divergência entre os dois, mas não detecta erro de procedimento: C1, C2 e M1 estão iguais nos dois documentos e passam.
- **Regras críticas sem teste que eu tenha achado:** exceção de escala em data passada (M3); o que o `GET` da escala entrega por perfil (A1); o caminho de reserva do login com erro de banco (`auth_ldap.php:302-322`); a semeadura automática (M7); deadlock e nova tentativa; o `.htaccess` sob Apache real (só o `test-linux.sh`, no servidor, depois do deploy).
- **Mutação.** Está descrita em documentos (por exemplo `docs/FUSO_HORARIO_BIZ02.md:270`: 10 defeitos, 9 detectados), mas não há script nem ferramenta no repositório: não é reproduzível, e os mutantes foram escolhidos por quem escreveu o código e o teste.
- **Roteiro de navegador.** Um script (`frontend/scripts/browser/qa-unsaved-changes-browser.mjs`, 2.547 linhas) contra um **servidor simulado** (`lib/mock-server.mjs`), não contra o PHP. Cobre caminhos de erro simulados (409, 403 e 500 em `:1498-1500`, `:2321`, `:2481`), o que é bom, mas não existe teste de ponta a ponta entre o React e a API real. O "obrigatório antes do deploy" valida a tela, não a integração.

---

## 7. As 10 coisas que eu faria primeiro depois do deploy

1. Instalar a rotina de backup e fazer o teste de restauração (hoje não há backup automático).
2. Fechar o vazamento do `GET` da escala (A1).
3. HTTPS próprio com cookie `Secure`, e LDAPS ou StartTLS para o AD (C4).
4. Logar a mensagem real dos erros de banco e separar conexão de consulta (A4).
5. Testes de integração contra MySQL real para login, escala, ausência e Mapa PA (A5).
6. Rodar a correção histórica do BIZ-02 logo após o item 1, com a guarda no degrau 2 (A3), para encurtar o período de histórico misturado.
7. Remover a semeadura automática e os nomes do código (M7, M8).
8. LGPD: retenção para `audit_log`, pausas e ausências; apagar as quatro cópias de junho, os dumps do deploy e as tabelas `manutencao_*`; decidir a fila de sincronização (M4).
9. CI com lint, QAs, `npm audit` e `gitleaks` (M13); confirmar o suporte do MySQL 8.0 (M12).
10. Ambiente de homologação, mesmo que seja um segundo vhost com um banco de cópia anonimizada, para o deploy 2 não repetir o C3.

Na minha avaliação, **não deveriam ter ficado para depois:** a rotina de backup (o deploy mais arriscado do projeto acontece sem ela, apoiado num dump manual) e a remoção dos arquivos das páginas legadas, que continuam no servidor protegidos só por regras do `.htaccess` (`DEPLOY_LINUX.md:4001-4012`).

---

## 8. O que NÃO consegui verificar e por quê

- **Servidor, banco e rede:** proibido pelas regras. Tudo o que depende deles está marcado A CONFIRMAR com a consulta exata.
- **Execução dos QAs:** não rodei nenhum. O único comando composto que tentei travou por erro meu de aspas, sem alterar nada (conferi com `git status`); não insisti para não gastar a janela. As conclusões sobre testes vêm de contagem e amostragem.
- **Não lidos linha a linha:** a maior parte de `services/OperationalService.php` (2.800 linhas; li cerca de 900), `PaMapService.php`, `CriticalIncidentService.php` (por exemplo, quais campos um técnico pode preencher ao criar chamado, `api/portal/critical_incidents.php:35-40`), `AbsenceService.php`, `EmployeeArchiveService.php`, `PresenceEngine` e `PresenceService`, `GerenciadorPausas.php`, `SpreadsheetImportService.php` (só o tratamento de XML, `:315-318`), `MailerService` e `NotificationService` (e-mail desligado), `integrations/`.
- **Rollbacks da 012, 014, 015 e 017**, as verificações `*_check.sql` e os scripts `ensaio-migration.sh` e `backup-chronodesk.sh`: não lidos.
- **Frontend:** só busquei padrões perigosos; não revisei a lógica das telas nem os contratos campo a campo contra `docs/API_CONTRACTS_FRONTEND.md`.
- **Documentos grandes:** do `DESENHO_PA_ESCALA_AUSENCIA.md` (726 KB) e do `REGISTRO_USO_IA.md` (281 KB) li só trechos por busca; o `DEPLOY1_INVENTARIO.md` não foi lido; da folha do dia conferi a ordem e os pontos dos achados, não comando a comando.
- **Dependências:** `npm audit` e consulta de CVE precisam de rede. Só registro que as versões do `frontend/package.json` usam faixa (`^`) e dependem do lockfile, e que o PHP não tem dependência de terceiros (não há `composer.json`).
- **Configuração real:** `.env`, vhost, `php.ini`, permissões de arquivos e opções do OPcache do servidor só são conhecidos pelos exemplos do repositório.
