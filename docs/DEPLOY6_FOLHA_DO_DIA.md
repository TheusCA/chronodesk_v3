# Deploy 6 (Escala do Critico em grade e feriados, migration 027): folha de execucao do dia

Gerada do roteiro "Deploy 6 (Escala do Critico em grade semanal e feriados; migration 027)" do `DEPLOY_LINUX.md`: os mesmos comandos, na mesma ordem. O roteiro e a referencia: o detalhe de cada esperado esta la. Mudou o roteiro, muda esta folha no mesmo commit. (Ate 2026-11-24 esta folha so apontava para a do deploy 4 consolidado, de quando os deploys 4 a 7 viraram um so; o deploy 6 desta folha e outro, depois do deploy 5.)

**O que vai:** a migration 027 (cor do papel, vagas no rodizio, banco de horas e a tabela de feriados; so a cor padrao de alguns papeis muda), antes do merge; o codigo da Escala do Critico em grade semanal (tela nova, para todos; editar so Lideranca e admin), os feriados no motor e em Configuracoes, a cor e as vagas nos papeis e o banco de horas nas Ausencias. Tudo depois do `babcdd9` (o deploy 5, congelado).

**Data, janela e quem acompanha: A DEFINIR** (depois do deploy 5).

**Shell root, sem `sudo`.** Abra o terminal da VM com `sudo -i` e rode **todos** os comandos desta folha nele, como estao (nenhum leva `sudo`; o que roda como `www-data` usa `runuser`). O comando 1 e a conferencia do ambiente, com o `umask 022`. Terminal reaberto: `sudo -i` e o comando 1 de novo. **Nenhum filtro de saida com `umask`** (o incidente do deploy 4).

**Sem valor real nesta folha**, nem impressa nem anotada nela: nada de senha, IP, hostname, nome ou login. O `ServerName` e lido do `chronodesk.conf` na hora (tambem o `HOST_HEADER` do polling). A senha do root do MySQL e sempre digitada no `read -rsp` (sem eco). Numeros anotados vao para o registro do deploy, nunca para chat ou chamado.

**Regras de parada em toda a folha:** qualquer linha com `PARE`, ou saida diferente do esperado: pare e envie so a contagem ou a mensagem. Nada de `git pull`; o unico `git fetch` e o do comando 3. **A 027 para no primeiro numero fora do esperado** (comandos 20 e 21): fique em manutencao.

## 0. Antes do dia, na estacao

- [ ] **0.** O responsavel publica o commit do deploy 6 em `codex/modernizacao-ui-backend` (fast-forward a partir do `babcdd9`). Depois, a bateria completa sozinha (nada pesado em paralelo): `git fetch origin; git worktree add --detach ..\chronodesk-deploy6 origin/codex/modernizacao-ui-backend; Set-Location ..\chronodesk-deploy6\frontend; npm ci; Set-Location ..; $env:CHROME_PATH='C:\Program Files\Google\Chrome\Application\chrome.exe'; powershell -NoProfile -ExecutionPolicy Bypass -File scripts\test-local.ps1 -SkipHttp -Browser; git rev-parse HEAD`. Esperado: `Validacao local concluida com sucesso`, `frontend: 31 scripts npm rodados (...): 31 OK, 0 com falha`, navegador com `falharam: 0` e o hash completo: anote como `<commit validado na estacao>`. Qualquer falha: nao ha deploy com esse commit.
- [ ] **0b.** Na vespera, o aviso a Lideranca e a gestao do roteiro (passo 0b), com a data e a janela preenchidas; sem nome, hostname nem endereco.

## 1. Conferencia do ambiente (com o umask), producao no babcdd9, commit validado e a lista das migrations

- [ ] **1.** (1) Esperado: antes, `sudo -i`. Nove linhas `OK` (com o `umask 0022`) e `ambiente: OK`. **PARE se:** qualquer `BLOQUEADO` ou `PARE: ambiente com BLOQUEADO`.

