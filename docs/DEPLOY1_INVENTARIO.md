# Deploy 1 da Parte B: inventário do que muda desde o `596db67`

**Data:** 2026-11-09. **Origem:** o pré-requisito 0a do roteiro do deploy 1 deu PARE no servidor (leitura do responsável, só leitura, 2026-11-09). **Consequência:** a fila antiga (deploys 1 a 10, de setembro e outubro, e os seguintes) **nunca foi aplicada**; o roteiro do deploy 1 passa a ser o consolidado de tudo desde o `596db67` (`DEPLOY_LINUX.md`, "Deploy 1 da Parte B"; desenho, seção 9.13.4).

Este inventário só lê o repositório. Nada foi executado no servidor. Onde diz "confere no 0b", o estado ainda não foi lido e o roteiro manda conferir antes do dia, só com contagens.

---

## 1. Ponto de partida (estado real, lido pelo responsável em 2026-11-09)

| Item | Valor |
|---|---|
| Código no ar | `596db67` ("feat: valida formulario de escala com rhf e zod", 2026-06-26), branch `codex/modernizacao-ui-backend` |
| Relação com a fila | Ancestral do `5d5c7c9` (59 commits até ele) e do `HEAD` do branch de trabalho (280 commits até o `d30b461`) |
| `origin/codex` no servidor | `072ed16` (15 commits à frente do `HEAD` do servidor, buscados e nunca aplicados) |
| Alteração local | Só o `.htaccess`, +6 linhas: `RewriteBase /` e os redirecionamentos de `^$`, `^var/www/chronodesk` e `^chronodesk` para `/app/` |
| MySQL | 8.0.46 (a 019 exige 8.0.29 ou mais novo) |
| Tamanho do banco | `sistema_pausas` com 1,8 MB (2026-11-09) |
| Backup pré-dia | `/root/backups_chronodesk/pre_deploy1_20261109_1549.sql.gz` (`mysqldump --single-transaction --routines --triggers --events`, modo `600`, "Dump completed"). Não substitui o backup do dia (passo 3d) |
| Cópia de ensaio | `sistema_pausas_ensaio`, no mesmo contêiner, restaurada do backup pré-dia, 33 tabelas. Origem do ensaio do BIZ-02 (0n) e dos 14 ensaios das migrations (passo 4, `ORIGEM`); apagada no 7m, com confirmação |
| Disco | `/var` com 88% de uso e 1,2 GB livres (código e Docker); `/` com 599 MB livres. Limites mínimos na seção 5 |
| Tabelas | As 29 das migrations 001 a 009 e do `database_SECURED.sql`, mais 4 cópias de junho (seção 8). Ausentes: `login_user_throttle` (015), `manutencao_*` (BIZ-02), `portal_presence_cutoff` (019), `funcionarios_perfil_016b` e qualquer `*_bkp_0NN` |

Conferência das tabelas: as migrations 001 a 009 criam `pause_approval_requests` e 11 `portal_*` (001), `portal_notification_reads` (002), 7 `portal_*` (003), `portal_document_files` (005), `portal_critical_incidents` (006), `portal_shift_attachments` (007), `portal_pa_map` (008), `portal_pa_inventory` e `portal_pa_assignments` (009); o `database_SECURED.sql` cria `audit_log`, `funcionarios`, `pausas` e `usuarios`. São as 29 do `SHOW TABLES`, sem falta nem sobra além das 4 cópias. A 004 e a 010 só alteram colunas: o `SHOW TABLES` não as confirma (confere no 0b). As consultas Q3, Q4, Q-F4, Q-F6 e Q-F7, que o responsável rodou no banco de produção entre 2026-10-07 e 2026-10-26, leram as colunas de `funcionarios`, de escala, de PA e de ausência que elas usam: o esquema do `596db67` está no banco.

---

## 2. Commits, por deploy da fila antiga

