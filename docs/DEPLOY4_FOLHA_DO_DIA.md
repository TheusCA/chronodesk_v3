# Deploy 4 (consolidado): folha de execucao do dia

Gerada do roteiro "Deploy 4 (consolidado: deploys 4, 5, 6 e 7 numa execucao so; migrations 021, 020, 024 e 025)" do `DEPLOY_LINUX.md`: os mesmos comandos, na mesma ordem. O roteiro e a referencia: o detalhe de cada esperado esta la. Mudou o roteiro, muda esta folha no mesmo commit. Substitui as folhas separadas dos deploys 4 a 7 (decisao do responsavel, 2026-11-18).

**O que vai:** as migrations 021 (times de rodizio e grupo da escala), 020 (equipes Critico e Hibrido e o limite aplicado da pausa; copia a `portal_absences`), 024 (rodizio de papeis do Critico) e 025 (Controle de CHG), nesta ordem, todas antes do merge; o codigo do F10-2, do F9, do icone novo, do rodizio de papeis e do Controle de CHG com o grupo Tools. O Controle de CHG entra desligado e so e ligado nos comandos 43 a 47, depois das conferencias. Ninguem muda de time, equipe ou jornada no deploy. O lockfile nao mudou desde o `cd0653b`: so o build. **Pre-requisito:** producao no `cd0653b`.

**Data, janela e quem acompanha: A DEFINIR.**

**Shell root, sem `sudo`.** Abra o terminal da VM com `sudo -i` e rode **todos** os comandos desta folha nele, como estao (nenhum leva `sudo`; o que roda como `www-data` usa `runuser`). O comando 1 e a conferencia do ambiente. Terminal reaberto: `sudo -i` e o comando 1 de novo.

**Sem valor real nesta folha**, nem impressa nem anotada nela: nada de senha, IP, hostname, nome ou login. O `ServerName` e lido do `chronodesk.conf` na hora (tambem o `HOST_HEADER` do polling). A senha do root do MySQL e sempre digitada no `read -rsp` (sem eco). Numeros anotados vao para o registro do deploy, nunca para chat ou chamado; da importacao, so as contagens.

**Regras de parada em toda a folha:** qualquer linha com `PARE`, ou saida diferente do esperado: pare e envie so a contagem ou a mensagem. Nada de `git pull`; o unico `git fetch` e o do comando 3. **As migrations param no primeiro numero fora do esperado** (comandos 27 a 34): fique em manutencao e nao aplique a seguinte.

## 0. Antes do dia, na estacao

- [ ] **0.** O responsavel publica o `feat/employee-form-rhf-zod`. Depois, a bateria completa sozinha (nada pesado em paralelo): `git fetch origin; git worktree add --detach ..\chronodesk-deploy4-consolidado origin/feat/employee-form-rhf-zod; Set-Location ..\chronodesk-deploy4-consolidado\frontend; npm ci; Set-Location ..; $env:CHROME_PATH='C:\Program Files\Google\Chrome\Application\chrome.exe'; powershell -NoProfile -ExecutionPolicy Bypass -File scripts\test-local.ps1 -SkipHttp -Browser; git rev-parse HEAD`. Esperado: `Validacao local concluida com sucesso`, `frontend: 30 scripts npm rodados (...): 30 OK, 0 com falha`, navegador com `falharam: 0` e o hash completo: anote como `<commit validado na estacao>`. Qualquer falha: nao ha deploy com esse commit.
- [ ] **0b.** Na vespera, o aviso a Lideranca e a gestao do roteiro (passo 0b), com a data e a janela preenchidas; sem nome, hostname nem endereco.

## 1. Conferencia do ambiente, commit validado, producao no cd0653b e a lista das migrations

- [ ] **1.** (1) Esperado: antes, `sudo -i`. Oito linhas `OK` e `ambiente: OK`. **PARE se:** qualquer `BLOQUEADO` ou `PARE: ambiente com BLOQUEADO`.

```bash
R=OK; [ "$(id -u)" = 0 ] && echo "OK id -u = 0" || { echo "BLOQUEADO id -u (o shell nao e root)"; R=PARE; }; bash -c 'exit 0' && echo "OK bash -c" || { echo "BLOQUEADO bash -c"; R=PARE; }; tar -czf - -C /etc hostname 2>/dev/null | tar -tzf - >/dev/null 2>&1 && echo "OK tar com gzip" || { echo "BLOQUEADO tar com gzip"; R=PARE; }; [ "$(find /etc/hostname -maxdepth 0 -exec echo ok \; 2>/dev/null)" = ok ] && echo "OK find -exec" || { echo "BLOQUEADO find -exec"; R=PARE; }; runuser -u www-data -- php -v >/dev/null 2>&1 && echo "OK runuser -u www-data -- php -v" || { echo "BLOQUEADO runuser -u www-data -- php -v"; R=PARE; }; [ "$(runuser -u www-data -- php -r '$p = proc_open([PHP_BINARY, "-v"], [1 => ["pipe", "w"]], $x); if (!is_resource($p)) { exit(1); } stream_get_contents($x[1]); echo proc_close($p) === 0 ? "ok" : "falha";' 2>/dev/null)" = ok ] && echo "OK php filho como www-data (proc_open, como no qa-security)" || { echo "BLOQUEADO php filho como www-data"; R=PARE; }; npm -v >/dev/null 2>&1 && echo "OK npm -v" || { echo "BLOQUEADO npm -v"; R=PARE; }; git -C /var/www/chronodesk rev-parse --git-dir >/dev/null 2>&1 && echo "OK git no clone" || { echo "BLOQUEADO git no clone"; R=PARE; }; [ "$R" = OK ] && echo "ambiente: OK" || echo "PARE: ambiente com BLOQUEADO"; unset R
```

- [ ] **2.** (1) Esperado: `HEAD de producao: cd0653b (OK, gravado em pre-deploy4-commit)`, `branch: codex/modernizacao-ui-backend`, `alteracoes locais: 0`. **PARE se:** `PARE: HEAD de producao ...` (o deploy 3 nao terminou com o `cd0653b`: traga o hash curto) ou alteracao local.

```bash
cd /var/www/chronodesk; install -d -o root -g root -m 700 /var/backups/chronodesk; H=$(git rev-parse HEAD); if [ "$H" = "cd0653b22a528cb3cb700b57c05a729cdf6f6f5e" ]; then echo "$H" | tee /var/backups/chronodesk/pre-deploy4-commit >/dev/null; echo "HEAD de producao: cd0653b (OK, gravado em pre-deploy4-commit)"; else echo "PARE: HEAD de producao $(git rev-parse --short HEAD), nao o cd0653b"; fi; echo "branch: $(git rev-parse --abbrev-ref HEAD)"; echo "alteracoes locais: $(git status --porcelain | wc -l)"; unset H
```

- [ ] **3.** (1) Esperado: antes, troque `<commit validado na estacao>` (item 0). O hash completo impresso (gravado em `deploy4-commit`). **PARE se:** `PARE: origin/... nao e o commit validado na estacao` ou erro do `fetch`.

```bash
cd /var/www/chronodesk; git fetch origin feat/employee-form-rhf-zod && K=$(git rev-parse origin/feat/employee-form-rhf-zod) && if [ "$K" = "<commit validado na estacao>" ]; then echo "$K" | tee /var/backups/chronodesk/deploy4-commit; else echo "PARE: origin/feat/employee-form-rhf-zod ($K) nao e o commit validado na estacao"; fi; unset K
```

- [ ] **4.** (1) Esperado: `fast-forward OK: <N> commits a aplicar` (anote), `migrations alteradas: exatamente as 16 esperadas (021, 020, 024 e 025)` e `lockfile alterado: 0` (com `1`, anote: o comando 36 faz o `npm ci`). **PARE se:** linha `PARE` (sem uma das quatro migrations, HEAD nao ancestral, ou outra lista de migrations).