```bash
R=OK; [ "$(id -u)" = 0 ] && echo "OK id -u = 0" || { echo "BLOQUEADO id -u (o shell nao e root)"; R=PARE; }; bash -c 'exit 0' && echo "OK bash -c" || { echo "BLOQUEADO bash -c"; R=PARE; }; tar -czf - -C /etc hostname 2>/dev/null | tar -tzf - >/dev/null 2>&1 && echo "OK tar com gzip" || { echo "BLOQUEADO tar com gzip"; R=PARE; }; [ "$(find /etc/hostname -maxdepth 0 -exec echo ok \; 2>/dev/null)" = ok ] && echo "OK find -exec" || { echo "BLOQUEADO find -exec"; R=PARE; }; runuser -u www-data -- php -v >/dev/null 2>&1 && echo "OK runuser -u www-data -- php -v" || { echo "BLOQUEADO runuser -u www-data -- php -v"; R=PARE; }; [ "$(runuser -u www-data -- php -r '$p = proc_open([PHP_BINARY, "-v"], [1 => ["pipe", "w"]], $x); if (!is_resource($p)) { exit(1); } stream_get_contents($x[1]); echo proc_close($p) === 0 ? "ok" : "falha";' 2>/dev/null)" = ok ] && echo "OK php filho como www-data (proc_open, como no qa-security)" || { echo "BLOQUEADO php filho como www-data"; R=PARE; }; npm -v >/dev/null 2>&1 && echo "OK npm -v" || { echo "BLOQUEADO npm -v"; R=PARE; }; git -C /var/www/chronodesk rev-parse --git-dir >/dev/null 2>&1 && echo "OK git no clone" || { echo "BLOQUEADO git no clone"; R=PARE; }; umask 022 && [ "$(umask)" = 0022 ] && echo "OK umask $(umask) (arquivos novos legiveis pelo www-data)" || { echo "BLOQUEADO umask $(umask)"; R=PARE; }; [ "$R" = OK ] && echo "ambiente: OK" || echo "PARE: ambiente com BLOQUEADO"; unset R
```

- [ ] **2.** (1) Esperado: `HEAD de producao: babcdd9 (OK, gravado em pre-deploy6-commit)`, `branch: codex/modernizacao-ui-backend`, `alteracoes locais: 0`. **PARE se:** `PARE: HEAD de producao ...` (o deploy 5 nao terminou com o `babcdd9`: traga o hash curto) ou alteracao local.

```bash
cd /var/www/chronodesk; install -d -o root -g root -m 700 /var/backups/chronodesk; H=$(git rev-parse HEAD); if [ "$H" = "babcdd9bde06a9cc6b7b160139dda4e67240aba4" ]; then echo "$H" | tee /var/backups/chronodesk/pre-deploy6-commit >/dev/null; echo "HEAD de producao: babcdd9 (OK, gravado em pre-deploy6-commit)"; else echo "PARE: HEAD de producao $(git rev-parse --short HEAD), nao o babcdd9"; fi; echo "branch: $(git rev-parse --abbrev-ref HEAD)"; echo "alteracoes locais: $(git status --porcelain | wc -l)"; unset H
```

- [ ] **3.** (1) Esperado: antes, troque `<commit validado na estacao>` (item 0). O hash completo impresso (gravado em `deploy6-commit`). **PARE se:** `PARE: origin/... nao e o commit validado na estacao` ou erro do `fetch`.

```bash
cd /var/www/chronodesk; git fetch origin codex/modernizacao-ui-backend && K=$(git rev-parse origin/codex/modernizacao-ui-backend) && if [ "$K" = "<commit validado na estacao>" ]; then echo "$K" | tee /var/backups/chronodesk/deploy6-commit; else echo "PARE: origin/codex/modernizacao-ui-backend ($K) nao e o commit validado na estacao"; fi; unset K
```

- [ ] **4.** (1) Esperado: `fast-forward OK: N commits a aplicar` (anote o N), `migrations alteradas: exatamente as 4 esperadas (027)` e `lockfile alterado: 0`. **PARE se:** qualquer `PARE` ou lockfile diferente de 0.