| Fila | Commits (`596db67`..) | O que traz | Banco | Infra e `.env` |
|---|---|---|---|---|
| 1 | Até `072ed16` (lotes 1 e 2, health, backup) | Domínio do AD fora dos exemplos (`d1cbbc5`); base do rewrite e redirects legados (`d7b8b19`, `8a8de91`); travas fora de produção (`55628bf`); CSV com TAB/CR (`86aeb46`); 401/403 únicos (`4e7fcdb`); `AD_SCHEME` (`0fe3b80`); health restrito por IP (`a8c1041`); preflight (`11a9729`); backup diário (`072ed16`) | — | `.htaccess`; `AD_SCHEME`; `HEALTH_ALLOWED_IPS`; rotina de backup (`deploy/`, `scripts/backup-chronodesk.sh`) |
| 2 | `d71fac5`, `0db826d` | Polling com `LOCK_SH` (PERF-01); modo de manutenção | — | `.htaccess` (regra de manutenção) |
| 3 | `c049868`, `beff99d` | Formulário de funcionário em RHF e Zod (A5) | — | — |
| 4 | `9957496` (+ `901d7a0`, `674931a`) | Fuso da sessão MySQL igual ao do PHP (BIZ-02) | Scripts `correcao_dados/biz02_0{1..4}` (não são migrations) | Sessão `America/Sao_Paulo` (tabelas de fuso do MySQL) |
| 5 | Lotes 6 e 7 (`a68066a` a `2a5497c`, `cc1bfab`) | Horas extras (totais e CSV sem truncar); limite de login por usuário (SEC-03) | 015 | `LOGIN_USER_MAX_FAILURES`, `LOGIN_USER_WINDOW_SECONDS`; `npm audit fix` (`4a7678c`) |
| 6 | Lote 5 (5a, 5b, 5c: `7fc3a38` a `f881266`) | Perfil de Liderança, revalidação da sessão, cadastro por perfil, admin só pela allowlist, `AUTH_SOURCE` removida, admin local travado | 016 (antes do código), 016b (depois) | `AD_ADMIN_USERS` obrigatória; `ENABLE_LOCAL_ADMIN` reprovado fora de `development`; `AUTH_SOURCE` obsoleta |
| 7 | Lote 4 (`211cd26` a `5d5c7c9`) | Anexo de escala por equipe (SEC-08), avisos por público (SEC-07), 503 com banco fora (0d) | 017 | — |
| 8 | F1 (`f91c850` a `137159d`) | Histórico de escala, Liderança na escala, fora do Mapa | 011 | — |
| 9 | F2 (`f91d2d0` a `0b918a6`) | Motor de presença, `presence.php`, presença no `status.php` | — (exige 011) | `STATUS_PRESENCE_ENABLED` |
| 10 | F2B (`fb53485` a `6354c8d`) | Ciclo, dias de home, prévia, Escala no motor | — (rollback do ciclo: `f2b_cycle_*`) | — |
| 11 | F3 (`faaea06` a `7d4cb27`) | Ausências | 012 | — |
| 12 | SEC-07b (`93d659d`, `41ee5c9`, `bf07c3e`) | Texto livre do Calendário só para a gestão | — | — |
| — | F4, F5, F6, F7, acabamento (17 a 17g, DD), retirada das páginas legadas (`caaab63`, `50f7674`), 9.12.1 a 9.12.5, commit 1 do F8 (sem efeito) | Já no roteiro do deploy 1 de 2026-11-06 | 014, 019 | `.htaccess` (páginas legadas) |

---

## 3. Migrations e scripts de dados

Ordem de aplicação no roteiro consolidado: **015, 016, 017, 011, 014, 019** (antes do código, cada uma com ensaio), **012 com o `git merge`**, **`biz02_01` logo depois do `merge`** (em manutenção, antes da recarga), **016b** (código novo no ar, em manutenção). A ordem segue a da fila antiga (015, 016 e 017 dos deploys 5 a 7; 011 do 8; 014 e 019 do F4 e do F5); nenhuma depende de outra fora dessa ordem, salvo a 016b, que exige a 016.