```bash
cd /var/www/chronodesk; D4=$(cat /var/backups/chronodesk/deploy4-commit); git cat-file -e "$D4^{commit}" && git cat-file -e "$D4:scripts/smoke-deploy1.sh" && git cat-file -e "$D4:migrations/20261115_021_rotation_teams.sql" && git cat-file -e "$D4:migrations/20261116_020_teams_critico_hibrido.sql" && git cat-file -e "$D4:migrations/20261117_024_critical_roles.sql" && git cat-file -e "$D4:migrations/20261117_025_chg.sql" && git merge-base --is-ancestor HEAD "$D4" && echo "fast-forward OK: $(git rev-list --count HEAD.."$D4") commits a aplicar" || echo "PARE: deploy4-commit ausente, sem o smoke-deploy1.sh, sem uma das quatro migrations ou o HEAD nao e ancestral dele"; L=$(git diff --name-only HEAD "$D4" -- migrations | sort | tr '\n' ' '); [ "$L" = "migrations/20261115_021_rotation_teams.sql migrations/20261116_020_teams_critico_hibrido.sql migrations/20261117_024_critical_roles.sql migrations/20261117_025_chg.sql migrations/rollback/20261115_021_ensaio_linhas_f10.sql migrations/rollback/20261115_021_rotation_teams_down.sql migrations/rollback/20261116_020_ensaio_linhas_f9.sql migrations/rollback/20261116_020_teams_critico_hibrido_down.sql migrations/rollback/20261117_024_critical_roles_down.sql migrations/rollback/20261117_024_ensaio_linhas_pc.sql migrations/rollback/20261117_025_chg_down.sql migrations/rollback/20261117_025_ensaio_linhas_chg.sql migrations/verificacao/20261115_021_rotation_teams_check.sql migrations/verificacao/20261116_020_teams_critico_hibrido_check.sql migrations/verificacao/20261117_024_critical_roles_check.sql migrations/verificacao/20261117_025_chg_check.sql " ] && echo "migrations alteradas: exatamente as 16 esperadas (021, 020, 024 e 025)" || echo "PARE: migrations alteradas diferentes das esperadas: $L"; echo "lockfile alterado: $(git diff --name-only HEAD "$D4" -- frontend/package-lock.json | wc -l)"; unset D4 L
```

## 2. Ensaio do codigo do commit gravado (em `/tmp`, como `www-data`; nenhum QA toca o banco real)

- [ ] **5.** (2) Esperado: `/tmp/.env ausente: OK`. **PARE se:** `PARE: existe /tmp/.env`.

```bash
[ ! -e /tmp/.env ] && echo "/tmp/.env ausente: OK" || echo "PARE: existe /tmp/.env; o ensaio leria esse arquivo"
```

- [ ] **6.** (2) Esperado: `extraido em /tmp/ensaio-deploy4-qa.<sufixo>`. **PARE se:** `PARE: falha ao extrair o deploy4-commit`.

```bash
cd /var/www/chronodesk; export D4=$(cat /var/backups/chronodesk/deploy4-commit); Q=$(mktemp -d /tmp/ensaio-deploy4-qa.XXXXXX); export Q; bash -o pipefail -c 'git archive "$D4" | tar -x -C "$Q"' && chown -R www-data: "$Q" && echo "extraido em $Q" || echo "PARE: falha ao extrair o deploy4-commit"
```

- [ ] **7.** (2) Esperado: nenhuma linha antes de `fim do php -l`. **PARE se:** qualquer linha antes de `fim do php -l`.

```bash
find "$Q" -name '*.php' -not -path '*/node_modules/*' -exec runuser -u www-data -- php -l {} \; | grep -v '^No syntax errors' ; echo "fim do php -l"
```

- [ ] **8.** (2) Esperado: os 22 QAs com `OK` (com o `qa-rotation-teams`, o `qa-critico-hibrido`, o `qa-critical-roles` e o `qa-chg`). **PARE se:** qualquer `FALHOU` (rode o QA de novo sem o `>/dev/null` e envie so o nome e a ultima linha).

```bash
[ ! -e /tmp/.env ] && for q in qa-smoke qa-auth qa-timezone qa-overtime qa-schedule-history qa-schedule-legacy-output qa-presence-engine qa-absences qa-calendar-text qa-archive qa-presence-cutoff qa-pa-conflicts qa-operational-view qa-employee-lunch qa-pa-distribution qa-paridade-escala qa-rotation-teams qa-critico-hibrido qa-critical-roles qa-chg qa-security qa-deploy-runbook; do printf '%s: ' "$q"; runuser -u www-data -- php "$Q/scripts/$q.php" >/dev/null 2>&1 && echo OK || echo FALHOU; done
```

- [ ] **9.** (2) Esperado: `removido: ...` e `ensaios restantes: 0`. **PARE se:** `ensaios restantes:` diferente de 0.

```bash
for Q in /tmp/ensaio-deploy4-qa.??????; do [ -d "$Q" ] && rm -r -- "$Q" && echo "removido: $Q"; done; echo "ensaios restantes: $(ls -d /tmp/ensaio-deploy4-qa.?????? 2>/dev/null | wc -l)"; unset Q D4
```

## 3. Q-F9 e o "antes" das quatro migrations no banco real (portal no ar; so leitura)

- [ ] **10.** (3) Esperado: as quatro verificacoes, na ordem: **021** com `tabelas_021 = 0`, `colunas_021 = 0`, `restricoes_021 = 0`, `copias_de_rollback_021 = 0` e "021 ausente"; **020 (Q-F9)** com `enums_com_critico_020 = 0`, `coluna_020 = 0`, `checks_ausencias = 2`, `copias_de_rollback_020 = 0`, `ativos_equipe_desconhecida = 0`, linhas com equipe nova em 0, "020 ausente" e "021 ausente" no grupo da escala; **024 (Q-PC)** com `tabelas_024 = 0`, `checks_024 = 0`, `copias_de_rollback_024 = 0` e "024 ausente"; **025 (Q-CHG)** com `tabelas_025 = 0`, `checks_025 = 0`, `copias_de_rollback_025 = 0` e "025 ausente". Anote o numero de linhas e as assinaturas das quatro (referencia dos comandos 28 a 34 e 42), os cadastros por equipe, as pausas por motivo (sem `Almoço`) e o total de ausencias. **PARE se:** `ativos_equipe_desconhecida` maior que 0 (corrigir pela tela antes), copia de rollback maior que 0, outro numero ou erro do MySQL.

```bash
cd /var/www/chronodesk; export D4=$(cat /var/backups/chronodesk/deploy4-commit); read -rsp 'Senha root do MySQL: ' P; echo; export P; for V in 20261115_021_rotation_teams_check 20261116_020_teams_critico_hibrido_check 20261117_024_critical_roles_check 20261117_025_chg_check; do echo "== $V"; export V; bash -o pipefail -c 'git show "$D4:migrations/verificacao/$V.sql" | docker exec -i -e MYSQL_PWD="$P" Chrono_Desk_DB mysql -uroot --table sistema_pausas' || echo "PARE: a verificacao $V falhou"; done; unset P D4 V
```

## 4. Ensaios das quatro migrations no MySQL do servidor (portal no ar; cada ensaio sobre a copia ja com as anteriores)

- [ ] **11.** (4) Esperado: `extraido em /root/ensaio-deploy4.<sufixo>`. **PARE se:** `PARE: falha ao extrair as migrations do deploy4-commit`.