```bash
cd /var/www/chronodesk; D6=$(cat /var/backups/chronodesk/deploy6-commit); git cat-file -e "$D6^{commit}" && git cat-file -e "$D6:scripts/smoke-deploy1.sh" && git cat-file -e "$D6:migrations/20261124_027_critical_grid.sql" && git merge-base --is-ancestor HEAD "$D6" && echo "fast-forward OK: $(git rev-list --count HEAD.."$D6") commits a aplicar" || echo "PARE: deploy6-commit ausente, sem o smoke-deploy1.sh, sem a 027, ou o HEAD nao e ancestral dele"; L=$(git diff --name-only HEAD "$D6" -- migrations | sort | tr '\n' ' '); [ "$L" = "migrations/20261124_027_critical_grid.sql migrations/rollback/20261124_027_critical_grid_down.sql migrations/rollback/20261124_027_ensaio_linhas.sql migrations/verificacao/20261124_027_critical_grid_check.sql " ] && echo "migrations alteradas: exatamente as 4 esperadas (027)" || echo "PARE: migrations alteradas diferentes das esperadas: $L"; echo "lockfile alterado: $(git diff --name-only HEAD "$D6" -- frontend/package-lock.json | wc -l)"; unset D6 L
```

## 2. Ensaio do codigo do commit gravado

- [ ] **5.** (2) Esperado: `/tmp/.env ausente: OK`. **PARE se:** `PARE: existe /tmp/.env`.

```bash
[ ! -e /tmp/.env ] && echo "/tmp/.env ausente: OK" || echo "PARE: existe /tmp/.env; o ensaio leria esse arquivo"
```

- [ ] **6.** (2) Esperado: `extraido em /tmp/ensaio-deploy6-qa.<sufixo>`. **PARE se:** `PARE: falha ao extrair`.

```bash
cd /var/www/chronodesk; export D6=$(cat /var/backups/chronodesk/deploy6-commit); Q=$(mktemp -d /tmp/ensaio-deploy6-qa.XXXXXX); export Q; bash -o pipefail -c 'git archive "$D6" | tar -x -C "$Q"' && chown -R www-data: "$Q" && echo "extraido em $Q" || echo "PARE: falha ao extrair o deploy6-commit"
```

- [ ] **7.** (2) Esperado: nenhuma linha antes de `fim do php -l`. **PARE se:** qualquer erro de sintaxe.

```bash
find "$Q" -name '*.php' -not -path '*/node_modules/*' -exec runuser -u www-data -- php -l {} \; | grep -v '^No syntax errors' ; echo "fim do php -l"
```

- [ ] **8.** (2) Esperado: os 25 QAs com `OK` (com o `qa-critical-grid`). **PARE se:** qualquer `FALHOU` (rode o QA de novo sem o `>/dev/null` e envie so o nome e a ultima linha).

```bash
[ ! -e /tmp/.env ] && for q in qa-smoke qa-auth qa-timezone qa-overtime qa-schedule-history qa-schedule-legacy-output qa-presence-engine qa-absences qa-calendar-text qa-archive qa-presence-cutoff qa-pa-conflicts qa-operational-view qa-employee-lunch qa-pa-distribution qa-paridade-escala qa-rotation-teams qa-critico-hibrido qa-critical-roles qa-chg qa-pa-inventory qa-nc qa-critical-grid qa-security qa-deploy-runbook; do printf '%s: ' "$q"; runuser -u www-data -- php "$Q/scripts/$q.php" >/dev/null 2>&1 && echo OK || echo FALHOU; done
```

- [ ] **9.** (2) Esperado: `removido: ...` e `ensaios restantes: 0`. **PARE se:** ensaio restante maior que 0.

```bash
for Q in /tmp/ensaio-deploy6-qa.??????; do [ -d "$Q" ] && rm -r -- "$Q" && echo "removido: $Q"; done; echo "ensaios restantes: $(ls -d /tmp/ensaio-deploy6-qa.?????? 2>/dev/null | wc -l)"; unset Q D6
```

## 3. O "antes" da 027 no banco real

- [ ] **10.** (3) Esperado: `colunas_027 = 0`, `checks_027 = 0`, `tabela_027 = 0`, `enum_027 = 0`, `copias_de_rollback_027 = 0` e "027 ausente" (duas vezes); anote as linhas e as assinaturas dos papeis, dos rodizios e das ausencias. **PARE se:** copia de rollback maior que 0, outro numero ou erro do MySQL.

