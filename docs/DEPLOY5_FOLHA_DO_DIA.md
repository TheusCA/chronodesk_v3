# Deploy 5 (F10-3, 8.8, NC e icone): folha de execucao do dia

Gerada do roteiro "Deploy 5 (F10-3: inventario de PA completo, reservados do Critico e preferencias; migration 022; papel do dia do Critico numa fonte so, 8.8; NC na hora extra e na correcao de ponto, migration 026; icone novo)" do `DEPLOY_LINUX.md`: os mesmos comandos, na mesma ordem. O roteiro e a referencia: o detalhe de cada esperado esta la. Mudou o roteiro, muda esta folha no mesmo commit. (Ate 2026-11-19 esta folha so apontava para a do deploy 4 consolidado.)

**O que vai:** a migration 022 (inventario de PA: tipo, emprestimo, reserva do Critico e a tabela de preferencias; nenhuma linha muda) e a migration 026 (o NC no cadastro e a copia dele na hora extra e na correcao de ponto; nenhuma linha muda), antes do merge; o codigo do F10-3, o papel do dia do Critico numa fonte so (8.8: a Escala, o Calendario, os relatorios e o Mapa PA mostram o Critico pelo papel do dia, como a Visao operacional; sem migration), o NC nas telas e nos CSVs (a chave `NC_OBRIGATORIO` fica desligada), o icone novo (`?v=3`) e a licao do deploy 4 (umask). Ninguem muda de PA no deploy: a gestao marca os PAs pela tela depois. O lockfile nao mudou desde o `f0844fd`: so o build. **Pre-requisito:** producao no `f0844fd`.

**Data, janela e quem acompanha: A DEFINIR.**

**Shell root, sem `sudo`.** Abra o terminal da VM com `sudo -i` e rode **todos** os comandos desta folha nele, como estao (nenhum leva `sudo`; o que roda como `www-data` usa `runuser`). O comando 1 e a conferencia do ambiente, com o `umask 022`. Terminal reaberto: `sudo -i` e o comando 1 de novo. **Nenhum filtro de saida com `umask`** (o incidente do deploy 4).

**Sem valor real nesta folha**, nem impressa nem anotada nela: nada de senha, IP, hostname, nome ou login. O `ServerName` e lido do `chronodesk.conf` na hora (tambem o `HOST_HEADER` do polling). A senha do root do MySQL e sempre digitada no `read -rsp` (sem eco). Numeros anotados vao para o registro do deploy, nunca para chat ou chamado.

**Regras de parada em toda a folha:** qualquer linha com `PARE`, ou saida diferente do esperado: pare e envie so a contagem ou a mensagem. Nada de `git pull`; o unico `git fetch` e o do comando 3. **A 022 e a 026 param no primeiro numero fora do esperado** (comandos 23 a 26): fique em manutencao.

## 0. Antes do dia, na estacao

- [ ] **0.** O responsavel publica o `feat/employee-form-rhf-zod`. Depois, a bateria completa sozinha (nada pesado em paralelo): `git fetch origin; git worktree add --detach ..\chronodesk-deploy5 origin/feat/employee-form-rhf-zod; Set-Location ..\chronodesk-deploy5\frontend; npm ci; Set-Location ..; $env:CHROME_PATH='C:\Program Files\Google\Chrome\Application\chrome.exe'; powershell -NoProfile -ExecutionPolicy Bypass -File scripts\test-local.ps1 -SkipHttp -Browser; git rev-parse HEAD`. Esperado: `Validacao local concluida com sucesso`, `frontend: 30 scripts npm rodados (...): 30 OK, 0 com falha`, navegador com `falharam: 0` e o hash completo: anote como `<commit validado na estacao>`. Qualquer falha: nao ha deploy com esse commit.
- [ ] **0b.** Na vespera, o aviso a Lideranca e a gestao do roteiro (passo 0b), com a data e a janela preenchidas; sem nome, hostname nem endereco.

## 1. Conferencia do ambiente (com o umask), producao no f0844fd, commit validado e a lista das migrations

- [ ] **1.** (1) Esperado: antes, `sudo -i`. Nove linhas `OK` (com o `umask 0022`) e `ambiente: OK`. **PARE se:** qualquer `BLOQUEADO` ou `PARE: ambiente com BLOQUEADO`.

```bash
R=OK; [ "$(id -u)" = 0 ] && echo "OK id -u = 0" || { echo "BLOQUEADO id -u (o shell nao e root)"; R=PARE; }; bash -c 'exit 0' && echo "OK bash -c" || { echo "BLOQUEADO bash -c"; R=PARE; }; tar -czf - -C /etc hostname 2>/dev/null | tar -tzf - >/dev/null 2>&1 && echo "OK tar com gzip" || { echo "BLOQUEADO tar com gzip"; R=PARE; }; [ "$(find /etc/hostname -maxdepth 0 -exec echo ok \; 2>/dev/null)" = ok ] && echo "OK find -exec" || { echo "BLOQUEADO find -exec"; R=PARE; }; runuser -u www-data -- php -v >/dev/null 2>&1 && echo "OK runuser -u www-data -- php -v" || { echo "BLOQUEADO runuser -u www-data -- php -v"; R=PARE; }; [ "$(runuser -u www-data -- php -r '$p = proc_open([PHP_BINARY, "-v"], [1 => ["pipe", "w"]], $x); if (!is_resource($p)) { exit(1); } stream_get_contents($x[1]); echo proc_close($p) === 0 ? "ok" : "falha";' 2>/dev/null)" = ok ] && echo "OK php filho como www-data (proc_open, como no qa-security)" || { echo "BLOQUEADO php filho como www-data"; R=PARE; }; npm -v >/dev/null 2>&1 && echo "OK npm -v" || { echo "BLOQUEADO npm -v"; R=PARE; }; git -C /var/www/chronodesk rev-parse --git-dir >/dev/null 2>&1 && echo "OK git no clone" || { echo "BLOQUEADO git no clone"; R=PARE; }; umask 022 && [ "$(umask)" = 0022 ] && echo "OK umask $(umask) (arquivos novos legiveis pelo www-data)" || { echo "BLOQUEADO umask $(umask)"; R=PARE; }; [ "$R" = OK ] && echo "ambiente: OK" || echo "PARE: ambiente com BLOQUEADO"; unset R
```