```bash
cd /var/www/chronodesk; export D4=$(cat /var/backups/chronodesk/deploy4-commit); EV=$(mktemp -d /root/ensaio-deploy4.XXXXXX); export EV; git cat-file -e "$D4^{commit}" && bash -o pipefail -c 'git archive "$D4" migrations scripts/ensaio-migration.sh | tar -x -C "$EV"' && echo "extraido em $EV" || echo "PARE: falha ao extrair as migrations do deploy4-commit"; unset D4
```

- [ ] **12.** (4) Esperado: `preparos das anteriores: 3 arquivos`. **PARE se:** `PARE: falha ao montar os preparos`.

```bash
M="$EV/migrations"; cat "$M/20261115_021_rotation_teams.sql" "$M/20261116_020_teams_critico_hibrido.sql" > "$EV/preparo-021-020.sql" && cat "$EV/preparo-021-020.sql" "$M/20261117_024_critical_roles.sql" > "$EV/preparo-021-020-024.sql" && cat "$EV/preparo-021-020-024.sql" "$M/20261117_025_chg.sql" > "$EV/preparo-021-020-024-025.sql" && echo "preparos das anteriores: $(ls "$EV"/preparo-021-*.sql | wc -l) arquivos" || echo "PARE: falha ao montar os preparos"; unset M
```

- [ ] **13.** (4) Esperado: 021 (a): migration 2x `OK`, com `tabelas_021 = 3`, `colunas_021 = 2`, `restricoes_021 = 9`; `rollback, 1a vez: OK`, com `copias_de_rollback_021 = 5` e "021 ausente"; a 2a vez para de proposito (`rollback_021 abortado`); assinaturas de `regras` e `funcionarios` iguais nos cinco estados; `Removido.` **PARE se:** `FALHOU` fora do 2o rollback ou assinatura diferente.

```bash
env DADOS=portal_schedule_rules,portal_schedule_exceptions,portal_absences,pausas VERIFICA="$EV/migrations/verificacao/20261115_021_rotation_teams_check.sql" bash "$EV/scripts/ensaio-migration.sh" d4_021 "$EV/migrations/20261115_021_rotation_teams.sql" "$EV/migrations/rollback/20261115_021_rotation_teams_down.sql" portal_pa_assignments,portal_shift_attachments,portal_overtime_entries,portal_time_adjustments,portal_oncall_shifts,audit_log
```

- [ ] **14.** (4) Esperado: 021 (b), com `== Preparo: ...` (a 021): depois das linhas, `times = 1`, `versoes = 1`, `pertencas_abertas = 1`, `regras_de_time = 1`, as duas incoerencias em 0 (os tres primeiros em 0 se a copia nao tiver N1 ativo com regra aberta); o 1o rollback `OK`, o 2o para de proposito; `Removido.` **PARE se:** `Preparo falhou`, `FALHOU` fora do 2o rollback ou assinatura das regras diferente.

```bash
env PREPARO="$EV/migrations/20261115_021_rotation_teams.sql" DADOS=portal_schedule_rules,portal_schedule_exceptions,portal_absences,pausas VERIFICA="$EV/migrations/verificacao/20261115_021_rotation_teams_check.sql" bash "$EV/scripts/ensaio-migration.sh" d4_021_rollback "$EV/migrations/rollback/20261115_021_ensaio_linhas_f10.sql" "$EV/migrations/rollback/20261115_021_rotation_teams_down.sql" portal_pa_assignments,portal_shift_attachments,portal_overtime_entries,portal_time_adjustments,portal_oncall_shifts,audit_log
```

- [ ] **15.** (4) Esperado: 020 (a), com `== Preparo: ...` (a 021): migration 2x `OK`, com `enums_com_critico_020 = 8`, `coluna_020 = 1`, `checks_ausencias = 2`, `pausas_com_limite = 0`, `critico_ou_hibrido_no_grupo_comum = 0`; `rollback, 1a vez: OK`, com `copias_de_rollback_020 = 1` e "020 ausente"; a 2a vez para de proposito (`rollback_020 abortado: ja existe pausas_limite_bkp_020`); assinaturas de `pausas`, `ausencias` e `regras` iguais nos cinco estados; `Removido.` **PARE se:** `Preparo falhou`, `FALHOU` fora do 2o rollback ou assinatura diferente.

```bash
env PREPARO="$EV/migrations/20261115_021_rotation_teams.sql" DADOS=portal_schedule_rules,portal_schedule_exceptions,portal_absences,pausas VERIFICA="$EV/migrations/verificacao/20261116_020_teams_critico_hibrido_check.sql" bash "$EV/scripts/ensaio-migration.sh" d4_020 "$EV/migrations/20261116_020_teams_critico_hibrido.sql" "$EV/migrations/rollback/20261116_020_teams_critico_hibrido_down.sql" portal_pa_assignments,portal_shift_attachments,portal_overtime_entries,portal_time_adjustments,portal_oncall_shifts,audit_log
```

- [ ] **16.** (4) Esperado: 020 (b), com `== Preparo: ...` (a 021 e a 020): depois das linhas, um cadastro a mais em `critico`, `pausas_com_limite = 1`, `almoco_fora_do_critico = 0`, `almoco_com_alerta = 0`, `critico_ou_hibrido_no_grupo_comum = 0`; os **dois** rollbacks param de proposito (`ha linhas com equipe critico ou hibrido`) e nada muda (`coluna_020 = 1`, `enums_com_critico_020 = 8`); `Removido.` **PARE se:** `Preparo falhou`, um rollback `OK` ou outro numero.

```bash
env PREPARO="$EV/preparo-021-020.sql" DADOS=portal_schedule_rules,portal_schedule_exceptions,portal_absences,pausas VERIFICA="$EV/migrations/verificacao/20261116_020_teams_critico_hibrido_check.sql" bash "$EV/scripts/ensaio-migration.sh" d4_020_rollback "$EV/migrations/rollback/20261116_020_ensaio_linhas_f9.sql" "$EV/migrations/rollback/20261116_020_teams_critico_hibrido_down.sql" portal_pa_assignments,portal_shift_attachments,portal_overtime_entries,portal_time_adjustments,portal_oncall_shifts,audit_log
```

- [ ] **17.** (4) Esperado: 024 (a), com `== Preparo: ...` (a 021 e a 020): migration 2x `OK`, com `tabelas_024 = 3`, `checks_024 = 6`, `papeis = 0`, `rodizios = 0`, `trocas = 0`; `rollback, 1a vez: OK`, com `copias_de_rollback_024 = 3` e "024 ausente"; a 2a vez para de proposito; assinaturas de `cadastros` e `regras` iguais nos cinco estados; `Removido.` **PARE se:** `Preparo falhou`, `FALHOU` fora do 2o rollback ou assinatura diferente.

```bash
env PREPARO="$EV/preparo-021-020.sql" DADOS=portal_schedule_rules,portal_schedule_exceptions,portal_absences,pausas VERIFICA="$EV/migrations/verificacao/20261117_024_critical_roles_check.sql" bash "$EV/scripts/ensaio-migration.sh" d4_024 "$EV/migrations/20261117_024_critical_roles.sql" "$EV/migrations/rollback/20261117_024_critical_roles_down.sql" portal_pa_assignments,portal_shift_attachments,portal_overtime_entries,portal_time_adjustments,portal_oncall_shifts,audit_log
```

- [ ] **18.** (4) Esperado: 024 (b), com `== Preparo: ...` (a 021, a 020 e a 024): depois das linhas, `papeis = 2`, `rodizios = 1`, `trocas = 1` e as quatro contagens de problema em 0 (sem Critico ativo na copia, a assinatura de `cadastros` muda depois das linhas; a de `regras` fica igual); o 1o rollback `OK`, o 2o para de proposito; `Removido.` **PARE se:** `Preparo falhou`, `FALHOU` fora do 2o rollback, problema maior que 0 ou assinatura de `regras` diferente.