```bash
cd /var/www/chronodesk; export D6=$(cat /var/backups/chronodesk/deploy6-commit); read -rsp 'Senha root do MySQL: ' P; echo; export P; bash -o pipefail -c 'git show "$D6:migrations/verificacao/20261124_027_critical_grid_check.sql" | docker exec -i -e MYSQL_PWD="$P" Chrono_Desk_DB mysql -uroot --table sistema_pausas' || echo "PARE: a verificacao da 027 falhou"; unset P D6
```

## 4. Ensaio da 027 no MySQL do servidor

- [ ] **11.** (4) Esperado: `extraido em /root/ensaio-deploy6.<sufixo>`. **PARE se:** `PARE: falha ao extrair`.

```bash
cd /var/www/chronodesk; export D6=$(cat /var/backups/chronodesk/deploy6-commit); EV=$(mktemp -d /root/ensaio-deploy6.XXXXXX); export EV; git cat-file -e "$D6^{commit}" && bash -o pipefail -c 'git archive "$D6" migrations scripts/ensaio-migration.sh | tar -x -C "$EV"' && echo "extraido em $EV" || echo "PARE: falha ao extrair as migrations do deploy6-commit"; unset D6
```

- [ ] **12.** (4) Esperado: variante (a) `d6_027`: `migration` 2x `OK`, com `colunas_027 = 1`, `checks_027 = 2`, `tabela_027 = 1`, `enum_027 = 1`, `feriados = 0`, `ausencias_banco_horas = 0` e `rodizios_com_vaga_repetida = 0`; `rollback` 1x `OK` (`copias_de_rollback_027 = 3`, "027 ausente") e a 2a vez para de proposito; assinaturas iguais nos cinco estados; `Removido.` no fim. **PARE se:** `FALHOU` fora do 2o rollback ou assinatura diferente.

```bash
env DADOS=portal_critical_roles,portal_critical_role_rotations,portal_absences VERIFICA="$EV/migrations/verificacao/20261124_027_critical_grid_check.sql" bash "$EV/scripts/ensaio-migration.sh" d6_027 "$EV/migrations/20261124_027_critical_grid.sql" "$EV/migrations/rollback/20261124_027_critical_grid_down.sql" audit_log
```

- [ ] **13.** (4) Esperado: variante (b) `d6_027_rollback` (preparo: a 027): depois das linhas ficticias, `feriados = 1` e `ausencias_banco_horas = 1`; o 1o rollback `OK` (`copias_de_rollback_027 = 3`; so a assinatura das ausencias muda) e o 2o para de proposito; `Removido.` no fim. **PARE se:** `Preparo falhou` ou `FALHOU` fora do 2o rollback.

```bash
env PREPARO="$EV/migrations/20261124_027_critical_grid.sql" DADOS=portal_critical_roles,portal_critical_role_rotations,portal_absences VERIFICA="$EV/migrations/verificacao/20261124_027_critical_grid_check.sql" bash "$EV/scripts/ensaio-migration.sh" d6_027_rollback "$EV/migrations/rollback/20261124_027_ensaio_linhas.sql" "$EV/migrations/rollback/20261124_027_critical_grid_down.sql" audit_log
```

- [ ] **14.** (4) Esperado: `removido: /root/ensaio-deploy6.<sufixo>`. **PARE se:** `PARE: diretorio do ensaio inesperado`.

```bash
case "$EV" in /root/ensaio-deploy6.*) rm -r "$EV" && echo "removido: $EV";; *) echo "PARE: diretorio do ensaio inesperado";; esac; unset EV
```

## 5. Espaco livre e backup rapido

- [ ] **15.** (5) Esperado: seis linhas `OK` e `espaco livre: OK`. **PARE se:** qualquer `BAIXO`.

