# Deploy 7 (escala do mes no portal, migration 023): folha de execucao do dia

Gerada do roteiro "Deploy 7 (escala do mes no portal: F10-4, F10-5, F10-6 e a importacao da planilha Base; migration 023)" do `DEPLOY_LINUX.md`: os mesmos comandos, na mesma ordem. O roteiro e a referencia: o detalhe de cada esperado esta la. Mudou o roteiro, muda esta folha no mesmo commit.

**O que vai:** a migration 023 (quatro tabelas novas e vazias da escala do mes; nenhuma linha de outra tabela muda), antes do merge; o codigo da escala do mes (previa, publicar e regerar, leitura com divergencia, travas, paridade so admin), a tela "Escala do mes", a exportacao em Excel com as 6 abas, a importacao da planilha Base (so admin) e a fonte do mes no Mapa PA. Commit do deploy 7: `6c910d4`, depois do `65af3da` (o deploy 6).

**Data, janela e quem acompanha: A DEFINIR** (depois do deploy 6).

**Shell root, sem `sudo`.** Abra o terminal da VM com `sudo -i` e rode **todos** os comandos desta folha nele, como estao (nenhum leva `sudo`; o que roda como `www-data` usa `runuser`). O comando 1 e a conferencia do ambiente, com o `umask 022`. Terminal reaberto: `sudo -i` e o comando 1 de novo. **Nenhum filtro de saida com `umask`** (o incidente do deploy 4).

**Sem valor real nesta folha**, nem impressa nem anotada nela: nada de senha, IP, hostname, nome ou login. O `ServerName` e lido do `chronodesk.conf` na hora (tambem o `HOST_HEADER` do polling). A senha do root do MySQL e sempre digitada no `read -rsp` (sem eco). Numeros anotados vao para o registro do deploy, nunca para chat ou chamado.

**Regras de parada em toda a folha:** qualquer linha com `PARE`, ou saida diferente do esperado: pare e envie so a contagem ou a mensagem. Nada de `git pull`; o unico `git fetch` e o do comando 3. **A 023 para no primeiro numero fora do esperado** (comandos 20 e 21): fique em manutencao.

## 0. Antes do dia, na estacao

- [ ] **0.** O commit do deploy 7 (`6c910d4`) ja esta em `codex/modernizacao-ui-backend`. A bateria completa sozinha (nada pesado em paralelo): `git fetch origin; git worktree add --detach ..\chronodesk-deploy7 6c910d4894353ce01393250c77d4b31f233f05a1; Set-Location ..\chronodesk-deploy7\frontend; npm ci; Set-Location ..; $env:CHROME_PATH='C:\Program Files\Google\Chrome\Application\chrome.exe'; powershell -NoProfile -ExecutionPolicy Bypass -File scripts\test-local.ps1 -SkipHttp -Browser; git rev-parse HEAD`. Esperado: `Validacao local concluida com sucesso`, `frontend: 32 scripts npm rodados (...): 32 OK, 0 com falha`, navegador com `falharam: 0` e o hash completo igual a `6c910d4894353ce01393250c77d4b31f233f05a1`. Qualquer falha ou outro hash: nao ha deploy.
- [ ] **0b.** Na vespera, o aviso a Lideranca e a gestao do roteiro (passo 0b), com a data e a janela preenchidas; sem nome, hostname nem endereco.

## 1. Conferencia do ambiente (com o umask), producao no 65af3da, commit do deploy 7 e a lista das migrations

- [ ] **1.** (1) Esperado: antes, `sudo -i`. Nove linhas `OK` (com o `umask 0022`) e `ambiente: OK`. **PARE se:** qualquer `BLOQUEADO` ou `PARE: ambiente com BLOQUEADO`.