```bash
env PREPARO="$EV/preparo-021-020-024.sql" DADOS=portal_schedule_rules,portal_schedule_exceptions,portal_absences,pausas VERIFICA="$EV/migrations/verificacao/20261117_024_critical_roles_check.sql" bash "$EV/scripts/ensaio-migration.sh" d4_024_rollback "$EV/migrations/rollback/20261117_024_ensaio_linhas_pc.sql" "$EV/migrations/rollback/20261117_024_critical_roles_down.sql" portal_pa_assignments,portal_shift_attachments,portal_overtime_entries,portal_time_adjustments,portal_oncall_shifts,audit_log
```

- [ ] **19.** (4) Esperado: 025 (a), com `== Preparo: ...` (a 021, a 020 e a 024): migration 2x `OK`, com `tabelas_025 = 10`, `checks_025 = 27` e todas as contagens de linhas em 0; `rollback, 1a vez: OK`, com `copias_de_rollback_025 = 10` e "025 ausente"; a 2a vez para de proposito; assinatura dos `cadastros` igual nos cinco estados; `Removido.` **PARE se:** `Preparo falhou`, `FALHOU` fora do 2o rollback ou assinatura diferente.

```bash
env PREPARO="$EV/preparo-021-020-024.sql" DADOS=portal_schedule_rules,portal_schedule_exceptions,portal_absences,pausas VERIFICA="$EV/migrations/verificacao/20261117_025_chg_check.sql" bash "$EV/scripts/ensaio-migration.sh" d4_025 "$EV/migrations/20261117_025_chg.sql" "$EV/migrations/rollback/20261117_025_chg_down.sql" portal_pa_assignments,portal_shift_attachments,portal_overtime_entries,portal_time_adjustments,portal_oncall_shifts,audit_log
```

- [ ] **20.** (4) Esperado: 025 (b), com `== Preparo: ...` (as quatro): depois das linhas, `grupo_tools = 1`, `carteiras_catalogo = 1`, `locais_catalogo = 1`, `cards = 1`, `planos = 1`, `tentativas = 1`, `endpoints = 1`, `rollbacks_ativos = 1` e as contagens de problema (inclusive `tools_somente_leitura` e `tools_inativos`) em 0; o 1o rollback `OK` (`copias_de_rollback_025 = 10`), o 2o para de proposito; `Removido.` **PARE se:** `Preparo falhou`, `FALHOU` fora do 2o rollback ou problema maior que 0.

```bash
env PREPARO="$EV/preparo-021-020-024-025.sql" DADOS=portal_schedule_rules,portal_schedule_exceptions,portal_absences,pausas VERIFICA="$EV/migrations/verificacao/20261117_025_chg_check.sql" bash "$EV/scripts/ensaio-migration.sh" d4_025_rollback "$EV/migrations/rollback/20261117_025_ensaio_linhas_chg.sql" "$EV/migrations/rollback/20261117_025_chg_down.sql" portal_pa_assignments,portal_shift_attachments,portal_overtime_entries,portal_time_adjustments,portal_oncall_shifts,audit_log
```

- [ ] **21.** (4) Esperado: `removido: /root/ensaio-deploy4.<sufixo>`. **PARE se:** `PARE: diretorio do ensaio inesperado`.

```bash
case "$EV" in /root/ensaio-deploy4.*) rm -r "$EV" && echo "removido: $EV";; *) echo "PARE: diretorio do ensaio inesperado";; esac; unset EV
```

## 5. Espaco livre e backup rapido (portal no ar)

- [ ] **22.** (5) Esperado: seis linhas `OK` e `espaco livre: OK`. **PARE se:** `BAIXO` ou `PARE: espaco livre abaixo do minimo`.

```bash
cd /var/www/chronodesk/frontend; NPMC=$(npm config get cache); test -e "$NPMC" || NPMC=$(dirname "$NPMC"); DROOT=$(docker info --format '{{.DockerRootDir}}'); ESP=OK; for LIM in "/var/www/chronodesk:500" "$NPMC:200" "/var/backups:100" "$DROOT:200" "/tmp:50" "/root:50"; do DIR=${LIM%:*}; MIN=${LIM##*:}; LIVRE=$(df -BM --output=avail "$DIR" | tail -n 1 | tr -dc '0-9'); if [ "${LIVRE:-0}" -ge "$MIN" ]; then echo "OK $DIR (disco $(df --output=target "$DIR" | tail -n 1)): $LIVRE MB livres, minimo $MIN"; else echo "BAIXO $DIR: ${LIVRE:-?} MB livres, minimo $MIN"; ESP=PARE; fi; done; [ "$ESP" = OK ] && echo "espaco livre: OK" || echo "PARE: espaco livre abaixo do minimo"; unset NPMC DROOT ESP LIM DIR MIN LIVRE
```

- [ ] **23.** (5) Esperado: `backup concluido: /var/backups/chronodesk/deploy4-<data> (<tamanho>)` (anote). **PARE se:** `PARE: backup falhou`.

```bash
B=/var/backups/chronodesk/deploy4-$(date +%Y%m%d-%H%M%S); export B; read -rsp 'Senha root do MySQL: ' P; echo; export P; install -d -o root -g root -m 700 "$B" && bash -o pipefail -c 'docker exec -i -e MYSQL_PWD="$P" Chrono_Desk_DB mysqldump -uroot --single-transaction --routines --triggers --no-tablespaces --set-gtid-purged=OFF sistema_pausas | gzip | tee "$B/database.sql.gz" > /dev/null' && gzip -t "$B/database.sql.gz" && tar -czf "$B/estado.tar.gz" -C /var/www/chronodesk --ignore-failed-read estado.json pausas.csv config_sistema.json funcionarios.json && sh -c 'cd "$1" && sha256sum database.sql.gz estado.tar.gz > SHA256SUMS' _ "$B" && echo "backup concluido: $B ($(du -sh "$B" | cut -f1))" || echo "PARE: backup falhou (sem ponto de restauracao, o deploy nao segue)"; unset P
```

## 6. Manutencao e copia do `app/` (o portal responde 503 ate o comando 48)

- [ ] **24.** (6) Esperado: `arquivo de manutencao: presente`. **PARE se:** `AUSENTE`.

```bash
touch /var/www/chronodesk.maintenance; echo "arquivo de manutencao: $(test -e /var/www/chronodesk.maintenance && echo presente || echo AUSENTE)"
```

- [ ] **25.** (6) Esperado: `copia do app/: ...` (anote tamanho e arquivos). **PARE se:** `PARE: ...`.

```bash
cd /var/www/chronodesk; A=/var/backups/chronodesk/app-antes-deploy4; if test -e "$A"; then diff -rq app "$A" && echo "copia do app/ ja existe e e igual: $A" || echo "PARE: $A existe e e diferente do app/ atual"; else cp -a app "$A" && diff -rq app "$A" && echo "copia do app/: $A ($(du -sh "$A" | cut -f1); $(find "$A" -type f | wc -l) arquivos)" || echo "PARE: copia do app/ falhou"; fi; unset A
```

- [ ] **26.** (6) Esperado: `portal visto de fora (manutencao): 503`. **PARE se:** outro codigo ou `PARE: ServerName nao encontrado`.

```bash
S=$(awk 'tolower($1) == "servername" {print $2; exit}' /etc/apache2/sites-enabled/chronodesk.conf); if [ -n "$S" ]; then curl -s -o /dev/null -w 'portal visto de fora (manutencao): %{http_code}\n' "http://$S/"; else echo "PARE: ServerName nao encontrado no chronodesk.conf"; fi; unset S
```