- [ ] **2.** (1) Esperado: `HEAD de producao: f0844fd (OK, gravado em pre-deploy5-commit)`, `branch: codex/modernizacao-ui-backend`, `alteracoes locais: 0`. **PARE se:** `PARE: HEAD de producao ...` (o deploy 4 nao terminou com o `f0844fd`: traga o hash curto) ou alteracao local.

```bash
cd /var/www/chronodesk; install -d -o root -g root -m 700 /var/backups/chronodesk; H=$(git rev-parse HEAD); if [ "$H" = "f0844fdd350f3da53f08b422005caa8a5909b662" ]; then echo "$H" | tee /var/backups/chronodesk/pre-deploy5-commit >/dev/null; echo "HEAD de producao: f0844fd (OK, gravado em pre-deploy5-commit)"; else echo "PARE: HEAD de producao $(git rev-parse --short HEAD), nao o f0844fd"; fi; echo "branch: $(git rev-parse --abbrev-ref HEAD)"; echo "alteracoes locais: $(git status --porcelain | wc -l)"; unset H
```

- [ ] **3.** (1) Esperado: antes, troque `<commit validado na estacao>` (item 0). O hash completo impresso (gravado em `deploy5-commit`). **PARE se:** `PARE: origin/... nao e o commit validado na estacao` ou erro do `fetch`.

```bash
cd /var/www/chronodesk; git fetch origin feat/employee-form-rhf-zod && K=$(git rev-parse origin/feat/employee-form-rhf-zod) && if [ "$K" = "<commit validado na estacao>" ]; then echo "$K" | tee /var/backups/chronodesk/deploy5-commit; else echo "PARE: origin/feat/employee-form-rhf-zod ($K) nao e o commit validado na estacao"; fi; unset K
```

- [ ] **4.** (1) Esperado: `fast-forward OK: <N> commits a aplicar` (anote), `migrations alteradas: exatamente as 8 esperadas (022 e 026)` e `lockfile alterado: 0` (com `1`, anote: o build faz o `npm ci`). **PARE se:** linha `PARE` (sem a 022 ou a 026, HEAD nao ancestral, ou outra lista de migrations).

```bash
cd /var/www/chronodesk; D5=$(cat /var/backups/chronodesk/deploy5-commit); git cat-file -e "$D5^{commit}" && git cat-file -e "$D5:scripts/smoke-deploy1.sh" && git cat-file -e "$D5:migrations/20261119_022_pa_inventory.sql" && git cat-file -e "$D5:migrations/20261123_026_matricula_nc.sql" && git merge-base --is-ancestor HEAD "$D5" && echo "fast-forward OK: $(git rev-list --count HEAD.."$D5") commits a aplicar" || echo "PARE: deploy5-commit ausente, sem o smoke-deploy1.sh, sem a 022 ou a 026, ou o HEAD nao e ancestral dele"; L=$(git diff --name-only HEAD "$D5" -- migrations | sort | tr '\n' ' '); [ "$L" = "migrations/20261119_022_pa_inventory.sql migrations/20261123_026_matricula_nc.sql migrations/rollback/20261119_022_ensaio_linhas_inv.sql migrations/rollback/20261119_022_pa_inventory_down.sql migrations/rollback/20261123_026_ensaio_linhas_nc.sql migrations/rollback/20261123_026_matricula_nc_down.sql migrations/verificacao/20261119_022_pa_inventory_check.sql migrations/verificacao/20261123_026_matricula_nc_check.sql " ] && echo "migrations alteradas: exatamente as 8 esperadas (022 e 026)" || echo "PARE: migrations alteradas diferentes das esperadas: $L"; echo "lockfile alterado: $(git diff --name-only HEAD "$D5" -- frontend/package-lock.json | wc -l)"; unset D5 L
```

## 2. Ensaio do codigo do commit gravado (em `/tmp`, como `www-data`; nenhum QA toca o banco real)

- [ ] **5.** (2) Esperado: `/tmp/.env ausente: OK`. **PARE se:** `PARE: existe /tmp/.env`.

```bash
[ ! -e /tmp/.env ] && echo "/tmp/.env ausente: OK" || echo "PARE: existe /tmp/.env; o ensaio leria esse arquivo"
```

- [ ] **6.** (2) Esperado: `extraido em /tmp/ensaio-deploy5-qa.<sufixo>`. **PARE se:** `PARE: falha ao extrair`.

```bash
cd /var/www/chronodesk; export D5=$(cat /var/backups/chronodesk/deploy5-commit); Q=$(mktemp -d /tmp/ensaio-deploy5-qa.XXXXXX); export Q; bash -o pipefail -c 'git archive "$D5" | tar -x -C "$Q"' && chown -R www-data: "$Q" && echo "extraido em $Q" || echo "PARE: falha ao extrair o deploy5-commit"
```