```bash
R=OK; [ "$(id -u)" = 0 ] && echo "OK id -u = 0" || { echo "BLOQUEADO id -u (o shell nao e root)"; R=PARE; }; bash -c 'exit 0' && echo "OK bash -c" || { echo "BLOQUEADO bash -c"; R=PARE; }; tar -czf - -C /etc hostname 2>/dev/null | tar -tzf - >/dev/null 2>&1 && echo "OK tar com gzip" || { echo "BLOQUEADO tar com gzip"; R=PARE; }; [ "$(find /etc/hostname -maxdepth 0 -exec echo ok \; 2>/dev/null)" = ok ] && echo "OK find -exec" || { echo "BLOQUEADO find -exec"; R=PARE; }; runuser -u www-data -- php -v >/dev/null 2>&1 && echo "OK runuser -u www-data -- php -v" || { echo "BLOQUEADO runuser -u www-data -- php -v"; R=PARE; }; [ "$(runuser -u www-data -- php -r '$p = proc_open([PHP_BINARY, "-v"], [1 => ["pipe", "w"]], $x); if (!is_resource($p)) { exit(1); } stream_get_contents($x[1]); echo proc_close($p) === 0 ? "ok" : "falha";' 2>/dev/null)" = ok ] && echo "OK php filho como www-data (proc_open, como no qa-security)" || { echo "BLOQUEADO php filho como www-data"; R=PARE; }; npm -v >/dev/null 2>&1 && echo "OK npm -v" || { echo "BLOQUEADO npm -v"; R=PARE; }; git -C /var/www/chronodesk rev-parse --git-dir >/dev/null 2>&1 && echo "OK git no clone" || { echo "BLOQUEADO git no clone"; R=PARE; }; umask 022 && [ "$(umask)" = 0022 ] && echo "OK umask $(umask) (arquivos novos legiveis pelo www-data)" || { echo "BLOQUEADO umask $(umask)"; R=PARE; }; [ "$R" = OK ] && echo "ambiente: OK" || echo "PARE: ambiente com BLOQUEADO"; unset R
```

- [ ] **2.** (1) Esperado: `HEAD de producao: 65af3da (OK, gravado em pre-deploy7-commit)`, `branch: codex/modernizacao-ui-backend`, `alteracoes locais: 0`. **PARE se:** `PARE: HEAD de producao ...` (o deploy 6 nao terminou com o `65af3da`: traga o hash curto) ou alteracao local.

```bash
cd /var/www/chronodesk; install -d -o root -g root -m 700 /var/backups/chronodesk; H=$(git rev-parse HEAD); if [ "$H" = "65af3dad9a60b6d4d2b22a7009e06b51b30b6569" ]; then echo "$H" | tee /var/backups/chronodesk/pre-deploy7-commit >/dev/null; echo "HEAD de producao: 65af3da (OK, gravado em pre-deploy7-commit)"; else echo "PARE: HEAD de producao $(git rev-parse --short HEAD), nao o 65af3da"; fi; echo "branch: $(git rev-parse --abbrev-ref HEAD)"; echo "alteracoes locais: $(git status --porcelain | wc -l)"; unset H
```

- [ ] **3.** (1) Esperado: o hash completo do `6c910d4` impresso (gravado em `deploy7-commit`); nada a preencher. **PARE se:** `PARE: o 6c910d4 nao esta no origin/...` ou erro do `fetch`.

```bash
cd /var/www/chronodesk; K=6c910d4894353ce01393250c77d4b31f233f05a1; git fetch origin codex/modernizacao-ui-backend && git cat-file -e "$K^{commit}" && git merge-base --is-ancestor "$K" origin/codex/modernizacao-ui-backend && echo "$K" | tee /var/backups/chronodesk/deploy7-commit || echo "PARE: o 6c910d4 nao esta no origin/codex/modernizacao-ui-backend (ou o fetch falhou)"; unset K
```

- [ ] **4.** (1) Esperado: `fast-forward OK: N commits a aplicar` (anote o N), `migrations alteradas: exatamente as 4 esperadas (023)` e `lockfile alterado: 0`. **PARE se:** qualquer `PARE` ou lockfile diferente de 0.