## 7. As quatro migrations, antes do merge, na ordem 021, 020, 024, 025 (PARE no primeiro numero fora do esperado)

- [ ] **27.** (7) Esperado: `021 aplicada`. **PARE se:** `PARE: a 021 nao foi aplicada` (fique em manutencao; nao aplique a 020).

```bash
cd /var/www/chronodesk; export D4=$(cat /var/backups/chronodesk/deploy4-commit); read -rsp 'Senha root do MySQL: ' P; echo; export P; git cat-file -e "$D4:migrations/20261115_021_rotation_teams.sql" && bash -o pipefail -c 'git show "$D4:migrations/20261115_021_rotation_teams.sql" | docker exec -i -e MYSQL_PWD="$P" Chrono_Desk_DB mysql -uroot sistema_pausas' && echo "021 aplicada" || echo "PARE: a 021 nao foi aplicada"; unset P D4
```

- [ ] **28.** (7) Esperado: verificacao da 021: `tabelas_021 = 3`, `colunas_021 = 2`, `restricoes_021 = 9`, `copias_de_rollback_021 = 0`, as contagens dos times em 0, todos no grupo `comum` e as assinaturas iguais as do comando 10. **PARE se:** outro numero (fique em manutencao; nao aplique a 020).

```bash
cd /var/www/chronodesk; export D4=$(cat /var/backups/chronodesk/deploy4-commit); read -rsp 'Senha root do MySQL: ' P; echo; export P; bash -o pipefail -c 'git show "$D4:migrations/verificacao/20261115_021_rotation_teams_check.sql" | docker exec -i -e MYSQL_PWD="$P" Chrono_Desk_DB mysql -uroot --table sistema_pausas'; unset P D4
```

- [ ] **29.** (7) Esperado: `020 aplicada`. **PARE se:** `PARE: a 020 nao foi aplicada` (fique em manutencao; nao aplique a 024).

```bash
cd /var/www/chronodesk; export D4=$(cat /var/backups/chronodesk/deploy4-commit); read -rsp 'Senha root do MySQL: ' P; echo; export P; git cat-file -e "$D4:migrations/20261116_020_teams_critico_hibrido.sql" && bash -o pipefail -c 'git show "$D4:migrations/20261116_020_teams_critico_hibrido.sql" | docker exec -i -e MYSQL_PWD="$P" Chrono_Desk_DB mysql -uroot sistema_pausas' && echo "020 aplicada" || echo "PARE: a 020 nao foi aplicada"; unset P D4
```

- [ ] **30.** (7) Esperado: verificacao da 020: `enums_com_critico_020 = 8`, `enum_legado_pa_map_com_critico = 0`, `coluna_020 = 1`, `checks_ausencias = 2`, `copias_de_rollback_020 = 0`, `pausas_com_limite = 0`, `ativos_equipe_desconhecida = 0`, `critico_ou_hibrido_no_grupo_comum = 0`, as linhas com equipe nova em 0 e as assinaturas iguais as do comando 10. **PARE se:** outro numero (fique em manutencao; nao aplique a 024).

```bash
cd /var/www/chronodesk; export D4=$(cat /var/backups/chronodesk/deploy4-commit); read -rsp 'Senha root do MySQL: ' P; echo; export P; bash -o pipefail -c 'git show "$D4:migrations/verificacao/20261116_020_teams_critico_hibrido_check.sql" | docker exec -i -e MYSQL_PWD="$P" Chrono_Desk_DB mysql -uroot --table sistema_pausas'; unset P D4
```

- [ ] **31.** (7) Esperado: `024 aplicada`. **PARE se:** `PARE: a 024 nao foi aplicada` (fique em manutencao; nao aplique a 025).

```bash
cd /var/www/chronodesk; export D4=$(cat /var/backups/chronodesk/deploy4-commit); read -rsp 'Senha root do MySQL: ' P; echo; export P; git cat-file -e "$D4:migrations/20261117_024_critical_roles.sql" && bash -o pipefail -c 'git show "$D4:migrations/20261117_024_critical_roles.sql" | docker exec -i -e MYSQL_PWD="$P" Chrono_Desk_DB mysql -uroot sistema_pausas' && echo "024 aplicada" || echo "PARE: a 024 nao foi aplicada"; unset P D4
```

- [ ] **32.** (7) Esperado: verificacao da 024: `tabelas_024 = 3`, `checks_024 = 6`, `copias_de_rollback_024 = 0`, `papeis = 0`, `rodizios = 0`, `trocas = 0`, as contagens de problema em 0 e as assinaturas iguais as do comando 10. **PARE se:** outro numero (fique em manutencao; nao aplique a 025).

```bash
cd /var/www/chronodesk; export D4=$(cat /var/backups/chronodesk/deploy4-commit); read -rsp 'Senha root do MySQL: ' P; echo; export P; bash -o pipefail -c 'git show "$D4:migrations/verificacao/20261117_024_critical_roles_check.sql" | docker exec -i -e MYSQL_PWD="$P" Chrono_Desk_DB mysql -uroot --table sistema_pausas'; unset P D4
```

- [ ] **33.** (7) Esperado: `025 aplicada`. **PARE se:** `PARE: a 025 nao foi aplicada` (fique em manutencao).

```bash
cd /var/www/chronodesk; export D4=$(cat /var/backups/chronodesk/deploy4-commit); read -rsp 'Senha root do MySQL: ' P; echo; export P; git cat-file -e "$D4:migrations/20261117_025_chg.sql" && bash -o pipefail -c 'git show "$D4:migrations/20261117_025_chg.sql" | docker exec -i -e MYSQL_PWD="$P" Chrono_Desk_DB mysql -uroot sistema_pausas' && echo "025 aplicada" || echo "PARE: a 025 nao foi aplicada"; unset P D4
```

- [ ] **34.** (7) Esperado: verificacao da 025: `tabelas_025 = 10`, `checks_025 = 27`, `copias_de_rollback_025 = 0`, as contagens de linhas e de problema em 0 e a assinatura dos cadastros igual a do comando 10. **PARE se:** outro numero (fique em manutencao).

```bash
cd /var/www/chronodesk; export D4=$(cat /var/backups/chronodesk/deploy4-commit); read -rsp 'Senha root do MySQL: ' P; echo; export P; bash -o pipefail -c 'git show "$D4:migrations/verificacao/20261117_025_chg_check.sql" | docker exec -i -e MYSQL_PWD="$P" Chrono_Desk_DB mysql -uroot --table sistema_pausas'; unset P D4
```

## 8. `git merge --ff-only` do commit gravado, build e QA do bundle

- [ ] **35.** (8) Esperado: fast-forward, `HEAD = deploy4-commit` e `alteracoes locais: 0`. **PARE se:** `PARE: HEAD diferente do deploy4-commit`, erro do merge ou alteracao local.

```bash
cd /var/www/chronodesk; D4=$(cat /var/backups/chronodesk/deploy4-commit); git merge --ff-only "$D4"; [ "$(git rev-parse HEAD)" = "$D4" ] && echo "HEAD = deploy4-commit" || echo "PARE: HEAD diferente do deploy4-commit"; echo "alteracoes locais: $(git status --porcelain | wc -l)"; unset D4
```

- [ ] **36.** (8) Esperado: `lockfile sem mudanca: so o build` e o build sem erro. **PARE se:** erro do build (ou do `npm ci`, se o lockfile mudou).

```bash
cd /var/www/chronodesk/frontend && if [ "$(git diff --name-only "$(cat /var/backups/chronodesk/pre-deploy4-commit)" HEAD -- package-lock.json | wc -l)" = 0 ]; then echo "lockfile sem mudanca: so o build"; npm run build; else echo "lockfile alterado: npm ci e build"; npm ci && npm run build; fi
```