### 3.1 Migrations de esquema

| Migration | Commit | O que faz (arquivo:linha) | "Antes" que espera | Bate com o banco de hoje? | O que conferir antes |
|---|---|---|---|---|---|
| `migrations/20261001_015_login_user_throttle.sql` | `5f5a5c1` | Cria `login_user_throttle` (`:20`). Sem ela, o código do Lote 7 recusa **todo** login com 503 (`:10-11`) | Tabela ausente (`CREATE TABLE IF NOT EXISTS`) | **Sim**: ausente no `SHOW TABLES` | `migration_015 = 0` (0a) |
| `migrations/20261002_016_lideranca_profile.sql` | `91a57a6` | Para se houver `ad_login` repetido (`:24-41`); `access_role` ganha `lideranca` com `MODIFY` da lista inteira (`:44-62`); `UNIQUE uq_funcionarios_ad_login` (`:64-79`) | `access_role` = `enum('tecnico','gestor','admin','somente_leitura')` (criado pela 005); nenhum `ad_login` repetido; índice ausente | Tabela sim; coluna e duplicados: **confere no 0b** | `access_role_005 = 1`, `ad_login_repetidos = 0`, `uq_ad_login = 0` (0b). Antes de aplicar, `AD_ADMIN_USERS` no `.env` (preflight) |
| `migrations/20261002_016b_lideranca_profile_data.sql` | `4ee108d` | Exige a 016 (`:24-39`); guarda cada linha em `funcionarios_perfil_016b` (`:41-58`); `admin` da equipe Liderança vira `lideranca` (`:60-72`); `admin` inativo fora dela vira `tecnico` (`:74-86`); um evento no `audit_log` por passo | A 016 aplicada; **código novo no ar** (o anterior leria `lideranca` como técnico); `funcionarios_perfil_016b` ausente | Tabela de registro ausente: **sim** | `perfil_016b = 0` (0b); o ensaio com `PREPARO` da 016 no passo 4c mostra as contagens que a aplicação deve repetir. Grava no `audit_log`: só **depois** do `biz02_01` (seção 3.2) |
| `migrations/20261002_017_shift_attachment_team.sql` | `3cfe9f7` | `portal_shift_attachments.team` `AFTER reference_month` (`:19-34`) e índice (`:36-51`) | Coluna `team` ausente; `reference_month` presente (007) | Tabela sim; colunas: **confere no 0b** | `anexo_team = 0`, `anexo_reference_month = 1` (0b) |
| `migrations/20261005_011_schedule_history.sql` | `cc16dc2` | Índice da FK antes de derrubar `uq_schedule_rule_employee` (`:32-65`); `UNIQUE` por início (`:67-83`); regra aberta única (`:85-119`); `rule_type` com `cycle` por `MODIFY` da lista inteira (`:121-139`); `team` com `lideranca` nas regras e exceções (`:141-174`); `funcionarios.fora_mapa_escala` `AFTER ativo` (`:176-194`) | `uq_schedule_rule_employee` presente (003); `rule_type` com `fixed_weekdays` (010) e `rule_config` (010); `team` = `enum('n1','n2')` nas duas tabelas; uma regra por colaborador | Tabelas sim; índice, ENUMs e a 010: **confere no 0b** | `uq_regra_por_colaborador = 1`, `rule_type_010 = 1`, `rule_config_010 = 1`, `team_regras_n1_n2 = 1`, `team_excecoes_n1_n2 = 1`, `colunas_011_014 = 0` (0b) |
| `migrations/20261013_014_employee_archive.sql` | `1abb37a` | `arquivado_em` e `arquivado_por` no fim de `funcionarios` (`:42-71`); `CHECK ck_funcionario_arquivado` (`:73-90`) | Colunas e CHECK ausentes; nenhum ativo com data (as colunas não existem) | Tabela sim; colunas: **confere no 0b** | `colunas_011_014 = 0` (0b); Q-F4 logo antes (4f) |
| `migrations/20261013_019_presence_cutoff.sql` | `03359b5`, `b2c9bc5`, `96758ae` | `portal_presence_cutoff` vazia (`:52-72`) e dois gatilhos de imutabilidade (`:74-85`) | Tabela e gatilhos ausentes; MySQL 8.0.29 ou mais novo | **Sim**: ausente; MySQL 8.0.46 | `tabela_019 = 0` (0a); verificação da 019 com `inconsistente = 0` (0g) |
| `migrations/20261007_012_absences.sql` | `c69c5ec` | Para se `absence_type` existe e há linha (`:44-58`); colunas novas (`:60-134`); `absence_type` sai (`:136-148`); `status` só `approved` e `cancelled` (`:150-163`); índice e FK (`:165-194`); CHECKs (`:196-230`) | `portal_absences` **vazia** e no formato da 001 (com `absence_type`) | Tabela sim; vazia: Q3 de 2026-10-07 deu 0, **confere de novo** (0b e Q4 no 4i) | `ausencias = 0`, `absence_type = 1`, `colunas_012 = 0` (0b); Q4 imediatamente antes |