```bash
cd /var/www/chronodesk; D7=$(cat /var/backups/chronodesk/deploy7-commit); git cat-file -e "$D7^{commit}" && git cat-file -e "$D7:scripts/smoke-deploy1.sh" && git cat-file -e "$D7:migrations/20261126_023_monthly_schedule.sql" && git merge-base --is-ancestor HEAD "$D7" && echo "fast-forward OK: $(git rev-list --count HEAD.."$D7") commits a aplicar" || echo "PARE: deploy7-commit ausente, sem o smoke-deploy1.sh, sem a 023, ou o HEAD nao e ancestral dele"; L=$(git diff --name-only HEAD "$D7" -- migrations | sort | tr '\n' ' '); [ "$L" = "migrations/20261126_023_monthly_schedule.sql migrations/rollback/20261126_023_ensaio_linhas.sql migrations/rollback/20261126_023_monthly_schedule_down.sql migrations/verificacao/20261126_023_monthly_schedule_check.sql " ] && echo "migrations alteradas: exatamente as 4 esperadas (023)" || echo "PARE: migrations alteradas diferentes das esperadas: $L"; echo "lockfile alterado: $(git diff --name-only HEAD "$D7" -- frontend/package-lock.json | wc -l)"; unset D7 L
```

## 2. Ensaio do codigo do commit gravado

- [ ] **5.** (2) Esperado: `/tmp/.env ausente: OK`. **PARE se:** `PARE: existe /tmp/.env`.

```bash
[ ! -e /tmp/.env ] && echo "/tmp/.env ausente: OK" || echo "PARE: existe /tmp/.env; o ensaio leria esse arquivo"
```

- [ ] **6.** (2) Esperado: `extraido em /tmp/ensaio-deploy7-qa.<sufixo>`. **PARE se:** `PARE: falha ao extrair`.

```bash
cd /var/www/chronodesk; export D7=$(cat /var/backups/chronodesk/deploy7-commit); Q=$(mktemp -d /tmp/ensaio-deploy7-qa.XXXXXX); export Q; bash -o pipefail -c 'git archive "$D7" | tar -x -C "$Q"' && chown -R www-data: "$Q" && echo "extraido em $Q" || echo "PARE: falha ao extrair o deploy7-commit"
```

- [ ] **7.** (2) Esperado: nenhuma linha antes de `fim do php -l`. **PARE se:** qualquer erro de sintaxe.

```bash
find "$Q" -name '*.php' -not -path '*/node_modules/*' -exec runuser -u www-data -- php -l {} \; | grep -v '^No syntax errors' ; echo "fim do php -l"
```

- [ ] **8.** (2) Esperado: os 26 QAs com `OK` (com o `qa-monthly-schedule`). **PARE se:** qualquer `FALHOU` (rode o QA de novo sem o `>/dev/null` e envie so o nome e a ultima linha).

```bash
[ ! -e /tmp/.env ] && for q in qa-smoke qa-auth qa-timezone qa-overtime qa-schedule-history qa-schedule-legacy-output qa-presence-engine qa-absences qa-calendar-text qa-archive qa-presence-cutoff qa-pa-conflicts qa-operational-view qa-employee-lunch qa-pa-distribution qa-paridade-escala qa-rotation-teams qa-critico-hibrido qa-critical-roles qa-chg qa-pa-inventory qa-nc qa-critical-grid qa-monthly-schedule qa-security qa-deploy-runbook; do printf '%s: ' "$q"; runuser -u www-data -- php "$Q/scripts/$q.php" >/dev/null 2>&1 && echo OK || echo FALHOU; done
```

- [ ] **9.** (2) Esperado: `removido: ...` e `ensaios restantes: 0`. **PARE se:** ensaio restante maior que 0.

```bash
for Q in /tmp/ensaio-deploy7-qa.??????; do [ -d "$Q" ] && rm -r -- "$Q" && echo "removido: $Q"; done; echo "ensaios restantes: $(ls -d /tmp/ensaio-deploy7-qa.?????? 2>/dev/null | wc -l)"; unset Q D7
```

## 3. O "antes" da 023 no banco real

- [ ] **10.** (3) Esperado: `tabelas_023 = 0`, `fks_023 = 0`, `checks_023 = 0`, `copias_de_rollback_023 = 0` e "023 ausente" (duas vezes); anote as linhas e as assinaturas dos cadastros e do inventario. **PARE se:** copia de rollback maior que 0, outro numero ou erro do MySQL.

```bash
cd /var/www/chronodesk; export D7=$(cat /var/backups/chronodesk/deploy7-commit); read -rsp 'Senha root do MySQL: ' P; echo; export P; bash -o pipefail -c 'git show "$D7:migrations/verificacao/20261126_023_monthly_schedule_check.sql" | docker exec -i -e MYSQL_PWD="$P" Chrono_Desk_DB mysql -uroot --table sistema_pausas' || echo "PARE: a verificacao da 023 falhou"; unset P D7
```