```bash
cd /var/www/chronodesk/frontend; NPMC=$(npm config get cache); test -e "$NPMC" || NPMC=$(dirname "$NPMC"); DROOT=$(docker info --format '{{.DockerRootDir}}'); ESP=OK; for LIM in "/var/www/chronodesk:500" "$NPMC:200" "/var/backups:100" "$DROOT:200" "/tmp:50" "/root:50"; do DIR=${LIM%:*}; MIN=${LIM##*:}; LIVRE=$(df -BM --output=avail "$DIR" | tail -n 1 | tr -dc '0-9'); if [ "${LIVRE:-0}" -ge "$MIN" ]; then echo "OK $DIR (disco $(df --output=target "$DIR" | tail -n 1)): $LIVRE MB livres, minimo $MIN"; else echo "BAIXO $DIR: ${LIVRE:-?} MB livres, minimo $MIN"; ESP=PARE; fi; done; [ "$ESP" = OK ] && echo "espaco livre: OK" || echo "PARE: espaco livre abaixo do minimo"; unset NPMC DROOT ESP LIM DIR MIN LIVRE
```

- [ ] **16.** (5) Esperado: `backup concluido: /var/backups/chronodesk/deploy6-<data> (<tamanho>)` (anote). **PARE se:** `PARE: backup falhou`.

```bash
B=/var/backups/chronodesk/deploy6-$(date +%Y%m%d-%H%M%S); export B; read -rsp 'Senha root do MySQL: ' P; echo; export P; install -d -o root -g root -m 700 "$B" && bash -o pipefail -c 'docker exec -i -e MYSQL_PWD="$P" Chrono_Desk_DB mysqldump -uroot --single-transaction --routines --triggers --no-tablespaces --set-gtid-purged=OFF sistema_pausas | gzip | tee "$B/database.sql.gz" > /dev/null' && gzip -t "$B/database.sql.gz" && tar -czf "$B/estado.tar.gz" -C /var/www/chronodesk --ignore-failed-read estado.json pausas.csv config_sistema.json funcionarios.json && sh -c 'cd "$1" && sha256sum database.sql.gz estado.tar.gz > SHA256SUMS' _ "$B" && echo "backup concluido: $B ($(du -sh "$B" | cut -f1))" || echo "PARE: backup falhou (sem ponto de restauracao, o deploy nao segue)"; unset P
```

## 6. Manutencao e copia do app/ (o portal responde 503 ate o comando 31)

- [ ] **17.** (6) Esperado: `arquivo de manutencao: presente`. **PARE se:** `AUSENTE`.

```bash
touch /var/www/chronodesk.maintenance; echo "arquivo de manutencao: $(test -e /var/www/chronodesk.maintenance && echo presente || echo AUSENTE)"
```

- [ ] **18.** (6) Esperado: `copia do app/: ...` (anote). **PARE se:** qualquer `PARE`.

```bash
cd /var/www/chronodesk; A=/var/backups/chronodesk/app-antes-deploy6; if test -e "$A"; then diff -rq app "$A" && echo "copia do app/ ja existe e e igual: $A" || echo "PARE: $A existe e e diferente do app/ atual"; else cp -a app "$A" && diff -rq app "$A" && echo "copia do app/: $A ($(du -sh "$A" | cut -f1); $(find "$A" -type f | wc -l) arquivos)" || echo "PARE: copia do app/ falhou"; fi; unset A
```

- [ ] **19.** (6) Esperado: `portal visto de fora (manutencao): 503`. **PARE se:** outro codigo.

```bash
S=$(awk 'tolower($1) == "servername" {print $2; exit}' /etc/apache2/sites-enabled/chronodesk.conf); if [ -n "$S" ]; then curl -s -o /dev/null -w 'portal visto de fora (manutencao): %{http_code}\n' "http://$S/"; else echo "PARE: ServerName nao encontrado no chronodesk.conf"; fi; unset S
```

## 7. A 027, antes do merge

- [ ] **20.** (7) Esperado: `027 aplicada`. **PARE se:** `PARE: a 027 nao foi aplicada` (fique em manutencao; o codigo do `babcdd9` nao le a 027).

```bash
cd /var/www/chronodesk; export D6=$(cat /var/backups/chronodesk/deploy6-commit); read -rsp 'Senha root do MySQL: ' P; echo; export P; git cat-file -e "$D6:migrations/20261124_027_critical_grid.sql" && bash -o pipefail -c 'git show "$D6:migrations/20261124_027_critical_grid.sql" | docker exec -i -e MYSQL_PWD="$P" Chrono_Desk_DB mysql -uroot sistema_pausas' && echo "027 aplicada" || echo "PARE: a 027 nao foi aplicada"; unset P D6
```