Migrations **fora** do deploy 1: a 013 (dispensada; Q3 e Q4 de 2026-10-07 em 0), a 018 (F8) e a 020 (F9), que não existem no commit. As 001 a 010 já estão no banco (seção 1).

### 3.2 Scripts de dados (não são migrations de esquema)

| Script | Commit | Quando | O que conferir |
|---|---|---|---|
| `migrations/correcao_dados/biz02_01_registrar_corte.sql` | `9957496` | **No dia, logo depois do `git merge`, com o portal em manutenção e antes da recarga do PHP-FPM** (`docs/FUSO_HORARIO_BIZ02.md`, seção 6; o arquivo, `:17-19`). Antes de qualquer gravação do código novo e antes da 016b e do registro do corte da 019, que gravam no `audit_log` no fuso novo | Guardas (`:23-30`): G1 linha no formato novo (`:66-69`), G2 salto para trás no `audit_log` (`:71`), G3 servidor fora de UTC (`:40`). Esperado `CORTE REGISTRADO` com as três em 0. Não altera dado da aplicação: cria `manutencao_biz02_datetime` e `manutencao_marcadores`. Ensaio antes do dia, numa cópia do banco (0n) |
| `biz02_02_verificar.sql` | `9957496` | Ensaio (0n) e antes e depois do script 3 | Só lê. V8 e V9 listam IDs internos de chamado crítico (sem dado pessoal): anote só quantas linhas |
| `biz02_03_corrigir.sql` | `9957496` | **Fora do deploy**: depois da rotina de backup instalada e do teste de restauração (seção 7.2 do BIZ-02; roteiro, passo 9c) | `CORRECAO EXECUTADA`, `nao_corrigidas = 0`; no ensaio, primeiro |
| `biz02_04_rollback.sql` | `9957496` | Ensaio (0n); em produção, só se a correção tiver de voltar | Volta ao "antes" do script 2 |
| `migrations/20261013_019_registrar_corte.sql` | `03359b5` | No dia, depois da recarga, só com a 16c em 0 | Como no roteiro do F5 |
| `migrations/rollback/20261007_f2b_cycle_down.sql`, `_up.sql`, `_ensaio_preparo.sql` e `verificacao/20261007_f2b_cycle_check.sql` | `86c8d92` | Só no rollback (degrau 2), com o código novo ainda no ar e `regras_cycle > 0` | `regras_cycle = 0` depois do `down`; o `up` só com o F2B de volta |
| `migrations/rollback/20261007_012_ensaio_linha_antiga.sql`, `_ensaio_linhas_f3.sql`; `20261013_014_ensaio_linhas_f4.sql` | `c69c5ec`, `1abb37a` | Só nos ensaios, no banco `ensaio_*` | Como nos roteiros do F3 e do F4 |