## 4. Ensaio da 023 no MySQL do servidor

- [ ] **11.** (4) Esperado: `extraido em /root/ensaio-deploy7.<sufixo>`. **PARE se:** `PARE: falha ao extrair`.

```bash
cd /var/www/chronodesk; export D7=$(cat /var/backups/chronodesk/deploy7-commit); EV=$(mktemp -d /root/ensaio-deploy7.XXXXXX); export EV; git cat-file -e "$D7^{commit}" && bash -o pipefail -c 'git archive "$D7" migrations scripts/ensaio-migration.sh | tar -x -C "$EV"' && echo "extraido em $EV" || echo "PARE: falha ao extrair as migrations do deploy7-commit"; unset D7
```

- [ ] **12.** (4) Esperado: variante (a) `d7_023`: `migration` 2x `OK`, com `tabelas_023 = 4`, `fks_023 = 4`, `checks_023 = 7`, meses, revisoes, linhas e travas em 0 e os problemas em 0; `rollback` 1a vez `OK` (`copias_de_rollback_023 = 4`, "023 ausente"); a 2a para de proposito; assinaturas iguais nos cinco estados. **PARE se:** `FALHOU` em etapa que nao seja o 2o rollback ou assinatura diferente.

```bash
env DADOS=funcionarios,portal_pa_inventory VERIFICA="$EV/migrations/verificacao/20261126_023_monthly_schedule_check.sql" bash "$EV/scripts/ensaio-migration.sh" d7_023 "$EV/migrations/20261126_023_monthly_schedule.sql" "$EV/migrations/rollback/20261126_023_monthly_schedule_down.sql" audit_log
```

- [ ] **13.** (4) Esperado: variante (b) `d7_023_rollback` (preparo: a 023): depois das linhas ficticias, `meses = 1`, `revisoes = 1`, `linhas = 1`, `travas = 1` e os problemas em 0; o 1o rollback `OK` (4 copias) e o 2o para de proposito. **PARE se:** `Preparo falhou`, `FALHOU` em etapa que nao seja o 2o rollback.

```bash
env PREPARO="$EV/migrations/20261126_023_monthly_schedule.sql" DADOS=funcionarios,portal_pa_inventory VERIFICA="$EV/migrations/verificacao/20261126_023_monthly_schedule_check.sql" bash "$EV/scripts/ensaio-migration.sh" d7_023_rollback "$EV/migrations/rollback/20261126_023_ensaio_linhas.sql" "$EV/migrations/rollback/20261126_023_monthly_schedule_down.sql" audit_log
```

- [ ] **14.** (4) Esperado: `removido: /root/ensaio-deploy7.<sufixo>`. **PARE se:** `PARE: diretorio do ensaio inesperado`.

```bash
case "$EV" in /root/ensaio-deploy7.*) rm -r "$EV" && echo "removido: $EV";; *) echo "PARE: diretorio do ensaio inesperado";; esac; unset EV
```

## 5. Espaco livre e backup rapido

- [ ] **15.** (5) Esperado: seis linhas `OK` e `espaco livre: OK`. **PARE se:** qualquer `BAIXO`.

```bash
cd /var/www/chronodesk/frontend; NPMC=$(npm config get cache); test -e "$NPMC" || NPMC=$(dirname "$NPMC"); DROOT=$(docker info --format '{{.DockerRootDir}}'); ESP=OK; for LIM in "/var/www/chronodesk:500" "$NPMC:200" "/var/backups:100" "$DROOT:200" "/tmp:50" "/root:50"; do DIR=${LIM%:*}; MIN=${LIM##*:}; LIVRE=$(df -BM --output=avail "$DIR" | tail -n 1 | tr -dc '0-9'); if [ "${LIVRE:-0}" -ge "$MIN" ]; then echo "OK $DIR (disco $(df --output=target "$DIR" | tail -n 1)): $LIVRE MB livres, minimo $MIN"; else echo "BAIXO $DIR: ${LIVRE:-?} MB livres, minimo $MIN"; ESP=PARE; fi; done; [ "$ESP" = OK ] && echo "espaco livre: OK" || echo "PARE: espaco livre abaixo do minimo"; unset NPMC DROOT ESP LIM DIR MIN LIVRE
```