- [ ] **37.** (8) Esperado: `Bundle frontend QA OK (...)`: os quatro icones no build, o `sdk-icon.png` fora, o CSS do portal abaixo de 70000 bytes e o do Controle de CHG abaixo de 25000. **PARE se:** qualquer falha do `qa:bundle`.

```bash
cd /var/www/chronodesk/frontend && npm run -s qa:bundle
```

## 9. Recarga, health, fuso e teste de fumaca (ainda em manutencao, com o Controle de CHG desligado)

- [ ] **38.** (9) Esperado: recarga sem saida. **PARE se:** erro da recarga.

```bash
systemctl reload "php$(php -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;')-fpm"
```

- [ ] **39.** (9) Esperado: `health: 200`. **PARE se:** outro codigo ou `PARE: ServerName nao encontrado`.

```bash
S=$(awk 'tolower($1) == "servername" {print $2; exit}' /etc/apache2/sites-enabled/chronodesk.conf); if [ -n "$S" ]; then curl -s -o /dev/null -w 'health: %{http_code}\n' -H "Host: $S" http://127.0.0.1/api/health.php; else echo "PARE: ServerName nao encontrado no chronodesk.conf"; fi; unset S
```

- [ ] **40.** (9) Esperado: `QA timezone (banco real) OK`. **PARE se:** qualquer falha.

```bash
cd /var/www/chronodesk; runuser -u www-data -- php scripts/qa-timezone.php --db
```

- [ ] **41.** (9) Esperado: `FUMACA OK`. **PARE se:** qualquer `PARE` ou falha da fumaca: nao reabra.

```bash
cd /var/www/chronodesk; S=$(awk 'tolower($1) == "servername" {print $2; exit}' /etc/apache2/sites-enabled/chronodesk.conf); if [ -n "$S" ]; then env PHPLOG=/var/log/chronodesk/php_error.log bash scripts/smoke-deploy1.sh "$S"; else echo "PARE: ServerName nao encontrado no chronodesk.conf"; fi; unset S
```

## 10. As quatro verificacoes de novo (so leitura, com o codigo novo no ar)

- [ ] **42.** (10) Esperado: os mesmos numeros dos comandos 28, 30, 32 e 34, e as assinaturas iguais as do comando 10. **PARE se:** numero diferente.

```bash
cd /var/www/chronodesk; export D4=$(cat /var/backups/chronodesk/deploy4-commit); read -rsp 'Senha root do MySQL: ' P; echo; export P; for V in 20261115_021_rotation_teams_check 20261116_020_teams_critico_hibrido_check 20261117_024_critical_roles_check 20261117_025_chg_check; do echo "== $V"; export V; bash -o pipefail -c 'git show "$D4:migrations/verificacao/$V.sql" | docker exec -i -e MYSQL_PWD="$P" Chrono_Desk_DB mysql -uroot --table sistema_pausas' || echo "PARE: a verificacao $V falhou"; done; unset P D4 V
```

## 11. Ligar o Controle de CHG (ainda em manutencao)

- [ ] **43.** (11) Esperado: nada acima de `fim (esperado: nada acima)`. **PARE se:** linha no `grep` do pool (o pool vence o `.env`: avise).

```bash
grep -rn 'CHG_MODULE_ENABLED' /etc/php/*/fpm/ ; echo "fim (esperado: nada acima)"
```

- [ ] **44.** (11) Esperado: `controle de CHG (desligado, sem sessao): 404`. **PARE se:** outro codigo.

```bash
S=$(awk 'tolower($1) == "servername" {print $2; exit}' /etc/apache2/sites-enabled/chronodesk.conf); if [ -n "$S" ]; then curl -s -o /dev/null -w 'controle de CHG (desligado, sem sessao): %{http_code}\n' -H "Host: $S" http://127.0.0.1/api/portal/chg.php; else echo "PARE: ServerName nao encontrado no chronodesk.conf"; fi; unset S
```

- [ ] **45.** (11) Esperado: `copia do .env: ... (600 root)` e `linhas CHG_MODULE_ENABLED=true no .env: 1`. **PARE se:** `PARE: copia do .env falhou` ou outro numero.

```bash
K=/var/backups/chronodesk/env-antes-deploy4-$(date +%Y%m%d-%H%M%S); if install -m 600 /var/www/.env "$K"; then echo "copia do .env: $K ($(stat -c '%a %U' "$K"))"; if grep -q '^CHG_MODULE_ENABLED=' /var/www/.env; then sed -i 's/^CHG_MODULE_ENABLED=.*/CHG_MODULE_ENABLED=true/' /var/www/.env; else echo 'CHG_MODULE_ENABLED=true' >> /var/www/.env; fi; echo "linhas CHG_MODULE_ENABLED=true no .env: $(grep -c '^CHG_MODULE_ENABLED=true$' /var/www/.env)"; else echo "PARE: copia do .env falhou, nada alterado"; fi; unset K
```

- [ ] **46.** (11) Esperado: recarga sem saida. **PARE se:** erro da recarga.

```bash
systemctl reload "php$(php -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;')-fpm"
```

- [ ] **47.** (11) Esperado: `controle de CHG (ligado, sem sessao): 401`. **PARE se:** outro codigo.

```bash
S=$(awk 'tolower($1) == "servername" {print $2; exit}' /etc/apache2/sites-enabled/chronodesk.conf); if [ -n "$S" ]; then curl -s -o /dev/null -w 'controle de CHG (ligado, sem sessao): %{http_code}\n' -H "Host: $S" http://127.0.0.1/api/portal/chg.php; else echo "PARE: ServerName nao encontrado no chronodesk.conf"; fi; unset S
```

## 12. Reabertura e o `Content-Type` dos icones (so com os comandos 38 a 47 como esperado)

- [ ] **48.** (12) Esperado: `manutencao encerrada` e `portal visto de fora: 302`. **PARE se:** `PARE: arquivo de manutencao ainda presente` ou outro codigo.

```bash
[ -e /var/www/chronodesk.maintenance ] && rm -- /var/www/chronodesk.maintenance; [ -e /var/www/chronodesk.maintenance ] && echo "PARE: arquivo de manutencao ainda presente" || echo "manutencao encerrada"; S=$(awk 'tolower($1) == "servername" {print $2; exit}' /etc/apache2/sites-enabled/chronodesk.conf); if [ -n "$S" ]; then curl -s -o /dev/null -w 'portal visto de fora: %{http_code}\n' "http://$S/"; else echo "PARE: ServerName nao encontrado no chronodesk.conf"; fi; unset S
```

- [ ] **49.** (12) Esperado: `favicon.svg: 200 image/svg+xml`, `favicon.ico: 200 image/vnd.microsoft.icon` (ou `image/x-icon`) e `apple-touch-icon.png: 200 image/png`. **PARE se:** `text/html`, `text/plain` ou `application/octet-stream` num icone (anote e avise; nada muda no Apache durante o deploy).

```bash
S=$(awk 'tolower($1) == "servername" {print $2; exit}' /etc/apache2/sites-enabled/chronodesk.conf); if [ -n "$S" ]; then for F in favicon.svg favicon.ico apple-touch-icon.png; do printf '%s: ' "$F"; curl -s -o /dev/null -w '%{http_code} %{content_type}\n' -H "Host: $S" "http://127.0.0.1/app/$F?v=2"; done; else echo "PARE: ServerName nao encontrado no chronodesk.conf"; fi; unset S F
```

## 13. Conferencia na tela (como admin, Ctrl+F5 uma vez, sem salvar nada)