- [ ] **7.** (2) Esperado: nenhuma linha antes de `fim do php -l`. **PARE se:** qualquer linha antes de `fim do php -l`.

```bash
find "$Q" -name '*.php' -not -path '*/node_modules/*' -exec runuser -u www-data -- php -l {} \; | grep -v '^No syntax errors' ; echo "fim do php -l"
```

- [ ] **8.** (2) Esperado: os 24 QAs com `OK`. **PARE se:** qualquer `FALHOU` (rode o QA de novo sem o `>/dev/null` e envie so o nome e a ultima linha).

```bash
[ ! -e /tmp/.env ] && for q in qa-smoke qa-auth qa-timezone qa-overtime qa-schedule-history qa-schedule-legacy-output qa-presence-engine qa-absences qa-calendar-text qa-archive qa-presence-cutoff qa-pa-conflicts qa-operational-view qa-employee-lunch qa-pa-distribution qa-paridade-escala qa-rotation-teams qa-critico-hibrido qa-critical-roles qa-chg qa-pa-inventory qa-nc qa-security qa-deploy-runbook; do printf '%s: ' "$q"; runuser -u www-data -- php "$Q/scripts/$q.php" >/dev/null 2>&1 && echo OK || echo FALHOU; done
```

- [ ] **9.** (2) Esperado: `removido: ...` e `ensaios restantes: 0`. **PARE se:** ensaio restante.

```bash
for Q in /tmp/ensaio-deploy5-qa.??????; do [ -d "$Q" ] && rm -r -- "$Q" && echo "removido: $Q"; done; echo "ensaios restantes: $(ls -d /tmp/ensaio-deploy5-qa.?????? 2>/dev/null | wc -l)"; unset Q D5
```

## 3. O "antes" da 022 e da 026 no banco real (portal no ar; so leitura)

- [ ] **10.** (3) Esperado: `colunas_022 = 0`, `checks_022 = 0`, `tabela_022 = 0`, `copias_de_rollback_022 = 0` e "022 ausente" (duas vezes); anote as linhas e as assinaturas do inventario e dos vinculos (a referencia dos passos 7 e 10). **PARE se:** copia de rollback maior que 0, outro numero ou erro do MySQL.

```bash
cd /var/www/chronodesk; export D5=$(cat /var/backups/chronodesk/deploy5-commit); read -rsp 'Senha root do MySQL: ' P; echo; export P; bash -o pipefail -c 'git show "$D5:migrations/verificacao/20261119_022_pa_inventory_check.sql" | docker exec -i -e MYSQL_PWD="$P" Chrono_Desk_DB mysql -uroot --table sistema_pausas' || echo "PARE: a verificacao da 022 falhou"; unset P D5
```

- [ ] **11.** (3) Esperado: `colunas_026 = 0`, `checks_026 = 0`, `indice_026 = 0`, `copias_de_rollback_026 = 0` e "026 ausente" (duas vezes); anote as linhas e as assinaturas do cadastro, das horas extras e das correcoes (a referencia dos passos 7 e 10). **PARE se:** copia de rollback maior que 0, outro numero ou erro do MySQL.

```bash
cd /var/www/chronodesk; export D5=$(cat /var/backups/chronodesk/deploy5-commit); read -rsp 'Senha root do MySQL: ' P; echo; export P; bash -o pipefail -c 'git show "$D5:migrations/verificacao/20261123_026_matricula_nc_check.sql" | docker exec -i -e MYSQL_PWD="$P" Chrono_Desk_DB mysql -uroot --table sistema_pausas' || echo "PARE: a verificacao da 026 falhou"; unset P D5
```

## 4. Ensaio da 022 e da 026 no MySQL do servidor (portal no ar)

- [ ] **12.** (4) Esperado: `extraido em /root/ensaio-deploy5.<sufixo>`. **PARE se:** `PARE: falha ao extrair`.

```bash
cd /var/www/chronodesk; export D5=$(cat /var/backups/chronodesk/deploy5-commit); EV=$(mktemp -d /root/ensaio-deploy5.XXXXXX); export EV; git cat-file -e "$D5^{commit}" && bash -o pipefail -c 'git archive "$D5" migrations scripts/ensaio-migration.sh | tar -x -C "$EV"' && echo "extraido em $EV" || echo "PARE: falha ao extrair as migrations do deploy5-commit"; unset D5
```

- [ ] **13.** (4) Esperado: variante (a) `d5_022`: `migration` 2x `OK`, com `colunas_022 = 3`, `checks_022 = 2`, `tabela_022 = 1` e todos os PAs `flexible`; `rollback, 1a vez: OK`, com `copias_de_rollback_022 = 2` e "022 ausente"; a 2a vez para de proposito (`rollback_022 abortado: ...`); as assinaturas iguais nos cinco estados; `Removido.` no fim. **PARE se:** `FALHOU` em etapa que nao seja o 2o rollback, ou assinatura diferente.

```bash
env DADOS=portal_pa_inventory,portal_pa_assignments VERIFICA="$EV/migrations/verificacao/20261119_022_pa_inventory_check.sql" bash "$EV/scripts/ensaio-migration.sh" d5_022 "$EV/migrations/20261119_022_pa_inventory.sql" "$EV/migrations/rollback/20261119_022_pa_inventory_down.sql" audit_log
```