**Ordem que importa no BIZ-02:** o `audit_log.timestamp` é `DATETIME` preenchido pelo MySQL. Até o `merge`, o código anterior grava em UTC; depois dele, o novo grava em `America/Sao_Paulo`. Uma linha no fuso novo antes da fotografia dispara a G2 (salto de 3 h para trás), e a correção histórica passa a exigir análise manual (seção 9 do BIZ-02). Por isso: manutenção ligada **antes** do `merge` (o arquivo-sinal só passa a valer quando o `.htaccess` novo entra), `biz02_01` logo depois do `merge`, e só então a 016b, a recarga, as validações e a reabertura. As migrations de esquema não gravam data com o relógio do MySQL (regra 3 da seção 10 do BIZ-02) e as consultas do roteiro só leem.

---

## 4. `.env` e preflight

Nada é trocado no `.env` pelo roteiro, além do `APP_ENV` (NN7, passo 3b) e do interruptor do degrau 1. O preflight (`scripts/preflight-deploy.sh`, novo desde o `596db67`; commits `11a9729`, `5f5a5c1`, `91a57a6`, `9ef9cd5`, `f204ca6`, `2c1d457`) é lido do commit gravado e reprova o que impediria a subida.

| Variável | Commit | Efeito no código novo | Preflight |
|---|---|---|---|
| `AD_DOMAIN`, `AD_SERVERS` | `d1cbbc5` | Sem elas, o login AD falha fechado | `:57` FALHA se vazias |
| `APP_DEBUG`, `DB_USER`, `DB_PASS` | `55628bf` | Travas do `config.php:126` passam a valer fora de `development` (o servidor está em `staging`) | `:69`, `:73`, `:78` |
| `AD_SCHEME` (novo; padrão `ldap`) | `0fe3b80` | `auth_ldap.php:64`; ausente = `ldap`, como hoje | — |
| `HEALTH_ALLOWED_IPS` (novo; vazio = só loopback) | `a8c1041` | `api/health.php:23`: fora da lista, `403`. **Monitoração externa que consulte o health hoje passa a receber 403**: incluir o IP dela antes do dia, se existir | — |
| `LOGIN_USER_MAX_FAILURES`, `LOGIN_USER_WINDOW_SECONDS` (novos) | `5f5a5c1` | Ausentes: 5 e 900 | `:121-122` (faixa) |
| `AD_ADMIN_USERS` (obrigatória) | `91a57a6` | Admin vem só desta lista; sem ela, ninguém entra como admin | `:103` FALHA se vazia |
| `ENABLE_LOCAL_ADMIN` | `9ef9cd5` | Ligada fora de `development`: reprova | `:87` |
| `AUTH_SOURCE` (removida) | `9ef9cd5` | Não é lida | `:95` AVISO |
| `STATUS_PRESENCE_ENABLED` (nova; ausente = ligado) | `f204ca6`, `2c1d457` | Interruptor do motor ao vivo (degrau 1) | `:130` |
| `FORCE_HTTPS` | `55628bf` | Ligado sem vhost HTTPS: 301 em loop | `:135` FALHA se ligado |
| `APP_ENV` (`staging` → `production`, NN7) | — | Em `production`, a aplicação não sobe sem `SECRET_KEY` válida (`config.php:94`) | Não confere a chave: 0j |
| `SESSION_COOKIE_SECURE`, `APP_BASE_URL` | `0565100`, `147aebc` (só o exemplo) | Valem como hoje no servidor | — |

## 5. Dependências