- [ ] Controle de CHG: o item **no menu**; o quadro com as quatro colunas vazias, os seis indicadores em 0, "Exportar Excel", "Importar do Controle v5" e "Nova atualização".
- [ ] Escala presencial: "Times de rodizio" com "Nenhum time de rodizio" e "Novo time" (abra e feche); as regras com "Editar" em todas; "Papeis do Critico" com "Sem pessoas no Critico"; "Papeis e rodizio" com as listas vazias (feche sem salvar).
- [ ] Regra por colaborador: "Ciclo presencial/remoto" mostra "Em blocos" e "Semana a semana"; a grade de 1 a 4 semanas (volte para "Sem escala definida").
- [ ] Funcionarios: "Editar" com "Grupo da escala: Comum", Critico e Hibrido na "Equipe operacional" e o campo "Grupo Tools" (feche sem salvar); os filtros de equipe e "Grupo Tools: todos".
- [ ] Visao operacional: Critico e Hibrido no bloco de baixo, em zero; o Total igual ao de antes (N1 + N2); sem o alerta "Papel vago no Critico hoje".
- [ ] Pausas: N1 e N2 como antes; sem "Almoço" para quem nao e do Critico.
- [ ] Icone: o novo na aba e no topo, nos dois temas.

**PARE se:** tela em branco ou quebrada, ou item que nao aparece: avise.

## 14. Medicao do polling (alguns minutos depois da reabertura; sessao de gestao; `HOST_HEADER` do `ServerName`)

- [ ] **50.** (14) Esperado: duas rodadas do `status.php`, com p50 e p95 (anote a melhor). **PARE se:** p95 acima de 50 ms **e** de 20% acima da base do deploy 3 (meca de novo e avise antes de qualquer reversao) ou `PARE: ServerName nao encontrado`.

```bash
cd /var/www/chronodesk; S=$(awk 'tolower($1) == "servername" {print $2; exit}' /etc/apache2/sites-enabled/chronodesk.conf); if [ -n "$S" ]; then for R in 1 2; do echo "== status.php, rodada $R"; HOST_HEADER="$S" bash scripts/medir-polling.sh; done; else echo "PARE: ServerName nao encontrado no chronodesk.conf"; fi; unset S R
```

- [ ] **51.** (14) Esperado: duas rodadas do `dashboard.php`, com p50 e p95 (anote a melhor). **PARE se:** o mesmo criterio do comando 50.

```bash
cd /var/www/chronodesk; S=$(awk 'tolower($1) == "servername" {print $2; exit}' /etc/apache2/sites-enabled/chronodesk.conf); if [ -n "$S" ]; then for R in 1 2; do echo "== dashboard.php, rodada $R"; HOST_HEADER="$S" URL=http://127.0.0.1/api/portal/dashboard.php bash scripts/medir-polling.sh; done; else echo "PARE: ServerName nao encontrado no chronodesk.conf"; fi; unset S R
```

- [ ] **52.** (14) Esperado: duas rodadas do `solicitacoes_pendentes.php`, com p50 e p95 (anote a melhor). **PARE se:** o mesmo criterio do comando 50.

```bash
cd /var/www/chronodesk; S=$(awk 'tolower($1) == "servername" {print $2; exit}' /etc/apache2/sites-enabled/chronodesk.conf); if [ -n "$S" ]; then for R in 1 2; do echo "== solicitacoes_pendentes.php, rodada $R"; HOST_HEADER="$S" URL=http://127.0.0.1/api/solicitacoes_pendentes.php bash scripts/medir-polling.sh; done; else echo "PARE: ServerName nao encontrado no chronodesk.conf"; fi; unset S R
```

**Daqui em diante, so se preciso e so com aprovacao do responsavel, cada degrau.** Rollback em degraus: codigo e `app/` primeiro (55 a 57); depois as migrations na ordem inversa: 025 (58 e 59), 024 (60 e 61), 020 (62 e 63), 021 (64 e 65). Cada rollback de migration confere o codigo do `cd0653b` no ar e a migration seguinte ja desfeita antes de tocar no banco.

## 15. Desligamento rapido do Controle de CHG (so se preciso, com aprovacao)

- [ ] **53.** (15) Esperado: `copia do .env: ... (600 root)` e `linhas CHG_MODULE_ENABLED=false no .env: 1`. **PARE se:** `PARE: copia do .env falhou`.

```bash
K=/var/backups/chronodesk/env-antes-desligar-chg-$(date +%Y%m%d-%H%M%S); if install -m 600 /var/www/.env "$K"; then echo "copia do .env: $K ($(stat -c '%a %U' "$K"))"; sed -i 's/^CHG_MODULE_ENABLED=.*/CHG_MODULE_ENABLED=false/' /var/www/.env; echo "linhas CHG_MODULE_ENABLED=false no .env: $(grep -c '^CHG_MODULE_ENABLED=false$' /var/www/.env)"; else echo "PARE: copia do .env falhou, nada alterado"; fi; unset K
```

- [ ] **54.** (15) Esperado: recarga sem saida; na tela, o menu sem o Controle de CHG. **PARE se:** erro da recarga.

```bash
systemctl reload "php$(php -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;')-fpm"
```

## 16. Rollback de codigo (so se preciso, com aprovacao; antes, cadastros Critico ou Hibrido de volta para N1 ou N2 pela tela)

- [ ] **55.** (16) Esperado: `codigo anterior no ar: cd0653b`. **PARE se:** erro do checkout.

```bash
cd /var/www/chronodesk; git checkout --detach "$(cat /var/backups/chronodesk/pre-deploy4-commit)" && echo "codigo anterior no ar: $(git rev-parse --short HEAD)"
```

- [ ] **56.** (16) Esperado: `app/ anterior de volta (o build retirado esta em ...)`. **PARE se:** `PARE: ...`.

```bash
cd /var/www/chronodesk; A=/var/backups/chronodesk/app-antes-deploy4; X=/var/backups/chronodesk/app-deploy4-retirado-$(date +%Y%m%d-%H%M%S); if test -d "$A"; then { ! test -e app || mv app "$X"; } && cp -a "$A" app && diff -rq "$A" app && echo "app/ anterior de volta (o build retirado esta em $X)" || echo "PARE: a volta do app/ falhou"; else echo "PARE: copia $A ausente; nada mudou no app/"; fi; unset A X
```

- [ ] **57.** (16) Esperado: recarga sem saida. Depois, os comandos 38 a 41 e a reabertura do comando 48. **PARE se:** erro da recarga.

```bash
systemctl reload "php$(php -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;')-fpm"
```

## 17. Rollback da 025 (so depois do 16, com aprovacao)

- [ ] **58.** (17) Esperado: `rollback da 025 aplicado`. **PARE se:** `PARE: ...` (`rollback_025 abortado: ja existe copia *_bkp_025`: confira e renomeie as copias antes).

```bash
cd /var/www/chronodesk; export D4=$(cat /var/backups/chronodesk/deploy4-commit); read -rsp 'Senha root do MySQL: ' P; echo; export P; [ "$(git rev-parse HEAD)" = "$(cat /var/backups/chronodesk/pre-deploy4-commit)" ] && git cat-file -e "$D4:migrations/rollback/20261117_025_chg_down.sql" && bash -o pipefail -c 'git show "$D4:migrations/rollback/20261117_025_chg_down.sql" | docker exec -i -e MYSQL_PWD="$P" Chrono_Desk_DB mysql -uroot sistema_pausas' && echo "rollback da 025 aplicado" || echo "PARE: o codigo no ar nao e o cd0653b ou o rollback da 025 falhou"; unset P D4
```

- [ ] **59.** (17) Esperado: `tabelas_025 = 0`, `copias_de_rollback_025 = 10`, "025 ausente" e a assinatura dos cadastros igual a do comando 34. **PARE se:** outro numero.