- [ ] **16.** (5) Esperado: `backup concluido: /var/backups/chronodesk/deploy7-<data> (<tamanho>)` (anote). **PARE se:** `PARE: backup falhou`.

```bash
B=/var/backups/chronodesk/deploy7-$(date +%Y%m%d-%H%M%S); export B; read -rsp 'Senha root do MySQL: ' P; echo; export P; install -d -o root -g root -m 700 "$B" && bash -o pipefail -c 'docker exec -i -e MYSQL_PWD="$P" Chrono_Desk_DB mysqldump -uroot --single-transaction --routines --triggers --no-tablespaces --set-gtid-purged=OFF sistema_pausas | gzip | tee "$B/database.sql.gz" > /dev/null' && gzip -t "$B/database.sql.gz" && tar -czf "$B/estado.tar.gz" -C /var/www/chronodesk --ignore-failed-read estado.json pausas.csv config_sistema.json funcionarios.json && sh -c 'cd "$1" && sha256sum database.sql.gz estado.tar.gz > SHA256SUMS' _ "$B" && echo "backup concluido: $B ($(du -sh "$B" | cut -f1))" || echo "PARE: backup falhou (sem ponto de restauracao, o deploy nao segue)"; unset P
```

## 6. Manutencao e copia do app/ (o portal responde 503 ate o comando 31)

- [ ] **17.** (6) Esperado: `arquivo de manutencao: presente`. **PARE se:** `AUSENTE`.

```bash
touch /var/www/chronodesk.maintenance; echo "arquivo de manutencao: $(test -e /var/www/chronodesk.maintenance && echo presente || echo AUSENTE)"
```

- [ ] **18.** (6) Esperado: `copia do app/: ...` (anote). **PARE se:** qualquer `PARE`.

```bash
cd /var/www/chronodesk; A=/var/backups/chronodesk/app-antes-deploy7; if test -e "$A"; then diff -rq app "$A" && echo "copia do app/ ja existe e e igual: $A" || echo "PARE: $A existe e e diferente do app/ atual"; else cp -a app "$A" && diff -rq app "$A" && echo "copia do app/: $A ($(du -sh "$A" | cut -f1); $(find "$A" -type f | wc -l) arquivos)" || echo "PARE: copia do app/ falhou"; fi; unset A
```

- [ ] **19.** (6) Esperado: `portal visto de fora (manutencao): 503`. **PARE se:** outro codigo.

```bash
S=$(awk 'tolower($1) == "servername" {print $2; exit}' /etc/apache2/sites-enabled/chronodesk.conf); if [ -n "$S" ]; then curl -s -o /dev/null -w 'portal visto de fora (manutencao): %{http_code}\n' "http://$S/"; else echo "PARE: ServerName nao encontrado no chronodesk.conf"; fi; unset S
```

## 7. A 023, antes do merge

- [ ] **20.** (7) Esperado: `023 aplicada`. **PARE se:** `PARE: a 023 nao foi aplicada` (fique em manutencao; o codigo do `65af3da` nao le a 023).

```bash
cd /var/www/chronodesk; export D7=$(cat /var/backups/chronodesk/deploy7-commit); read -rsp 'Senha root do MySQL: ' P; echo; export P; git cat-file -e "$D7:migrations/20261126_023_monthly_schedule.sql" && bash -o pipefail -c 'git show "$D7:migrations/20261126_023_monthly_schedule.sql" | docker exec -i -e MYSQL_PWD="$P" Chrono_Desk_DB mysql -uroot sistema_pausas' && echo "023 aplicada" || echo "PARE: a 023 nao foi aplicada"; unset P D7
```

- [ ] **21.** (7) Esperado: `tabelas_023 = 4`, `fks_023 = 4`, `checks_023 = 7`, `copias_de_rollback_023 = 0`, meses, revisoes, linhas e travas em 0, os problemas em 0 e as assinaturas iguais as do comando 10. **PARE se:** numero fora do esperado (fique em manutencao e avise).