- **npm:** nenhuma dependência direta nova (`frontend/package.json` só ganhou scripts de QA). O `package-lock.json` mudou num commit só (`4a7678c`, `npm audit fix` sem `--force`): 10 pacotes transitivos de build (`baseline-browser-mapping`, `brace-expansion`, `browserslist`, `caniuse-lite`, `electron-to-chromium`, `js-yaml`, `nanoid`, `node-releases`, `postcss`, `update-browserslist-db`). O `npm ci` do servidor baixa do registro, como nos builds anteriores.
- **PHP:** nenhuma extensão nova. Usadas: `pdo_mysql` e `ldap` (já usadas pelo `596db67`); o `qa-auth` e o `qa-security` do ensaio de código precisam do `php-ldap`.
- **MySQL:** 8.0.29 ou mais novo (019); tabelas de fuso carregadas (`mysql.time_zone_name`; BIZ-02, conferido em 2026-09-30 com 1795 linhas; de novo no 0b). Sem isso, o `db.php` cai para `-03:00` e registra aviso a cada conexão.
- **Espaço do `npm ci` e do build** (medido em 2026-11-09 na estação, com o `package-lock.json` do commit e um cache vazio; roteiro, 0m):

  | Item | Tamanho |
  |---|---|
  | `node_modules` | 114 MB; até uns 150 MB se o npm do servidor instalar as variantes `gnu` e `musl` dos binários nativos (`@rolldown/binding-linux-x64-*`, `lightningcss-linux-x64-*`; TO CONFIRM pela versão do npm) |
  | Cache do npm | 27 MB com o cache vazio. Com `sudo`, é o do root (em geral `/root/.npm`, na `/`) |
  | Build (`app/`) | 0,9 MB. Não é versionado: o rollback de código também roda o build |

  O `npm ci` apaga o `node_modules` antigo antes de instalar, mas os limites não contam com isso. No pior caso, o deploy pede uns 175 MB em `/var` (com o backup do dia, menos de 2 MB, e os bancos de ensaio, de poucos MB e um de cada vez) e uns 50 MB na `/` (com as extrações do commit: 7 MB em `/tmp` e 1 MB em `/root`).

  **Limites mínimos**, perto de três vezes o pior caso, conferidos no 0m e de novo no 3d, antes do backup e do `npm ci`:
  - código: 500 MB;
  - cache do npm: 200 MB;
  - `/var/backups`: 100 MB;
  - raiz do Docker: 200 MB;
  - `/tmp`: 50 MB;
  - `/root`: 50 MB.

  Com os números de 2026-11-09 (1,2 GB e 599 MB livres), passa.
- **Ferramentas do servidor usadas pelo roteiro:** `git`, `docker`, `mysqldump` (no contêiner), `gzip`, `sha256sum`, `curl`, `npm`. A rotina de backup (`072ed16`) **não** está instalada: o backup do dia é um `mysqldump` do root (passo 3d); a instalação da rotina fica para depois do deploy (passo 9c).

## 6. vhost e `.htaccess`

- **vhost:** nenhum commit muda o VirtualHost. O `AllowOverride` já vale: a alteração local do servidor (`RewriteBase /`) só funciona por isso.
- **`.htaccess`** (commits `d7b8b19`, `8a8de91`, `0db826d`, `caaab63`, `50f7674`): `RewriteBase /` (`.htaccess:62`); modo de manutenção (`:87-89`, arquivo-sinal `/var/www/chronodesk.maintenance`, fora do DocumentRoot, loopback passa); redirecionamentos de `^var/www/chronodesk` e `^chronodesk` para `/app/` (`:101-102`); páginas legadas (`:109-130`; `^$` para `app/` em GET e HEAD na `:122`, que com o `RewriteBase /` é o mesmo `/app/`).
- **Alteração local do servidor:** as três regras que o responsável viu (`RewriteBase /`, `^var/www/chronodesk`, `^chronodesk`) estão literalmente nas linhas 62, 101 e 102 do branch; o `^$` local vira o da linha 122. O roteiro guarda cópia do `.htaccess` modificado (passo 3a), confere as regras no commit gravado (passo 2) e descarta a mudança local **no mesmo comando** do `merge` (passo 6b), para o site não ficar sem o `RewriteBase` entre os dois. No rollback de código, a cópia volta (degrau 2): sem ela, o código anterior ficaria sem o `RewriteBase`.

## 7. Decisões e consultas da fila antiga que ainda valem