- [ ] **21.** (7) Esperado: `colunas_027 = 1`, `checks_027 = 2`, `tabela_027 = 1`, `enum_027 = 1`, `copias_de_rollback_027 = 0`, `papeis_com_cor` (anote), `rodizios_com_vaga_repetida = 0`, `feriados = 0`, `ausencias_banco_horas = 0`, as contagens de problema em 0 e as assinaturas iguais as do passo 3. **PARE se:** outro numero ou assinatura diferente (fique em manutencao).

```bash
cd /var/www/chronodesk; export D6=$(cat /var/backups/chronodesk/deploy6-commit); read -rsp 'Senha root do MySQL: ' P; echo; export P; bash -o pipefail -c 'git show "$D6:migrations/verificacao/20261124_027_critical_grid_check.sql" | docker exec -i -e MYSQL_PWD="$P" Chrono_Desk_DB mysql -uroot --table sistema_pausas'; unset P D6
```

## 8. Merge, build, leitura pelo www-data e QA do bundle

- [ ] **22.** (8) Esperado: o `merge` em fast-forward, `HEAD = deploy6-commit` e `alteracoes locais: 0`. **PARE se:** merge que nao e fast-forward ou `PARE: HEAD diferente`.

```bash
cd /var/www/chronodesk; D6=$(cat /var/backups/chronodesk/deploy6-commit); git merge --ff-only "$D6"; [ "$(git rev-parse HEAD)" = "$D6" ] && echo "HEAD = deploy6-commit" || echo "PARE: HEAD diferente do deploy6-commit"; echo "alteracoes locais: $(git status --porcelain | wc -l)"; unset D6
```

- [ ] **23.** (8) Esperado: `lockfile sem mudanca: so o build` e o build sem erro. **PARE se:** erro no build.

```bash
cd /var/www/chronodesk/frontend && if [ "$(git diff --name-only "$(cat /var/backups/chronodesk/pre-deploy6-commit)" HEAD -- package-lock.json | wc -l)" = 0 ]; then echo "lockfile sem mudanca: so o build"; npm run build; else echo "lockfile alterado: npm ci e build"; npm ci && npm run build; fi
```

- [ ] **24.** (8) Esperado: `sem leitura para o www-data: 0 (arquivos do deploy e app/)`. **PARE se:** qualquer `PARE` (fique em manutencao e avise; nada de `chmod` sem combinar).

```bash
cd /var/www/chronodesk; PRE=$(cat /var/backups/chronodesk/pre-deploy6-commit); if git cat-file -e "$PRE^{commit}" 2>/dev/null; then N=$( { git diff -z --name-only --diff-filter=d "$PRE" HEAD; find app -print0; } | runuser -u www-data -- xargs -0 -r sh -c 'for f; do if [ -d "$f" ]; then { [ -r "$f" ] && [ -x "$f" ]; } || echo x; else [ -r "$f" ] || echo x; fi; done' _ | wc -l); [ "$N" = 0 ] && echo "sem leitura para o www-data: 0 (arquivos do deploy e app/)" || echo "PARE: $N arquivos ou diretorios do deploy ou do app/ sem leitura para o www-data"; else echo "PARE: pre-deploy6-commit ausente"; fi; unset PRE N
```

- [ ] **25.** (8) Esperado: `Bundle frontend QA OK (...)` (os quatro icones, o CSS do portal abaixo de 70000 bytes, o do CHG abaixo de 25000 e o da Escala do Critico abaixo de 15000). **PARE se:** `FALHOU`.

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

- [ ] **29.** (9) Esperado: oito linhas `GET ...: 200` (as mesmas do deploy 5) e `FUMACA OK`. **PARE se:** qualquer `PARE` ou codigo diferente (nao reabra).