```bash
cd /var/www/chronodesk; export D7=$(cat /var/backups/chronodesk/deploy7-commit); read -rsp 'Senha root do MySQL: ' P; echo; export P; bash -o pipefail -c 'git show "$D7:migrations/verificacao/20261126_023_monthly_schedule_check.sql" | docker exec -i -e MYSQL_PWD="$P" Chrono_Desk_DB mysql -uroot --table sistema_pausas'; unset P D7
```

## 8. Merge, build, leitura pelo www-data e QA do bundle

- [ ] **22.** (8) Esperado: o `merge` em fast-forward, `HEAD = deploy7-commit` e `alteracoes locais: 0`. **PARE se:** merge que nao e fast-forward ou outro HEAD.

```bash
cd /var/www/chronodesk; D7=$(cat /var/backups/chronodesk/deploy7-commit); git merge --ff-only "$D7"; [ "$(git rev-parse HEAD)" = "$D7" ] && echo "HEAD = deploy7-commit" || echo "PARE: HEAD diferente do deploy7-commit"; echo "alteracoes locais: $(git status --porcelain | wc -l)"; unset D7
```

- [ ] **23.** (8) Esperado: `lockfile sem mudanca: so o build` e o build sem erro. **PARE se:** erro no build.

```bash
cd /var/www/chronodesk/frontend && if [ "$(git diff --name-only "$(cat /var/backups/chronodesk/pre-deploy7-commit)" HEAD -- package-lock.json | wc -l)" = 0 ]; then echo "lockfile sem mudanca: so o build"; npm run build; else echo "lockfile alterado: npm ci e build"; npm ci && npm run build; fi
```

- [ ] **24.** (8) Esperado: `sem leitura para o www-data: 0 (arquivos do deploy e app/)`. **PARE se:** qualquer `PARE` (fique em manutencao e avise; nada de `chmod` sem combinar).

```bash
cd /var/www/chronodesk; PRE=$(cat /var/backups/chronodesk/pre-deploy7-commit); if git cat-file -e "$PRE^{commit}" 2>/dev/null; then N=$( { git diff -z --name-only --diff-filter=d "$PRE" HEAD; find app -print0; } | runuser -u www-data -- xargs -0 -r sh -c 'for f; do if [ -d "$f" ]; then { [ -r "$f" ] && [ -x "$f" ]; } || echo x; else [ -r "$f" ] || echo x; fi; done' _ | wc -l); [ "$N" = 0 ] && echo "sem leitura para o www-data: 0 (arquivos do deploy e app/)" || echo "PARE: $N arquivos ou diretorios do deploy ou do app/ sem leitura para o www-data"; else echo "PARE: pre-deploy7-commit ausente"; fi; unset PRE N
```

- [ ] **25.** (8) Esperado: `Bundle frontend QA OK (...)` (os quatro icones, o CSS do portal abaixo de 70000 bytes, o do CHG abaixo de 25000 e o da Escala do Critico, que a Escala do mes reaproveita, abaixo de 15000). **PARE se:** qualquer falha.

```bash
cd /var/www/chronodesk/frontend && npm run -s qa:bundle
```

## 9. Recarga, health, fuso e teste de fumaca

- [ ] **26.** (9) Esperado: sem saida. **PARE se:** erro do `systemctl`.

```bash
systemctl reload "php$(php -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;')-fpm"
```

- [ ] **27.** (9) Esperado: `health: 200`. **PARE se:** outro codigo.

```bash
S=$(awk 'tolower($1) == "servername" {print $2; exit}' /etc/apache2/sites-enabled/chronodesk.conf); if [ -n "$S" ]; then curl -s -o /dev/null -w 'health: %{http_code}\n' -H "Host: $S" http://127.0.0.1/api/health.php; else echo "PARE: ServerName nao encontrado no chronodesk.conf"; fi; unset S
```

- [ ] **28.** (9) Esperado: `QA timezone (banco real) OK`. **PARE se:** `FALHOU`.

```bash
cd /var/www/chronodesk; runuser -u www-data -- php scripts/qa-timezone.php --db
```

- [ ] **29.** (9) Esperado: oito linhas `GET ...: 200` (as mesmas do deploy 6) e `FUMACA OK`. **PARE se:** qualquer `PARE` ou codigo diferente (nao reabra).