| Fila | Vale no deploy consolidado | Onde no roteiro |
|---|---|---|
| 1 | Preflight do commit gravado; `curl` dos redirects e bloqueios; QA pós-deploy (`qa-auth`, `qa-smoke`); health em `200` pelo loopback; `HEALTH_ALLOWED_IPS` decidido antes | 3c, 5, 6f, 7a, 7c; inventário seção 4 |
| 2 | Modo de manutenção usado na janela do código; medição do polling antes e depois (já é a base do critério do F2 e do F7) | 6b a 6g; 0d, 6a, 7j. O `MAINTENANCE_TEST=1` do `test-linux.sh` **não** roda na janela (com o arquivo-sinal presente ele falha sem removê-lo); fica para um horário sem uso depois |
| 3 | Editar um funcionário **sem login AD** pela tela | 7i (admin) |
| 4 | Corte do BIZ-02 em manutenção, antes da recarga; `qa-timezone.php --db` como `www-data`; correção histórica só depois do backup instalado e testado | 0n, 6c, 6f, 9c |
| 5 | `qa-overtime`; aprovar pendente com mais de 24 h dá 409 (só se houver; a consulta de 2026-10-02 deu 0 lançamento de 24 h); avisar quem consome o CSV de horas extras (colunas trocadas); consultas da seção 8 de `docs/HORAS_EXTRAS_LOTE6.md`; 015 antes do código; um login real com sucesso e a tabela sem linha para ele | 0c, 4b, 5, 7i, aviso |
| 6 | `AD_ADMIN_USERS` com o login do responsável (preflight); ensaio da 016 e da 016b; 016 antes do código e 016b depois; login de cada perfil; `ADMIN_ROLE_SEM_ALLOWLIST` no `audit_log`; revalidação com usuário de teste; sessões antigas com perfil maior são encerradas (todos entram de novo) | 3c, 4c, 6d, 7i, aviso |
| 7 | Contagem de `audience` dos avisos antes (valor fora do padrão fica só para a gestão); 017 antes do código; anexo de N1 invisível para técnico de N2; 503 com mensagem com o banco fora (não testado no dia: derrubaria o banco) | 0c, 4d, 7i |
| 8 a 12, F4 a F7, 9.12 | Como no roteiro do deploy 1 de 2026-11-06 (aprovado em 2026-11-09, NN1 a NN12, OO1 a OO6) | 0d a 0l, 4e a 4i, 5 a 9 |

Referências que mudam de sentido: a Q-F6, a Q-F7 e a 16c continuam na variante **sem a 011** antes do dia (a 011 também não está no banco, como o roteiro supunha); a verificação da 019 (16a) também. O que caiu: "deploys 1 a 7 no ar" (0a antigo), `migration_015/016/017 = 1` e a rotina de backup no passo 3d.

## 8. Pendência LGPD (registrada, sem mudança)

O banco de produção tem quatro cópias de junho: `funcionarios_backup_20260616_082511`, `funcionarios_cleanup_backup_20260616_092509`, `funcionarios_pre_horario_20260616_111259` e `pausas_cleanup_backup_20260616_092509`. Pelo nome, são cópias de `funcionarios` e de `pausas` (dados pessoais: nome, login, jornada e pausas de cada técnico), sem retenção definida, fora de qualquer rotina e sem uso pelo código (nenhuma referência no repositório). Elas também entram em todo `mysqldump` do banco (o do passo 3d e, depois, o da rotina diária, com retenção de 14 dias).

**Recomendação, depois do deploy e com aprovação do responsável:** (1) conferir, só com contagens, se alguma ainda tem uso (comparar `COUNT(*)` e o intervalo de datas com a tabela viva; nenhum código as lê); (2) se não houver motivo legal ou de auditoria para mantê-las, exportá-las uma vez para o diretório de backup (root, modo 700, com `SHA256SUMS`), registrar a decisão e a retenção, e removê-las do banco com `DROP TABLE` em janela própria; (3) se houver motivo, definir a retenção e o responsável e restringir o acesso (o usuário da aplicação não precisa de `SELECT` nelas). O deploy 1 não toca nelas; o 0b só as conta.