```bash
cd /var/www/chronodesk; export D4=$(cat /var/backups/chronodesk/deploy4-commit); read -rsp 'Senha root do MySQL: ' P; echo; export P; bash -o pipefail -c 'git show "$D4:migrations/verificacao/20261117_025_chg_check.sql" | docker exec -i -e MYSQL_PWD="$P" Chrono_Desk_DB mysql -uroot --table sistema_pausas'; unset P D4
```

## 18. Rollback da 024 (so depois do 17 com "025 ausente", com aprovacao)

- [ ] **60.** (18) Esperado: `rollback da 024 aplicado`. **PARE se:** `PARE: ...` (a 025 ainda existe: o 58 antes; ou `rollback_024 abortado`).

```bash
cd /var/www/chronodesk; export D4=$(cat /var/backups/chronodesk/deploy4-commit); read -rsp 'Senha root do MySQL: ' P; echo; export P; [ "$(git rev-parse HEAD)" = "$(cat /var/backups/chronodesk/pre-deploy4-commit)" ] && [ "$(docker exec -e MYSQL_PWD="$P" Chrono_Desk_DB mysql -uroot -N -e "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = 'sistema_pausas' AND TABLE_NAME = 'portal_chg_updates'")" = 0 ] && git cat-file -e "$D4:migrations/rollback/20261117_024_critical_roles_down.sql" && bash -o pipefail -c 'git show "$D4:migrations/rollback/20261117_024_critical_roles_down.sql" | docker exec -i -e MYSQL_PWD="$P" Chrono_Desk_DB mysql -uroot sistema_pausas' && echo "rollback da 024 aplicado" || echo "PARE: o codigo no ar nao e o cd0653b, a 025 ainda existe ou o rollback da 024 falhou"; unset P D4
```

- [ ] **61.** (18) Esperado: `tabelas_024 = 0`, `copias_de_rollback_024 = 3`, "024 ausente" e as assinaturas iguais as do comando 32. **PARE se:** outro numero.

```bash
cd /var/www/chronodesk; export D4=$(cat /var/backups/chronodesk/deploy4-commit); read -rsp 'Senha root do MySQL: ' P; echo; export P; bash -o pipefail -c 'git show "$D4:migrations/verificacao/20261117_024_critical_roles_check.sql" | docker exec -i -e MYSQL_PWD="$P" Chrono_Desk_DB mysql -uroot --table sistema_pausas'; unset P D4
```

## 19. Rollback da 020 (so depois do 18 com "024 ausente", com aprovacao)

- [ ] **62.** (19) Esperado: `rollback da 020 aplicado`. **PARE se:** `PARE: ...` (a 024 ainda existe: o 60 antes; ou `ha linhas com equipe critico ou hibrido`: volte os cadastros pela tela antes).

```bash
cd /var/www/chronodesk; export D4=$(cat /var/backups/chronodesk/deploy4-commit); read -rsp 'Senha root do MySQL: ' P; echo; export P; [ "$(git rev-parse HEAD)" = "$(cat /var/backups/chronodesk/pre-deploy4-commit)" ] && [ "$(docker exec -e MYSQL_PWD="$P" Chrono_Desk_DB mysql -uroot -N -e "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = 'sistema_pausas' AND TABLE_NAME = 'portal_critical_roles'")" = 0 ] && git cat-file -e "$D4:migrations/rollback/20261116_020_teams_critico_hibrido_down.sql" && bash -o pipefail -c 'git show "$D4:migrations/rollback/20261116_020_teams_critico_hibrido_down.sql" | docker exec -i -e MYSQL_PWD="$P" Chrono_Desk_DB mysql -uroot sistema_pausas' && echo "rollback da 020 aplicado" || echo "PARE: o codigo no ar nao e o cd0653b, a 024 ainda existe ou o rollback da 020 falhou"; unset P D4
```

- [ ] **63.** (19) Esperado: `enums_com_critico_020 = 0`, `coluna_020 = 0`, `copias_de_rollback_020 = 1`, "020 ausente" e as assinaturas iguais as do comando 30 se nenhuma pausa foi finalizada. **PARE se:** outro numero.

```bash
cd /var/www/chronodesk; export D4=$(cat /var/backups/chronodesk/deploy4-commit); read -rsp 'Senha root do MySQL: ' P; echo; export P; bash -o pipefail -c 'git show "$D4:migrations/verificacao/20261116_020_teams_critico_hibrido_check.sql" | docker exec -i -e MYSQL_PWD="$P" Chrono_Desk_DB mysql -uroot --table sistema_pausas'; unset P D4
```

## 20. Rollback da 021 (so depois do 19 com "020 ausente", com aprovacao)

- [ ] **64.** (20) Esperado: `rollback da 021 aplicado`. **PARE se:** `PARE: ...` (a 020 ainda existe: o 62 antes).

```bash
cd /var/www/chronodesk; export D4=$(cat /var/backups/chronodesk/deploy4-commit); read -rsp 'Senha root do MySQL: ' P; echo; export P; [ "$(git rev-parse HEAD)" = "$(cat /var/backups/chronodesk/pre-deploy4-commit)" ] && [ "$(docker exec -e MYSQL_PWD="$P" Chrono_Desk_DB mysql -uroot -N -e "SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = 'sistema_pausas' AND TABLE_NAME = 'pausas' AND COLUMN_NAME = 'limite_segundos'")" = 0 ] && git cat-file -e "$D4:migrations/rollback/20261115_021_rotation_teams_down.sql" && bash -o pipefail -c 'git show "$D4:migrations/rollback/20261115_021_rotation_teams_down.sql" | docker exec -i -e MYSQL_PWD="$P" Chrono_Desk_DB mysql -uroot sistema_pausas' && echo "rollback da 021 aplicado" || echo "PARE: o codigo no ar nao e o cd0653b, a 020 ainda existe ou o rollback da 021 falhou"; unset P D4
```

- [ ] **65.** (20) Esperado: `tabelas_021 = 0`, `colunas_021 = 0`, `copias_de_rollback_021 = 5`, "021 ausente" e as assinaturas iguais as do comando 10 se nenhum time foi usado. **PARE se:** outro numero.

```bash
cd /var/www/chronodesk; export D4=$(cat /var/backups/chronodesk/deploy4-commit); read -rsp 'Senha root do MySQL: ' P; echo; export P; bash -o pipefail -c 'git show "$D4:migrations/verificacao/20261115_021_rotation_teams_check.sql" | docker exec -i -e MYSQL_PWD="$P" Chrono_Desk_DB mysql -uroot --table sistema_pausas'; unset P D4
```

## Depois do deploy, com a gestao (pela tela, sem comando)

- [ ] Marcar o grupo Tools e as equipes Critico e Hibrido pela tela (Funcionarios); quem esta em cada grupo ou equipe nunca vai para o git nem para o chat.
- [ ] Importar o Controle v5 pela tela "Importar do Controle v5" (admin), com os dois arquivos da pasta de rede: **Ver previa** e anotar so as contagens; com o responsavel de acordo, **Confirmar importacao**; depois, a verificacao da 025 conta cards, carteiras e locais.
- [ ] Conferir que os tecnicos de CHG estao cadastrados em Funcionarios (a previa conta os "tecnicos sem correspondencia"; com numero maior que 0, conferir e cadastrar pela tela antes de confirmar).
- [ ] Depois de um ciclo estavel, com o responsavel: o push do commit validado para o `codex/modernizacao-ui-backend` (nunca `--force`) e, com aprovacao, a limpeza de `/var/backups/chronodesk` (`deploy4-<data>`, `app-antes-deploy4`, as copias `env-antes-*`, `pre-deploy4-commit` e `deploy4-commit`).