```bash
cd /var/www/chronodesk; S=$(awk 'tolower($1) == "servername" {print $2; exit}' /etc/apache2/sites-enabled/chronodesk.conf); if [ -n "$S" ]; then env PHPLOG=/var/log/chronodesk/php_error.log bash scripts/smoke-deploy1.sh "$S"; else echo "PARE: ServerName nao encontrado no chronodesk.conf"; fi; unset S
```

## 10. A verificacao da 027 de novo

- [ ] **30.** (10) Esperado: os mesmos numeros do passo 7 e as assinaturas iguais as do passo 3. **PARE se:** numero diferente.

```bash
cd /var/www/chronodesk; export D6=$(cat /var/backups/chronodesk/deploy6-commit); read -rsp 'Senha root do MySQL: ' P; echo; export P; bash -o pipefail -c 'git show "$D6:migrations/verificacao/20261124_027_critical_grid_check.sql" | docker exec -i -e MYSQL_PWD="$P" Chrono_Desk_DB mysql -uroot --table sistema_pausas' || echo "PARE: a verificacao da 027 falhou"; unset P D6
```

## 11. Reabertura (so com os comandos 26 a 30 como esperado)

- [ ] **31.** (11) Esperado: `manutencao encerrada` e `portal visto de fora: 302`. **PARE se:** `PARE: arquivo de manutencao ainda presente` ou outro codigo.

```bash
[ -e /var/www/chronodesk.maintenance ] && rm -- /var/www/chronodesk.maintenance; [ -e /var/www/chronodesk.maintenance ] && echo "PARE: arquivo de manutencao ainda presente" || echo "manutencao encerrada"; S=$(awk 'tolower($1) == "servername" {print $2; exit}' /etc/apache2/sites-enabled/chronodesk.conf); if [ -n "$S" ]; then curl -s -o /dev/null -w 'portal visto de fora: %{http_code}\n' "http://$S/"; else echo "PARE: ServerName nao encontrado no chronodesk.conf"; fi; unset S
```

## 12. Conferencia na tela (como admin, Ctrl+F5 uma vez, sem salvar nada)

- [ ] Na Escala do Critico (nova), a grade do mes com a semana atual aberta, as cores e o resumo, e o dialogo do dia com o salvar desligado ate o efeito (nao salve; o Excel tem nomes: nao anexe); em "Papeis e rodizio", a cor de cada papel; em Ausencias, "Banco de horas" na lista; em Configuracoes, o bloco "Feriados" vazio; a Visao operacional e a Escala de hoje como antes. **PARE se:** tela em branco ou quebrada, item que nao aparece ou resultado diferente do de antes.

## 13. Medicao do polling (alguns minutos depois da reabertura)

- [ ] **32.** (13) Esperado: p50 e p95 das duas rodadas (anote a melhor). **PARE se:** p95 acima de 50 ms **e** 20% acima da base do deploy 5 (meca de novo e avise).

```bash
cd /var/www/chronodesk; S=$(awk 'tolower($1) == "servername" {print $2; exit}' /etc/apache2/sites-enabled/chronodesk.conf); if [ -n "$S" ]; then for R in 1 2; do echo "== status.php, rodada $R"; HOST_HEADER="$S" bash scripts/medir-polling.sh; done; else echo "PARE: ServerName nao encontrado no chronodesk.conf"; fi; unset S R
```

- [ ] **33.** (13) Esperado: p50 e p95 das duas rodadas (anote a melhor). **PARE se:** p95 acima de 50 ms **e** 20% acima da base do deploy 5 (meca de novo e avise).

```bash
cd /var/www/chronodesk; S=$(awk 'tolower($1) == "servername" {print $2; exit}' /etc/apache2/sites-enabled/chronodesk.conf); if [ -n "$S" ]; then for R in 1 2; do echo "== dashboard.php, rodada $R"; HOST_HEADER="$S" URL=http://127.0.0.1/api/portal/dashboard.php bash scripts/medir-polling.sh; done; else echo "PARE: ServerName nao encontrado no chronodesk.conf"; fi; unset S R
```

- [ ] **34.** (13) Esperado: p50 e p95 das duas rodadas (anote a melhor). **PARE se:** p95 acima de 50 ms **e** 20% acima da base do deploy 5 (meca de novo e avise).