**Cópias de 2026-11-09, também com dados pessoais (inclusive as quatro de junho):**
- **Banco `sistema_pausas_ensaio`.** Retenção: até o fim do dia do deploy. O 7m o apaga com confirmação, também se o deploy parar, for revertido ou for cancelado. Se a data passar de 16/11, ele é apagado no mesmo dia e recriado perto da nova data.
- **Backup pré-dia** (`/root/backups_chronodesk/pre_deploy1_20261109_1549.sql.gz`, modo `600`, na `/`). O deploy não depende dele. Recomendação: mantê-lo com a mesma regra do backup do dia (até um ciclo estável depois do deploy) e apagá-lo com aprovação no 9e. Se a data for adiada sem previsão, apagá-lo antes.

## 9. Tempo do dia e viabilidade em 13/11, às 19:00

Estimativa por passo, com o banco pequeno (2026-10-02: 81 pausas, 3 horas extras, 3 chamados críticos; `funcionarios` na casa das dezenas) e o operador lendo cada saída:

| Passo | Minutos |
|---|---|
| 1 e 2 (conferência, commit gravado, regras do `.htaccess`) | 10 |
| 3 (rollback, cópia do `.htaccess`, `APP_ENV`, preflight, espaço livre, backup do banco de 1,8 MB) | 17 |
| 4 (ensaio e aplicação de 015, 016 com 016b, 017, 011, 014 com Q-F4, 019; ensaio da 012 e Q4): 14 ensaios de 3 a 5 min cada, lidos da cópia `sistema_pausas_ensaio` | 80 |
| 5 (ensaio do código: `php -l` e 16 QAs) | 10 |
| 6 (Q-F6, Q-F7 e base; manutenção, 012 e `merge`; `biz02_01`; 016b; build; recarga; validação em manutenção; reabertura) | 45 |
| 7 (verificações, D5, fora do Mapa, navegador por perfil com os itens dos lotes 4 a 7, medição, 16c e corte) | 75 |
| **Total sem incidente** | **~237 (cerca de 4 h)** |
| Reserva para um degrau 2 (código), se preciso | 20 |

**Conclusão:** a janela de 3 h (19:00 às 22:00) **não basta**. Em 13/11, às 19:00, é viável com uma janela até 00:00 (5 h: cerca de 4 h mais reserva), com duas ressalvas: (a) a manutenção (passos 6b a 6g, uns 25 a 30 min, por volta de 21:15) cai no turno de 15:30 a 23:59 e tira o registro de pausas do ar nesse intervalo, o que precisa ir no aviso; (b) o corte do F5 fica em 16/11 (deploy até o dia 15), e a 16c precisa dar 0 no dia. Alternativas: um sábado sem operação (14/11, mesmo corte 16/11), ou mover os 14 ensaios para a véspera (leem da cópia e não alteram o banco de produção; tiram uns 60 min do dia, que cabe em 19:00 às 22:00 sem folga). O tamanho medido do banco (1,8 MB, 2026-11-09) confirma a premissa: o tempo é o de ler as saídas, não o de executar.

**O ensaio do BIZ-02 não pede mais tempo no dia:** ele é antes do dia (0n) e, com a cópia já criada pelo responsável (2026-11-09), a conferência, os seis scripts e a conferência depois levam de 20 a 30 min. O risco de prazo é outro: se uma guarda disparar na cópia (G2, por exemplo), a correção exige análise antes do deploy, e ela não cabe entre 12/11 e 13/11. Para manter 13/11, os itens antes do dia precisam começar já: publicar o branch e o 0o (`-Browser`) até 10/11; 0a a 0n em 10 e 11/11 (a Q-F7 do 0f num dia útil, no expediente); o aviso (0p) em 12/11.