- [ ] **14.** (4) Esperado: variante (b) `d5_022_rollback` (preparo: a 022): depois das linhas, `reservados_critico = 2`, `fixos = 1`, `fixos_que_emprestam = 1`, `preferencias = 1` e as contagens de problema em 0 (menos marcacoes se a copia tiver menos de 3 PAs sem vinculo vigente ou futuro); o 1o rollback `OK` (`copias_de_rollback_022 = 2`); o 2o para de proposito; as assinaturas iguais; `Removido.` no fim. **PARE se:** `Preparo falhou`, `FALHOU` em etapa que nao seja o 2o rollback, ou assinatura diferente.

```bash
env PREPARO="$EV/migrations/20261119_022_pa_inventory.sql" DADOS=portal_pa_inventory,portal_pa_assignments VERIFICA="$EV/migrations/verificacao/20261119_022_pa_inventory_check.sql" bash "$EV/scripts/ensaio-migration.sh" d5_022_rollback "$EV/migrations/rollback/20261119_022_ensaio_linhas_inv.sql" "$EV/migrations/rollback/20261119_022_pa_inventory_down.sql" audit_log
```

- [ ] **15.** (4) Esperado: variante (a) `d5_026`: `migration` 2x `OK`, com `colunas_026 = 3`, `checks_026 = 3`, `indice_026 = 1` e `ativos_com_nc = 0`; `rollback, 1a vez: OK`, com `copias_de_rollback_026 = 3` e "026 ausente"; a 2a vez para de proposito (`rollback_026 abortado`); as assinaturas do cadastro, das horas extras e das correcoes iguais nos cinco estados; no fim, `Removido.` **PARE se:** `FALHOU` em etapa que nao seja o 2o rollback ou assinatura diferente.

```bash
env DADOS=portal_overtime_entries,portal_time_adjustments VERIFICA="$EV/migrations/verificacao/20261123_026_matricula_nc_check.sql" bash "$EV/scripts/ensaio-migration.sh" d5_026 "$EV/migrations/20261123_026_matricula_nc.sql" "$EV/migrations/rollback/20261123_026_matricula_nc_down.sql" audit_log
```

- [ ] **16.** (4) Esperado: variante (b) `d5_026_rollback` (preparo: a 026): depois das linhas ficticias, `ativos_com_nc = 3` (menos com menos de 3 cadastros ativos), as copias nos lancamentos desses cadastros e as contagens de problema em 0; o 1o rollback `OK` (`copias_de_rollback_026 = 3`) e o 2o para de proposito; assinaturas iguais as do estado inicial. **PARE se:** `Preparo falhou`, `FALHOU` em etapa que nao seja o 2o rollback ou assinatura diferente.

```bash
env PREPARO="$EV/migrations/20261123_026_matricula_nc.sql" DADOS=portal_overtime_entries,portal_time_adjustments VERIFICA="$EV/migrations/verificacao/20261123_026_matricula_nc_check.sql" bash "$EV/scripts/ensaio-migration.sh" d5_026_rollback "$EV/migrations/rollback/20261123_026_ensaio_linhas_nc.sql" "$EV/migrations/rollback/20261123_026_matricula_nc_down.sql" audit_log
```

- [ ] **17.** (4) Esperado: `removido: /root/ensaio-deploy5.<sufixo>`. **PARE se:** `PARE: diretorio do ensaio inesperado`.

```bash
case "$EV" in /root/ensaio-deploy5.*) rm -r "$EV" && echo "removido: $EV";; *) echo "PARE: diretorio do ensaio inesperado";; esac; unset EV
```

## 5. Espaco livre e backup rapido (portal no ar)

- [ ] **18.** (5) Esperado: seis linhas `OK` e `espaco livre: OK`. **PARE se:** `BAIXO` ou `PARE`.

```bash
cd /var/www/chronodesk/frontend; NPMC=$(npm config get cache); test -e "$NPMC" || NPMC=$(dirname "$NPMC"); DROOT=$(docker info --format '{{.DockerRootDir}}'); ESP=OK; for LIM in "/var/www/chronodesk:500" "$NPMC:200" "/var/backups:100" "$DROOT:200" "/tmp:50" "/root:50"; do DIR=${LIM%:*}; MIN=${LIM##*:}; LIVRE=$(df -BM --output=avail "$DIR" | tail -n 1 | tr -dc '0-9'); if [ "${LIVRE:-0}" -ge "$MIN" ]; then echo "OK $DIR (disco $(df --output=target "$DIR" | tail -n 1)): $LIVRE MB livres, minimo $MIN"; else echo "BAIXO $DIR: ${LIVRE:-?} MB livres, minimo $MIN"; ESP=PARE; fi; done; [ "$ESP" = OK ] && echo "espaco livre: OK" || echo "PARE: espaco livre abaixo do minimo"; unset NPMC DROOT ESP LIM DIR MIN LIVRE
```

- [ ] **19.** (5) Esperado: `backup concluido: /var/backups/chronodesk/deploy5-<data> (<tamanho>)` (anote). **PARE se:** `PARE: backup falhou`.

```bash
B=/var/backups/chronodesk/deploy5-$(date +%Y%m%d-%H%M%S); export B; read -rsp 'Senha root do MySQL: ' P; echo; export P; install -d -o root -g root -m 700 "$B" && bash -o pipefail -c 'docker exec -i -e MYSQL_PWD="$P" Chrono_Desk_DB mysqldump -uroot --single-transaction --routines --triggers --no-tablespaces --set-gtid-purged=OFF sistema_pausas | gzip | tee "$B/database.sql.gz" > /dev/null' && gzip -t "$B/database.sql.gz" && tar -czf "$B/estado.tar.gz" -C /var/www/chronodesk --ignore-failed-read estado.json pausas.csv config_sistema.json funcionarios.json && sh -c 'cd "$1" && sha256sum database.sql.gz estado.tar.gz > SHA256SUMS' _ "$B" && echo "backup concluido: $B ($(du -sh "$B" | cut -f1))" || echo "PARE: backup falhou (sem ponto de restauracao, o deploy nao segue)"; unset P
```