```bash
cd /var/www/chronodesk; S=$(awk 'tolower($1) == "servername" {print $2; exit}' /etc/apache2/sites-enabled/chronodesk.conf); if [ -n "$S" ]; then env PHPLOG=/var/log/chronodesk/php_error.log bash scripts/smoke-deploy1.sh "$S"; else echo "PARE: ServerName nao encontrado no chronodesk.conf"; fi; unset S
```

## 10. A verificacao da 023 de novo

- [ ] **30.** (10) Esperado: os mesmos numeros do comando 21 e as assinaturas iguais as do comando 10. **PARE se:** numero diferente.

```bash
cd /var/www/chronodesk; export D7=$(cat /var/backups/chronodesk/deploy7-commit); read -rsp 'Senha root do MySQL: ' P; echo; export P; bash -o pipefail -c 'git show "$D7:migrations/verificacao/20261126_023_monthly_schedule_check.sql" | docker exec -i -e MYSQL_PWD="$P" Chrono_Desk_DB mysql -uroot --table sistema_pausas' || echo "PARE: a verificacao da 023 falhou"; unset P D7
```

## 11. Reabertura (so com os comandos 26 a 30 como esperado)

- [ ] **31.** (11) Esperado: `manutencao encerrada` e `portal visto de fora: 302`. **PARE se:** `PARE: arquivo de manutencao ainda presente` ou outro codigo.

```bash
[ -e /var/www/chronodesk.maintenance ] && rm -- /var/www/chronodesk.maintenance; [ -e /var/www/chronodesk.maintenance ] && echo "PARE: arquivo de manutencao ainda presente" || echo "manutencao encerrada"; S=$(awk 'tolower($1) == "servername" {print $2; exit}' /etc/apache2/sites-enabled/chronodesk.conf); if [ -n "$S" ]; then curl -s -o /dev/null -w 'portal visto de fora: %{http_code}\n' "http://$S/"; else echo "PARE: ServerName nao encontrado no chronodesk.conf"; fi; unset S
```

## 12. Conferencia na tela (como admin, Ctrl+F5 uma vez, sem salvar nada)

Escala do mes: dezembro "Ainda nao publicada" (nao gere, nao publique e nao importe agora); Mapa PA sem a linha "Escala do mes"; Escala do Critico, Escala presencial e Visao operacional como antes. So "igual" ou "diferente", sem nome.

## 13. Medicao do polling (alguns minutos depois da reabertura)

- [ ] **32.** (13) Esperado: p50 e p95 das duas rodadas (anote a melhor). **PARE se:** p95 acima de 50 ms **e** 20% acima da base do deploy 6 (meca de novo e avise).

```bash
cd /var/www/chronodesk; S=$(awk 'tolower($1) == "servername" {print $2; exit}' /etc/apache2/sites-enabled/chronodesk.conf); if [ -n "$S" ]; then for R in 1 2; do echo "== status.php, rodada $R"; HOST_HEADER="$S" bash scripts/medir-polling.sh; done; else echo "PARE: ServerName nao encontrado no chronodesk.conf"; fi; unset S R
```

- [ ] **33.** (13) Esperado: p50 e p95 das duas rodadas (anote a melhor). **PARE se:** p95 acima de 50 ms **e** 20% acima da base do deploy 6 (meca de novo e avise).

```bash
cd /var/www/chronodesk; S=$(awk 'tolower($1) == "servername" {print $2; exit}' /etc/apache2/sites-enabled/chronodesk.conf); if [ -n "$S" ]; then for R in 1 2; do echo "== dashboard.php, rodada $R"; HOST_HEADER="$S" URL=http://127.0.0.1/api/portal/dashboard.php bash scripts/medir-polling.sh; done; else echo "PARE: ServerName nao encontrado no chronodesk.conf"; fi; unset S R
```

- [ ] **34.** (13) Esperado: p50 e p95 das duas rodadas (anote a melhor). **PARE se:** p95 acima de 50 ms **e** 20% acima da base do deploy 6 (meca de novo e avise).

```bash
cd /var/www/chronodesk; S=$(awk 'tolower($1) == "servername" {print $2; exit}' /etc/apache2/sites-enabled/chronodesk.conf); if [ -n "$S" ]; then for R in 1 2; do echo "== solicitacoes_pendentes.php, rodada $R"; HOST_HEADER="$S" URL=http://127.0.0.1/api/solicitacoes_pendentes.php bash scripts/medir-polling.sh; done; else echo "PARE: ServerName nao encontrado no chronodesk.conf"; fi; unset S R
```