```bash
cd /var/www/chronodesk; S=$(awk 'tolower($1) == "servername" {print $2; exit}' /etc/apache2/sites-enabled/chronodesk.conf); if [ -n "$S" ]; then for R in 1 2; do echo "== solicitacoes_pendentes.php, rodada $R"; HOST_HEADER="$S" URL=http://127.0.0.1/api/solicitacoes_pendentes.php bash scripts/medir-polling.sh; done; else echo "PARE: ServerName nao encontrado no chronodesk.conf"; fi; unset S R
```

## 14. Rollback de codigo (so com aprovacao)

- [ ] **35.** (14) Esperado: `codigo anterior no ar: babcdd9`. **PARE se:** qualquer `PARE` ou erro.

```bash
cd /var/www/chronodesk; git checkout --detach "$(cat /var/backups/chronodesk/pre-deploy6-commit)" && echo "codigo anterior no ar: $(git rev-parse --short HEAD)"
```

- [ ] **36.** (14) Esperado: `app/ anterior de volta (...)`. **PARE se:** qualquer `PARE` ou erro.

```bash
cd /var/www/chronodesk; A=/var/backups/chronodesk/app-antes-deploy6; X=/var/backups/chronodesk/app-deploy6-retirado-$(date +%Y%m%d-%H%M%S); if test -d "$A"; then { ! test -e app || mv app "$X"; } && cp -a "$A" app && diff -rq "$A" app && echo "app/ anterior de volta (o build retirado esta em $X)" || echo "PARE: a volta do app/ falhou"; else echo "PARE: copia $A ausente; nada mudou no app/"; fi; unset A X
```

- [ ] **37.** (14) Esperado: sem saida; depois, os comandos 26 a 29 e a reabertura (31). **PARE se:** qualquer `PARE` ou erro.

```bash
systemctl reload "php$(php -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;')-fpm"
```

## 15. Rollback da 027 (so depois do 14, com aprovacao)

- [ ] **38.** (15) Esperado: `rollback da 027 aplicado`. **PARE se:** `PARE` (o codigo no ar nao e o `babcdd9`: o passo 14 antes; `rollback_027 abortado: ...`: confira e renomeie as copias antes, ou salve o rodizio sem papel repetido).

```bash
cd /var/www/chronodesk; export D6=$(cat /var/backups/chronodesk/deploy6-commit); read -rsp 'Senha root do MySQL: ' P; echo; export P; [ "$(git rev-parse HEAD)" = "$(cat /var/backups/chronodesk/pre-deploy6-commit)" ] && git cat-file -e "$D6:migrations/rollback/20261124_027_critical_grid_down.sql" && bash -o pipefail -c 'git show "$D6:migrations/rollback/20261124_027_critical_grid_down.sql" | docker exec -i -e MYSQL_PWD="$P" Chrono_Desk_DB mysql -uroot sistema_pausas' && echo "rollback da 027 aplicado" || echo "PARE: o codigo no ar nao e o babcdd9 ou o rollback da 027 falhou"; unset P D6
```

- [ ] **39.** (15) Esperado: `colunas_027 = 0`, `tabela_027 = 0`, `enum_027 = 0`, `copias_de_rollback_027 = 3`, "027 ausente" e as assinaturas dos papeis e dos rodizios iguais as do passo 3. **PARE se:** outro numero.

```bash
cd /var/www/chronodesk; export D6=$(cat /var/backups/chronodesk/deploy6-commit); read -rsp 'Senha root do MySQL: ' P; echo; export P; bash -o pipefail -c 'git show "$D6:migrations/verificacao/20261124_027_critical_grid_check.sql" | docker exec -i -e MYSQL_PWD="$P" Chrono_Desk_DB mysql -uroot --table sistema_pausas'; unset P D6
```

## Depois do deploy, com a gestao

- [ ] Lideranca ou admin cadastram os feriados do ano em Configuracoes (a verificacao da 027, comando 30, conta `feriados`), conferem a cor de cada papel em "Papeis e rodizio" e, quando quiserem o mesmo papel em mais de uma vaga, salvam uma versao nova do rodizio. O Excel da escala tem nomes: circula so pelo canal de sempre da Lideranca.