## 6. Manutencao e copia do `app/` (o portal responde 503 ate o comando 37)

- [ ] **20.** (6) Esperado: `arquivo de manutencao: presente`. **PARE se:** `AUSENTE`.

```bash
touch /var/www/chronodesk.maintenance; echo "arquivo de manutencao: $(test -e /var/www/chronodesk.maintenance && echo presente || echo AUSENTE)"
```

- [ ] **21.** (6) Esperado: `copia do app/: ...` (anote tamanho e arquivos). **PARE se:** `PARE`.

```bash
cd /var/www/chronodesk; A=/var/backups/chronodesk/app-antes-deploy5; if test -e "$A"; then diff -rq app "$A" && echo "copia do app/ ja existe e e igual: $A" || echo "PARE: $A existe e e diferente do app/ atual"; else cp -a app "$A" && diff -rq app "$A" && echo "copia do app/: $A ($(du -sh "$A" | cut -f1); $(find "$A" -type f | wc -l) arquivos)" || echo "PARE: copia do app/ falhou"; fi; unset A
```

- [ ] **22.** (6) Esperado: `portal visto de fora (manutencao): 503`. **PARE se:** outro codigo ou `PARE: ServerName nao encontrado`.

```bash
S=$(awk 'tolower($1) == "servername" {print $2; exit}' /etc/apache2/sites-enabled/chronodesk.conf); if [ -n "$S" ]; then curl -s -o /dev/null -w 'portal visto de fora (manutencao): %{http_code}\n' "http://$S/"; else echo "PARE: ServerName nao encontrado no chronodesk.conf"; fi; unset S
```

## 7. A 022 e a 026, antes do merge (PARE no numero fora do esperado)

- [ ] **23.** (7) Esperado: `022 aplicada`. **PARE se:** `PARE: a 022 nao foi aplicada` (fique em manutencao; o codigo do `f0844fd` nao le a 022).

```bash
cd /var/www/chronodesk; export D5=$(cat /var/backups/chronodesk/deploy5-commit); read -rsp 'Senha root do MySQL: ' P; echo; export P; git cat-file -e "$D5:migrations/20261119_022_pa_inventory.sql" && bash -o pipefail -c 'git show "$D5:migrations/20261119_022_pa_inventory.sql" | docker exec -i -e MYSQL_PWD="$P" Chrono_Desk_DB mysql -uroot sistema_pausas' && echo "022 aplicada" || echo "PARE: a 022 nao foi aplicada"; unset P D5
```

- [ ] **24.** (7) Esperado: `colunas_022 = 3`, `checks_022 = 2`, `tabela_022 = 1`, `copias_de_rollback_022 = 0`, todos os PAs ativos `flexible` (`fixos = 0`, `reservados_critico = 0`, `fixos_que_emprestam = 0`), `preferencias = 0`, as contagens de problema em 0 e as assinaturas iguais as do passo 3. **PARE se:** outro numero (fique em manutencao).

```bash
cd /var/www/chronodesk; export D5=$(cat /var/backups/chronodesk/deploy5-commit); read -rsp 'Senha root do MySQL: ' P; echo; export P; bash -o pipefail -c 'git show "$D5:migrations/verificacao/20261119_022_pa_inventory_check.sql" | docker exec -i -e MYSQL_PWD="$P" Chrono_Desk_DB mysql -uroot --table sistema_pausas'; unset P D5
```

- [ ] **25.** (7) Esperado: `026 aplicada`. **PARE se:** `PARE: a 026 nao foi aplicada` (fique em manutencao; o codigo do `f0844fd` nao le a 026).

```bash
cd /var/www/chronodesk; export D5=$(cat /var/backups/chronodesk/deploy5-commit); read -rsp 'Senha root do MySQL: ' P; echo; export P; git cat-file -e "$D5:migrations/20261123_026_matricula_nc.sql" && bash -o pipefail -c 'git show "$D5:migrations/20261123_026_matricula_nc.sql" | docker exec -i -e MYSQL_PWD="$P" Chrono_Desk_DB mysql -uroot sistema_pausas' && echo "026 aplicada" || echo "PARE: a 026 nao foi aplicada"; unset P D5
```

- [ ] **26.** (7) Esperado: `colunas_026 = 3`, `checks_026 = 3`, `indice_026 = 1`, `copias_de_rollback_026 = 0`, `ativos_com_nc = 0` (todos sem NC ate a gestao preencher), `horas_extras_com_copia = 0`, `correcoes_com_copia = 0`, as contagens de problema em 0 e as assinaturas iguais as do passo 3. **PARE se:** outro numero ou assinatura diferente.

```bash
cd /var/www/chronodesk; export D5=$(cat /var/backups/chronodesk/deploy5-commit); read -rsp 'Senha root do MySQL: ' P; echo; export P; bash -o pipefail -c 'git show "$D5:migrations/verificacao/20261123_026_matricula_nc_check.sql" | docker exec -i -e MYSQL_PWD="$P" Chrono_Desk_DB mysql -uroot --table sistema_pausas'; unset P D5
```