## 14. Rollback de codigo (so com aprovacao)

- [ ] **35.** (14) Esperado: `codigo anterior no ar: 65af3da`. **PARE se:** qualquer `PARE` ou erro.

```bash
cd /var/www/chronodesk; git checkout --detach "$(cat /var/backups/chronodesk/pre-deploy7-commit)" && echo "codigo anterior no ar: $(git rev-parse --short HEAD)"
```

- [ ] **36.** (14) Esperado: `app/ anterior de volta (...)`. **PARE se:** qualquer `PARE` ou erro.

```bash
cd /var/www/chronodesk; A=/var/backups/chronodesk/app-antes-deploy7; X=/var/backups/chronodesk/app-deploy7-retirado-$(date +%Y%m%d-%H%M%S); if test -d "$A"; then { ! test -e app || mv app "$X"; } && cp -a "$A" app && diff -rq "$A" app && echo "app/ anterior de volta (o build retirado esta em $X)" || echo "PARE: a volta do app/ falhou"; else echo "PARE: copia $A ausente; nada mudou no app/"; fi; unset A X
```

- [ ] **37.** (14) Esperado: sem saida; depois, os comandos 26 a 29 e a reabertura (31). **PARE se:** qualquer `PARE` ou erro.

```bash
systemctl reload "php$(php -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;')-fpm"
```

## 15. Rollback da 023 (so depois do 14, com aprovacao)

- [ ] **38.** (15) Esperado: `rollback da 023 aplicado`. **PARE se:** `PARE` (o codigo no ar nao e o `65af3da`: o passo 14 antes; `rollback_023 abortado`: confira e renomeie as copias).

```bash
cd /var/www/chronodesk; export D7=$(cat /var/backups/chronodesk/deploy7-commit); read -rsp 'Senha root do MySQL: ' P; echo; export P; [ "$(git rev-parse HEAD)" = "$(cat /var/backups/chronodesk/pre-deploy7-commit)" ] && git cat-file -e "$D7:migrations/rollback/20261126_023_monthly_schedule_down.sql" && bash -o pipefail -c 'git show "$D7:migrations/rollback/20261126_023_monthly_schedule_down.sql" | docker exec -i -e MYSQL_PWD="$P" Chrono_Desk_DB mysql -uroot sistema_pausas' && echo "rollback da 023 aplicado" || echo "PARE: o codigo no ar nao e o 65af3da ou o rollback da 023 falhou"; unset P D7
```

- [ ] **39.** (15) Esperado: `tabelas_023 = 0`, `fks_023 = 0`, `checks_023 = 0`, `copias_de_rollback_023 = 4`, "023 ausente" e as assinaturas iguais as do comando 10. **PARE se:** outro numero.

```bash
cd /var/www/chronodesk; export D7=$(cat /var/backups/chronodesk/deploy7-commit); read -rsp 'Senha root do MySQL: ' P; echo; export P; bash -o pipefail -c 'git show "$D7:migrations/verificacao/20261126_023_monthly_schedule_check.sql" | docker exec -i -e MYSQL_PWD="$P" Chrono_Desk_DB mysql -uroot --table sistema_pausas'; unset P D7
```

## Depois do deploy: o mes em paralelo e a virada (pela tela, sem comando)

- [ ] O responsavel preenche o `employee_id` do `_referencias/casamento-nome-id.csv` (na estacao, fora do git).
- [ ] Admin: "Importar planilha Base", previa, conferir conflitos e nao casados; confirmar **so** times, regras, excecoes e inventario (a virada fica para janeiro).
- [ ] Dezembro em paralelo, **sem publicar**: "Gerar previa" e o JSON da paridade (admin) salvo em `_referencias/`; a paridade na estacao, so contagens (status 100% igual; PA conforme a 4.4). A planilha continua a oficial.
- [ ] Janeiro, so se dezembro bater e com aprovacao: publicar janeiro, confirmar a virada e aposentar a planilha. A verificacao da 023 (a do comando 30, pelo responsavel) conta `meses_publicados` (anote).