## 8. `git merge --ff-only` do commit gravado, build, leitura pelo `www-data` e QA do bundle

- [ ] **27.** (8) Esperado: fast-forward, `HEAD = deploy5-commit` e `alteracoes locais: 0`. **PARE se:** `PARE: HEAD diferente do deploy5-commit`, erro do merge ou alteracao local.

```bash
cd /var/www/chronodesk; D5=$(cat /var/backups/chronodesk/deploy5-commit); git merge --ff-only "$D5"; [ "$(git rev-parse HEAD)" = "$D5" ] && echo "HEAD = deploy5-commit" || echo "PARE: HEAD diferente do deploy5-commit"; echo "alteracoes locais: $(git status --porcelain | wc -l)"; unset D5
```

- [ ] **28.** (8) Esperado: `lockfile sem mudanca: so o build` e o build sem erro. **PARE se:** erro do build (ou do `npm ci`, se o lockfile mudou).

```bash
cd /var/www/chronodesk/frontend && if [ "$(git diff --name-only "$(cat /var/backups/chronodesk/pre-deploy5-commit)" HEAD -- package-lock.json | wc -l)" = 0 ]; then echo "lockfile sem mudanca: so o build"; npm run build; else echo "lockfile alterado: npm ci e build"; npm ci && npm run build; fi
```

- [ ] **29.** (8) Esperado: `sem leitura para o www-data: 0 (arquivos do deploy e app/)` (licao do deploy 4). **PARE se:** `PARE: ... sem leitura para o www-data` ou `PARE: pre-deploy5-commit ausente`: fique em manutencao, nao reabra e avise.

```bash
cd /var/www/chronodesk; PRE=$(cat /var/backups/chronodesk/pre-deploy5-commit); if git cat-file -e "$PRE^{commit}" 2>/dev/null; then N=$( { git diff -z --name-only --diff-filter=d "$PRE" HEAD; find app -print0; } | runuser -u www-data -- xargs -0 -r sh -c 'for f; do if [ -d "$f" ]; then { [ -r "$f" ] && [ -x "$f" ]; } || echo x; else [ -r "$f" ] || echo x; fi; done' _ | wc -l); [ "$N" = 0 ] && echo "sem leitura para o www-data: 0 (arquivos do deploy e app/)" || echo "PARE: $N arquivos ou diretorios do deploy ou do app/ sem leitura para o www-data"; else echo "PARE: pre-deploy5-commit ausente"; fi; unset PRE N
```

- [ ] **30.** (8) Esperado: `Bundle frontend QA OK (...)`: os quatro icones no build, o CSS do portal abaixo de 70000 bytes e o do Controle de CHG abaixo de 25000. **PARE se:** qualquer falha do `qa:bundle`.

```bash
cd /var/www/chronodesk/frontend && npm run -s qa:bundle
```

## 9. Recarga, health, fuso e teste de fumaca (ainda em manutencao)

- [ ] **31.** (9) Esperado: recarga sem saida. **PARE se:** erro da recarga.

```bash
systemctl reload "php$(php -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;')-fpm"
```

- [ ] **32.** (9) Esperado: `health: 200`. **PARE se:** outro codigo ou `PARE: ServerName nao encontrado`.

```bash
S=$(awk 'tolower($1) == "servername" {print $2; exit}' /etc/apache2/sites-enabled/chronodesk.conf); if [ -n "$S" ]; then curl -s -o /dev/null -w 'health: %{http_code}\n' -H "Host: $S" http://127.0.0.1/api/health.php; else echo "PARE: ServerName nao encontrado no chronodesk.conf"; fi; unset S
```

- [ ] **33.** (9) Esperado: `QA timezone (banco real) OK`. **PARE se:** qualquer falha.

```bash
cd /var/www/chronodesk; runuser -u www-data -- php scripts/qa-timezone.php --db
```

- [ ] **34.** (9) Esperado: oito linhas `GET ...: 200` (com `calendar.php` e `reports.php`, 8.8, e os CSVs `overtime.php?format=csv` e `time_corrections.php?format=csv`, lote NC) e `FUMACA OK`. **PARE se:** qualquer `PARE` ou falha da fumaca: nao reabra.

```bash
cd /var/www/chronodesk; S=$(awk 'tolower($1) == "servername" {print $2; exit}' /etc/apache2/sites-enabled/chronodesk.conf); if [ -n "$S" ]; then env PHPLOG=/var/log/chronodesk/php_error.log bash scripts/smoke-deploy1.sh "$S"; else echo "PARE: ServerName nao encontrado no chronodesk.conf"; fi; unset S
```

## 10. As verificacoes da 022 e da 026 de novo (so leitura, com o codigo novo no ar)

- [ ] **35.** (10) Esperado: os mesmos numeros do passo 7 e as assinaturas iguais as do passo 3. **PARE se:** numero diferente.

```bash
cd /var/www/chronodesk; export D5=$(cat /var/backups/chronodesk/deploy5-commit); read -rsp 'Senha root do MySQL: ' P; echo; export P; bash -o pipefail -c 'git show "$D5:migrations/verificacao/20261119_022_pa_inventory_check.sql" | docker exec -i -e MYSQL_PWD="$P" Chrono_Desk_DB mysql -uroot --table sistema_pausas' || echo "PARE: a verificacao da 022 falhou"; unset P D5
```

- [ ] **36.** (10) Esperado: os mesmos numeros do passo 7 e as assinaturas iguais as do passo 3. **PARE se:** numero diferente.

```bash
cd /var/www/chronodesk; export D5=$(cat /var/backups/chronodesk/deploy5-commit); read -rsp 'Senha root do MySQL: ' P; echo; export P; bash -o pipefail -c 'git show "$D5:migrations/verificacao/20261123_026_matricula_nc_check.sql" | docker exec -i -e MYSQL_PWD="$P" Chrono_Desk_DB mysql -uroot --table sistema_pausas' || echo "PARE: a verificacao da 026 falhou"; unset P D5
```

## 11. Reabertura (so com os comandos 31 a 36 como esperado)

- [ ] **37.** (11) Esperado: `manutencao encerrada` e `portal visto de fora: 302`. **PARE se:** `PARE` ou outro codigo.

```bash
[ -e /var/www/chronodesk.maintenance ] && rm -- /var/www/chronodesk.maintenance; [ -e /var/www/chronodesk.maintenance ] && echo "PARE: arquivo de manutencao ainda presente" || echo "manutencao encerrada"; S=$(awk 'tolower($1) == "servername" {print $2; exit}' /etc/apache2/sites-enabled/chronodesk.conf); if [ -n "$S" ]; then curl -s -o /dev/null -w 'portal visto de fora: %{http_code}\n' "http://$S/"; else echo "PARE: ServerName nao encontrado no chronodesk.conf"; fi; unset S
```

- [ ] **38.** (11) Esperado: `favicon.svg: 200 image/svg+xml`, `favicon.ico: 200 image/vnd.microsoft.icon` (ou `image/x-icon`) e `apple-touch-icon.png: 200 image/png` (o icone B, com `?v=3`). **PARE se:** `text/html`, `text/plain` ou `application/octet-stream` num icone (anote e avise; nada muda no Apache durante o deploy).

```bash
S=$(awk 'tolower($1) == "servername" {print $2; exit}' /etc/apache2/sites-enabled/chronodesk.conf); if [ -n "$S" ]; then for F in favicon.svg favicon.ico apple-touch-icon.png; do printf '%s: ' "$F"; curl -s -o /dev/null -w '%{http_code} %{content_type}\n' -H "Host: $S" "http://127.0.0.1/app/$F?v=3"; done; else echo "PARE: ServerName nao encontrado no chronodesk.conf"; fi; unset S F
```

## 12. Conferencia na tela (como admin, Ctrl+F5 uma vez, sem salvar nada)

Os itens do passo 12 do roteiro (Mapa de PA sem selo e com a legenda nova; o "Inventario do PA" no dialogo com os botoes de salvar desligados; uma pessoa do Critico com papel hoje igual na Escala, com a origem "Papel do Critico", no Calendario e na Visao operacional, 8.8, anotando so "igual" ou "diferente"; em Funcionarios, o aviso "N cadastro(s) ativo(s) sem NC" (anote so o N), o filtro "Sem NC" e o campo NC; nos formularios de hora extra e de correcao de ponto, o NC so leitura com o aviso amarelo e o envio ligado; o icone B na aba e no topo, nos dois temas). **PARE** com tela em branco ou quebrada, ou a mesma pessoa diferente entre as telas.

## 13. Medicao do polling (alguns minutos depois da reabertura; sessao de gestao; `HOST_HEADER` do `ServerName`)

- [ ] **39.** (13) Esperado: duas rodadas do `status.php`, com p50 e p95 (anote a melhor). **PARE se:** p95 acima de 50 ms **e** de 20% acima da base do deploy 4: meca de novo e avise.

```bash
cd /var/www/chronodesk; S=$(awk 'tolower($1) == "servername" {print $2; exit}' /etc/apache2/sites-enabled/chronodesk.conf); if [ -n "$S" ]; then for R in 1 2; do echo "== status.php, rodada $R"; HOST_HEADER="$S" bash scripts/medir-polling.sh; done; else echo "PARE: ServerName nao encontrado no chronodesk.conf"; fi; unset S R
```

- [ ] **40.** (13) Esperado: duas rodadas do `dashboard.php`, com p50 e p95 (anote a melhor). **PARE se:** o mesmo criterio.

```bash
cd /var/www/chronodesk; S=$(awk 'tolower($1) == "servername" {print $2; exit}' /etc/apache2/sites-enabled/chronodesk.conf); if [ -n "$S" ]; then for R in 1 2; do echo "== dashboard.php, rodada $R"; HOST_HEADER="$S" URL=http://127.0.0.1/api/portal/dashboard.php bash scripts/medir-polling.sh; done; else echo "PARE: ServerName nao encontrado no chronodesk.conf"; fi; unset S R
```

- [ ] **41.** (13) Esperado: duas rodadas do `solicitacoes_pendentes.php`, com p50 e p95 (anote a melhor). **PARE se:** o mesmo criterio.

```bash
cd /var/www/chronodesk; S=$(awk 'tolower($1) == "servername" {print $2; exit}' /etc/apache2/sites-enabled/chronodesk.conf); if [ -n "$S" ]; then for R in 1 2; do echo "== solicitacoes_pendentes.php, rodada $R"; HOST_HEADER="$S" URL=http://127.0.0.1/api/solicitacoes_pendentes.php bash scripts/medir-polling.sh; done; else echo "PARE: ServerName nao encontrado no chronodesk.conf"; fi; unset S R
```

## 14. Rollback de codigo (so se preciso, com aprovacao)

- [ ] **42.** (14) Esperado: `codigo anterior no ar: f0844fd`. **PARE se:** erro do checkout.

```bash
cd /var/www/chronodesk; git checkout --detach "$(cat /var/backups/chronodesk/pre-deploy5-commit)" && echo "codigo anterior no ar: $(git rev-parse --short HEAD)"
```

- [ ] **43.** (14) Esperado: `app/ anterior de volta (...)`. **PARE se:** `PARE`.

```bash
cd /var/www/chronodesk; A=/var/backups/chronodesk/app-antes-deploy5; X=/var/backups/chronodesk/app-deploy5-retirado-$(date +%Y%m%d-%H%M%S); if test -d "$A"; then { ! test -e app || mv app "$X"; } && cp -a "$A" app && diff -rq "$A" app && echo "app/ anterior de volta (o build retirado esta em $X)" || echo "PARE: a volta do app/ falhou"; else echo "PARE: copia $A ausente; nada mudou no app/"; fi; unset A X
```

- [ ] **44.** (14) Esperado: recarga sem saida. Depois, os comandos de health, fuso e fumaca (passo 9) e a reabertura (passo 11). **PARE se:** erro da recarga.

```bash
systemctl reload "php$(php -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;')-fpm"
```

## 15. Rollback da 022 (so depois do 14, com aprovacao)

- [ ] **45.** (15) Esperado: `rollback da 022 aplicado`. **PARE se:** `PARE` (o codigo no ar nao e o `f0844fd`: o passo 14 antes; `rollback_022 abortado: ja existe copia *_bkp_022`: confira e renomeie as copias antes) e avise.

```bash
cd /var/www/chronodesk; export D5=$(cat /var/backups/chronodesk/deploy5-commit); read -rsp 'Senha root do MySQL: ' P; echo; export P; [ "$(git rev-parse HEAD)" = "$(cat /var/backups/chronodesk/pre-deploy5-commit)" ] && git cat-file -e "$D5:migrations/rollback/20261119_022_pa_inventory_down.sql" && bash -o pipefail -c 'git show "$D5:migrations/rollback/20261119_022_pa_inventory_down.sql" | docker exec -i -e MYSQL_PWD="$P" Chrono_Desk_DB mysql -uroot sistema_pausas' && echo "rollback da 022 aplicado" || echo "PARE: o codigo no ar nao e o f0844fd ou o rollback da 022 falhou"; unset P D5
```

- [ ] **46.** (15) Esperado: `colunas_022 = 0`, `tabela_022 = 0`, `copias_de_rollback_022 = 2`, "022 ausente" e as assinaturas iguais as do passo 3. **PARE se:** outro numero.

```bash
cd /var/www/chronodesk; export D5=$(cat /var/backups/chronodesk/deploy5-commit); read -rsp 'Senha root do MySQL: ' P; echo; export P; bash -o pipefail -c 'git show "$D5:migrations/verificacao/20261119_022_pa_inventory_check.sql" | docker exec -i -e MYSQL_PWD="$P" Chrono_Desk_DB mysql -uroot --table sistema_pausas'; unset P D5
```

## 16. Rollback da 026 (so depois do 14, com aprovacao)

- [ ] **47.** (16) Esperado: `rollback da 026 aplicado`. **PARE se:** `PARE` (o codigo no ar nao e o `f0844fd`: o passo 14 antes; `rollback_026 abortado: ja existe copia *_bkp_026`: confira e renomeie as copias antes).

```bash
cd /var/www/chronodesk; export D5=$(cat /var/backups/chronodesk/deploy5-commit); read -rsp 'Senha root do MySQL: ' P; echo; export P; [ "$(git rev-parse HEAD)" = "$(cat /var/backups/chronodesk/pre-deploy5-commit)" ] && git cat-file -e "$D5:migrations/rollback/20261123_026_matricula_nc_down.sql" && bash -o pipefail -c 'git show "$D5:migrations/rollback/20261123_026_matricula_nc_down.sql" | docker exec -i -e MYSQL_PWD="$P" Chrono_Desk_DB mysql -uroot sistema_pausas' && echo "rollback da 026 aplicado" || echo "PARE: o codigo no ar nao e o f0844fd ou o rollback da 026 falhou"; unset P D5
```

- [ ] **48.** (16) Esperado: `colunas_026 = 0`, `indice_026 = 0`, `copias_de_rollback_026 = 3`, "026 ausente" e as assinaturas iguais as do passo 3. **PARE se:** outro numero.

```bash
cd /var/www/chronodesk; export D5=$(cat /var/backups/chronodesk/deploy5-commit); read -rsp 'Senha root do MySQL: ' P; echo; export P; bash -o pipefail -c 'git show "$D5:migrations/verificacao/20261123_026_matricula_nc_check.sql" | docker exec -i -e MYSQL_PWD="$P" Chrono_Desk_DB mysql -uroot --table sistema_pausas'; unset P D5
```

## Depois do deploy, com a gestao (pela tela, sem comando)

Os itens do fim do roteiro: marcar os 2 PAs reservados ao Critico (antes, encerrar os vinculos e retirar as preferencias desses PAs), os PAs fixos com o vinculo do dono e o "Empresta", e as preferencias do grupo comum. A verificacao da 022 conta (`reservados_critico`, `fixos`, `fixos_que_emprestam`, `preferencias`) e as contagens de problema ficam em 0. Quem prefere qual PA nunca vai para o git nem para o chat. Preencher o NC dos cadastros ativos (Lideranca ou admin); a verificacao da 026 conta `ativos_sem_nc`. A chave `NC_OBRIGATORIO` so depois, com `ativos_sem_nc = 0`, num passo combinado com o responsavel. Nenhum NC vai para o git, os docs nem o chat.
