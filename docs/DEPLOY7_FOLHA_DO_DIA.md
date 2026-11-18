# Deploy 7: folha de execucao do dia

Gerada do roteiro "Deploy 7 (Controle de CHG e grupo Tools; migration 025)" do `DEPLOY_LINUX.md`: os mesmos comandos, na mesma ordem. O roteiro e a referencia: o detalhe de cada esperado esta la. Mudou o roteiro, muda esta folha no mesmo commit.

**O que vai:** a migration 025 (dez tabelas novas e vazias: o grupo por funcionario, os catalogos de carteiras e locais, os cards do Controle de CHG e as partes deles; nenhuma linha existente muda) e o codigo do Controle de CHG (quadro, lista, dashboard, o card por etapa como no v5.2, o Excel do servidor e a importacao do Controle v5) e do grupo Tools no cadastro de funcionarios. O modulo entra desligado e so e ligado no comando 32, depois das conferencias. O lockfile nao mudou desde o commit do deploy 6: so o build. **Pre-requisito:** o deploy 6 executado com o commit dele (o hash completo do registro do deploy 6).

**Data, janela e quem acompanha: A DEFINIR.**

**Shell root, sem `sudo`.** Abra o terminal da VM com `sudo -i` e rode **todos** os comandos desta folha nele, como estao (nenhum leva `sudo`; o que roda como `www-data` usa `runuser`). O comando 1 e a conferencia do ambiente. Terminal reaberto: `sudo -i` e o comando 1 de novo.

**Sem valor real nesta folha**, nem impressa nem anotada nela: nada de senha, IP, hostname, nome ou login. O `ServerName` e lido do `chronodesk.conf` na hora. A senha do root do MySQL e sempre digitada no `read -rsp` (sem eco). Numeros anotados vao para o registro do deploy, nunca para chat ou chamado; da importacao, so as contagens.

**Regras de parada em toda a folha:** qualquer linha com `PARE`, ou saida diferente do esperado: pare e envie so a contagem ou a mensagem. Nada de `git pull`; o unico `git fetch` e o do comando 3.

## 0. Antes do dia, na estacao

- [ ] **0.** O responsavel publica o `feat/employee-form-rhf-zod`. Depois, a bateria completa sozinha (nada pesado em paralelo): `git fetch origin; git worktree add --detach ..\chronodesk-deploy7 origin/feat/employee-form-rhf-zod; Set-Location ..\chronodesk-deploy7\frontend; npm ci; Set-Location ..; $env:CHROME_PATH='C:\Program Files\Google\Chrome\Application\chrome.exe'; powershell -NoProfile -ExecutionPolicy Bypass -File scripts\test-local.ps1 -SkipHttp -Browser; git rev-parse HEAD`. Esperado: `Validacao local concluida com sucesso`, `frontend: 30 scripts npm rodados (...): 30 OK, 0 com falha`, navegador com `falharam: 0` e o hash completo: anote como `<commit validado na estacao>`. Qualquer falha: nao ha deploy com esse commit.
- [ ] **0b.** Na vespera, o aviso a Lideranca e a gestao do roteiro (passo 0b), com a data e a janela preenchidas; sem nome, hostname nem endereco.

## 1. Conferencia do ambiente, commit validado e producao no commit do deploy 6

- [ ] **1.** (1) Esperado: antes, `sudo -i`. Oito linhas `OK` e `ambiente: OK`. **PARE se:** qualquer `BLOQUEADO` ou `PARE: ambiente com BLOQUEADO`.

```bash
R=OK; [ "$(id -u)" = 0 ] && echo "OK id -u = 0" || { echo "BLOQUEADO id -u (o shell nao e root)"; R=PARE; }; bash -c 'exit 0' && echo "OK bash -c" || { echo "BLOQUEADO bash -c"; R=PARE; }; tar -czf - -C /etc hostname 2>/dev/null | tar -tzf - >/dev/null 2>&1 && echo "OK tar com gzip" || { echo "BLOQUEADO tar com gzip"; R=PARE; }; [ "$(find /etc/hostname -maxdepth 0 -exec echo ok \; 2>/dev/null)" = ok ] && echo "OK find -exec" || { echo "BLOQUEADO find -exec"; R=PARE; }; runuser -u www-data -- php -v >/dev/null 2>&1 && echo "OK runuser -u www-data -- php -v" || { echo "BLOQUEADO runuser -u www-data -- php -v"; R=PARE; }; [ "$(runuser -u www-data -- php -r '$p = proc_open([PHP_BINARY, "-v"], [1 => ["pipe", "w"]], $x); if (!is_resource($p)) { exit(1); } stream_get_contents($x[1]); echo proc_close($p) === 0 ? "ok" : "falha";' 2>/dev/null)" = ok ] && echo "OK php filho como www-data (proc_open, como no qa-security)" || { echo "BLOQUEADO php filho como www-data"; R=PARE; }; npm -v >/dev/null 2>&1 && echo "OK npm -v" || { echo "BLOQUEADO npm -v"; R=PARE; }; git -C /var/www/chronodesk rev-parse --git-dir >/dev/null 2>&1 && echo "OK git no clone" || { echo "BLOQUEADO git no clone"; R=PARE; }; [ "$R" = OK ] && echo "ambiente: OK" || echo "PARE: ambiente com BLOQUEADO"; unset R
```

- [ ] **2.** (1) Esperado: antes, troque `<commit do deploy 6>` pelo hash completo do registro do deploy 6. `HEAD de producao: o commit do deploy 6 (OK, gravado em pre-deploy7-commit)`, `branch: codex/modernizacao-ui-backend`, `alteracoes locais: 0`. **PARE se:** `PARE: HEAD de producao ...` (o deploy 6 nao terminou com o commit dele: traga o hash curto) ou alteracao local.

```bash
cd /var/www/chronodesk; install -d -o root -g root -m 700 /var/backups/chronodesk; H=$(git rev-parse HEAD); if [ "$H" = "<commit do deploy 6>" ]; then echo "$H" | tee /var/backups/chronodesk/pre-deploy7-commit >/dev/null; echo "HEAD de producao: o commit do deploy 6 (OK, gravado em pre-deploy7-commit)"; else echo "PARE: HEAD de producao $(git rev-parse --short HEAD), nao o commit do deploy 6"; fi; echo "branch: $(git rev-parse --abbrev-ref HEAD)"; echo "alteracoes locais: $(git status --porcelain | wc -l)"; unset H
```

- [ ] **3.** (1) Esperado: antes, troque `<commit validado na estacao>` (item 0). O hash completo impresso (gravado em `deploy7-commit`). **PARE se:** `PARE: origin/... nao e o commit validado na estacao` ou erro do `fetch`.

```bash
cd /var/www/chronodesk; git fetch origin feat/employee-form-rhf-zod && K=$(git rev-parse origin/feat/employee-form-rhf-zod) && if [ "$K" = "<commit validado na estacao>" ]; then echo "$K" | tee /var/backups/chronodesk/deploy7-commit; else echo "PARE: origin/feat/employee-form-rhf-zod ($K) nao e o commit validado na estacao"; fi; unset K
```

- [ ] **4.** (1) Esperado: `fast-forward OK: <N> commits a aplicar` (anote), `migrations alteradas:` com exatamente os quatro arquivos da 025 e `lockfile alterado: 0` (com `1`, anote: o comando 23 faz o `npm ci`). **PARE se:** linha `PARE` ou outra migration na lista.

```bash
cd /var/www/chronodesk; D7=$(cat /var/backups/chronodesk/deploy7-commit); git cat-file -e "$D7^{commit}" && git cat-file -e "$D7:scripts/smoke-deploy1.sh" && git cat-file -e "$D7:migrations/20261117_025_chg.sql" && git merge-base --is-ancestor HEAD "$D7" && echo "fast-forward OK: $(git rev-list --count HEAD.."$D7") commits a aplicar" || echo "PARE: deploy7-commit ausente, sem o smoke-deploy1.sh, sem a 025 ou o HEAD nao e ancestral dele"; echo "migrations alteradas: $(git diff --name-only HEAD "$D7" -- migrations | sort | tr '\n' ' ')"; echo "lockfile alterado: $(git diff --name-only HEAD "$D7" -- frontend/package-lock.json | wc -l)"; unset D7
```

## 2. Ensaio do codigo do commit gravado (em `/tmp`, como `www-data`; nenhum QA toca o banco real)

- [ ] **5.** (2) Esperado: `/tmp/.env ausente: OK`. **PARE se:** `PARE: existe /tmp/.env`.

```bash
[ ! -e /tmp/.env ] && echo "/tmp/.env ausente: OK" || echo "PARE: existe /tmp/.env; o ensaio leria esse arquivo"
```

- [ ] **6.** (2) Esperado: `extraido em /tmp/ensaio-deploy7-qa.<sufixo>`. **PARE se:** `PARE: falha ao extrair o deploy7-commit`.

```bash
cd /var/www/chronodesk; export D7=$(cat /var/backups/chronodesk/deploy7-commit); Q=$(mktemp -d /tmp/ensaio-deploy7-qa.XXXXXX); export Q; bash -o pipefail -c 'git archive "$D7" | tar -x -C "$Q"' && chown -R www-data: "$Q" && echo "extraido em $Q" || echo "PARE: falha ao extrair o deploy7-commit"
```

- [ ] **7.** (2) Esperado: nenhuma linha antes de `fim do php -l`. **PARE se:** qualquer linha antes de `fim do php -l`.

```bash
find "$Q" -name '*.php' -not -path '*/node_modules/*' -exec runuser -u www-data -- php -l {} \; | grep -v '^No syntax errors' ; echo "fim do php -l"
```

- [ ] **8.** (2) Esperado: os 22 QAs com `OK` (agora com o `qa-chg`). **PARE se:** qualquer `FALHOU` (rode o QA de novo sem o `>/dev/null` e envie so o nome e a ultima linha).

```bash
[ ! -e /tmp/.env ] && for q in qa-smoke qa-auth qa-timezone qa-overtime qa-schedule-history qa-schedule-legacy-output qa-presence-engine qa-absences qa-calendar-text qa-archive qa-presence-cutoff qa-pa-conflicts qa-operational-view qa-employee-lunch qa-pa-distribution qa-paridade-escala qa-rotation-teams qa-critico-hibrido qa-critical-roles qa-chg qa-security qa-deploy-runbook; do printf '%s: ' "$q"; runuser -u www-data -- php "$Q/scripts/$q.php" >/dev/null 2>&1 && echo OK || echo FALHOU; done
```

- [ ] **9.** (2) Esperado: `removido: ...` e `ensaios restantes: 0`. **PARE se:** `ensaios restantes:` diferente de 0.

```bash
for Q in /tmp/ensaio-deploy7-qa.??????; do [ -d "$Q" ] && rm -r -- "$Q" && echo "removido: $Q"; done; echo "ensaios restantes: $(ls -d /tmp/ensaio-deploy7-qa.?????? 2>/dev/null | wc -l)"; unset Q D7
```

## 3. Q-CHG e ensaio da 025 no MySQL do servidor (portal no ar)

- [ ] **10.** (3) Esperado: a Q-CHG: `tabelas_025 = 0`, `checks_025 = 0`, `copias_de_rollback_025 = 0` e "025 ausente"; anote o numero de linhas e a assinatura dos cadastros. **PARE se:** outro numero ou erro do MySQL.

```bash
cd /var/www/chronodesk; export D7=$(cat /var/backups/chronodesk/deploy7-commit); read -rsp 'Senha root do MySQL: ' P; echo; export P; bash -o pipefail -c 'git show "$D7:migrations/verificacao/20261117_025_chg_check.sql" | docker exec -i -e MYSQL_PWD="$P" Chrono_Desk_DB mysql -uroot --table sistema_pausas'; unset P D7
```

- [ ] **11.** (3) Esperado: `extraido em /root/ensaio-deploy7.<sufixo>`. **PARE se:** `PARE: falha ao extrair as migrations do deploy7-commit`.

```bash
cd /var/www/chronodesk; export D7=$(cat /var/backups/chronodesk/deploy7-commit); EV=$(mktemp -d /root/ensaio-deploy7.XXXXXX); export EV; git cat-file -e "$D7^{commit}" && bash -o pipefail -c 'git archive "$D7" migrations scripts/ensaio-migration.sh | tar -x -C "$EV"' && echo "extraido em $EV" || echo "PARE: falha ao extrair as migrations do deploy7-commit"; unset D7
```

- [ ] **12.** (3) Esperado: variante (a): migration 2x `OK` (`tabelas_025 = 10`, `checks_025 = 27`, linhas em 0), 1o rollback `OK` (`copias_de_rollback_025 = 10`, "025 ausente") e o 2o parando de proposito; a assinatura dos cadastros igual nos cinco estados. **PARE se:** `FALHOU` em etapa que nao seja o 2o rollback, ou assinatura diferente.

```bash
env VERIFICA="$EV/migrations/verificacao/20261117_025_chg_check.sql" bash "$EV/scripts/ensaio-migration.sh" d7_025 "$EV/migrations/20261117_025_chg.sql" "$EV/migrations/rollback/20261117_025_chg_down.sql" portal_pa_assignments,portal_absences,portal_shift_attachments,portal_overtime_entries,portal_time_adjustments,portal_oncall_shifts,audit_log
```

- [ ] **13.** (3) Esperado: variante (b): depois das linhas, `grupo_tools = 1`, `carteiras_catalogo = 1`, `locais_catalogo = 1`, `cards = 1`, `planos = 1`, `tentativas = 1`, `endpoints = 1`, `rollbacks_ativos = 1` e as contagens de problema em 0; 1o rollback `OK` (`copias_de_rollback_025 = 10`), 2o para de proposito. **PARE se:** `FALHOU` em etapa que nao seja o 2o rollback, ou problema diferente de 0.

```bash
env PREPARO="$EV/migrations/20261117_025_chg.sql" VERIFICA="$EV/migrations/verificacao/20261117_025_chg_check.sql" bash "$EV/scripts/ensaio-migration.sh" d7_025_rollback "$EV/migrations/rollback/20261117_025_ensaio_linhas_chg.sql" "$EV/migrations/rollback/20261117_025_chg_down.sql" portal_pa_assignments,portal_absences,portal_shift_attachments,portal_overtime_entries,portal_time_adjustments,portal_oncall_shifts,audit_log
```

- [ ] **14.** (3) Esperado: `removido: /root/ensaio-deploy7.<sufixo>`. **PARE se:** `PARE: diretorio do ensaio inesperado`.

```bash
case "$EV" in /root/ensaio-deploy7.*) rm -r "$EV" && echo "removido: $EV";; *) echo "PARE: diretorio do ensaio inesperado";; esac; unset EV
```

## 4. Espaco livre e backup rapido (portal no ar)

- [ ] **15.** (4) Esperado: seis linhas `OK` e `espaco livre: OK`. **PARE se:** `BAIXO` ou `PARE: espaco livre abaixo do minimo`.

```bash
cd /var/www/chronodesk/frontend; NPMC=$(npm config get cache); test -e "$NPMC" || NPMC=$(dirname "$NPMC"); DROOT=$(docker info --format '{{.DockerRootDir}}'); ESP=OK; for LIM in "/var/www/chronodesk:500" "$NPMC:200" "/var/backups:100" "$DROOT:200" "/tmp:50" "/root:50"; do DIR=${LIM%:*}; MIN=${LIM##*:}; LIVRE=$(df -BM --output=avail "$DIR" | tail -n 1 | tr -dc '0-9'); if [ "${LIVRE:-0}" -ge "$MIN" ]; then echo "OK $DIR (disco $(df --output=target "$DIR" | tail -n 1)): $LIVRE MB livres, minimo $MIN"; else echo "BAIXO $DIR: ${LIVRE:-?} MB livres, minimo $MIN"; ESP=PARE; fi; done; [ "$ESP" = OK ] && echo "espaco livre: OK" || echo "PARE: espaco livre abaixo do minimo"; unset NPMC DROOT ESP LIM DIR MIN LIVRE
```

- [ ] **16.** (4) Esperado: `backup concluido: /var/backups/chronodesk/deploy7-<data> (<tamanho>)` (anote). **PARE se:** `PARE: backup falhou`.

```bash
B=/var/backups/chronodesk/deploy7-$(date +%Y%m%d-%H%M%S); export B; read -rsp 'Senha root do MySQL: ' P; echo; export P; install -d -o root -g root -m 700 "$B" && bash -o pipefail -c 'docker exec -i -e MYSQL_PWD="$P" Chrono_Desk_DB mysqldump -uroot --single-transaction --routines --triggers --no-tablespaces --set-gtid-purged=OFF sistema_pausas | gzip | tee "$B/database.sql.gz" > /dev/null' && gzip -t "$B/database.sql.gz" && tar -czf "$B/estado.tar.gz" -C /var/www/chronodesk --ignore-failed-read estado.json pausas.csv config_sistema.json funcionarios.json && sh -c 'cd "$1" && sha256sum database.sql.gz estado.tar.gz > SHA256SUMS' _ "$B" && echo "backup concluido: $B ($(du -sh "$B" | cut -f1))" || echo "PARE: backup falhou (sem ponto de restauracao, o deploy nao segue)"; unset P
```

## 5. Manutencao e copia do `app/` (o portal responde 503 ate o comando 35)

- [ ] **17.** (5) Esperado: `arquivo de manutencao: presente`. **PARE se:** `AUSENTE`.

```bash
touch /var/www/chronodesk.maintenance; echo "arquivo de manutencao: $(test -e /var/www/chronodesk.maintenance && echo presente || echo AUSENTE)"
```

- [ ] **18.** (5) Esperado: `copia do app/: /var/backups/chronodesk/app-antes-deploy7 (...)` (anote). **PARE se:** linha `PARE`.

```bash
cd /var/www/chronodesk; A=/var/backups/chronodesk/app-antes-deploy7; if test -e "$A"; then diff -rq app "$A" && echo "copia do app/ ja existe e e igual: $A" || echo "PARE: $A existe e e diferente do app/ atual"; else cp -a app "$A" && diff -rq app "$A" && echo "copia do app/: $A ($(du -sh "$A" | cut -f1); $(find "$A" -type f | wc -l) arquivos)" || echo "PARE: copia do app/ falhou"; fi; unset A
```

- [ ] **19.** (5) Esperado: `portal visto de fora (manutencao): 503`. **PARE se:** outro codigo ou `PARE`.

```bash
S=$(awk 'tolower($1) == "servername" {print $2; exit}' /etc/apache2/sites-enabled/chronodesk.conf); if [ -n "$S" ]; then curl -s -o /dev/null -w 'portal visto de fora (manutencao): %{http_code}\n' "http://$S/"; else echo "PARE: ServerName nao encontrado no chronodesk.conf"; fi; unset S
```

## 6. Migration 025, antes do merge, e a verificacao

- [ ] **20.** (6) Esperado: `025 aplicada`. **PARE se:** `PARE: a 025 nao foi aplicada` (fique em manutencao; o codigo ainda e o do deploy 6).

```bash
cd /var/www/chronodesk; export D7=$(cat /var/backups/chronodesk/deploy7-commit); read -rsp 'Senha root do MySQL: ' P; echo; export P; git cat-file -e "$D7:migrations/20261117_025_chg.sql" && bash -o pipefail -c 'git show "$D7:migrations/20261117_025_chg.sql" | docker exec -i -e MYSQL_PWD="$P" Chrono_Desk_DB mysql -uroot sistema_pausas' && echo "025 aplicada" || echo "PARE: a 025 nao foi aplicada"; unset P D7
```

- [ ] **21.** (6) Esperado: `tabelas_025 = 10`, `checks_025 = 27`, `copias_de_rollback_025 = 0`, linhas e problemas em 0, assinatura dos cadastros igual a da Q-CHG (comando 10). **PARE se:** outro numero (fique em manutencao).

```bash
cd /var/www/chronodesk; export D7=$(cat /var/backups/chronodesk/deploy7-commit); read -rsp 'Senha root do MySQL: ' P; echo; export P; bash -o pipefail -c 'git show "$D7:migrations/verificacao/20261117_025_chg_check.sql" | docker exec -i -e MYSQL_PWD="$P" Chrono_Desk_DB mysql -uroot --table sistema_pausas'; unset P D7
```

## 7. Merge, build e QA do bundle

- [ ] **22.** (7) Esperado: o `merge` em fast-forward, `HEAD = deploy7-commit` e `alteracoes locais: 0`. **PARE se:** `PARE: HEAD diferente do deploy7-commit` ou merge que nao e fast-forward.

```bash
cd /var/www/chronodesk; D7=$(cat /var/backups/chronodesk/deploy7-commit); git merge --ff-only "$D7"; [ "$(git rev-parse HEAD)" = "$D7" ] && echo "HEAD = deploy7-commit" || echo "PARE: HEAD diferente do deploy7-commit"; echo "alteracoes locais: $(git status --porcelain | wc -l)"; unset D7
```

- [ ] **23.** (7) Esperado: `lockfile sem mudanca: so o build` e o build sem erro. **PARE se:** erro no build.

```bash
cd /var/www/chronodesk/frontend && if [ "$(git diff --name-only "$(cat /var/backups/chronodesk/pre-deploy7-commit)" HEAD -- package-lock.json | wc -l)" = 0 ]; then echo "lockfile sem mudanca: so o build"; npm run build; else echo "lockfile alterado: npm ci e build"; npm ci && npm run build; fi
```

- [ ] **24.** (7) Esperado: `Bundle frontend QA OK (...)` com o CSS do portal abaixo de 70000 e o do Controle de CHG abaixo de 25000. **PARE se:** falha do QA do bundle.

```bash
cd /var/www/chronodesk/frontend && npm run -s qa:bundle
```

## 8. Recarga, health, fuso e fumaca (ainda em manutencao, modulo desligado)

- [ ] **25.** (8) Esperado: recarga sem saida. **PARE se:** erro na recarga.

```bash
systemctl reload "php$(php -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;')-fpm"
```

- [ ] **26.** (8) Esperado: `health: 200`. **PARE se:** outro codigo ou `PARE`.

```bash
S=$(awk 'tolower($1) == "servername" {print $2; exit}' /etc/apache2/sites-enabled/chronodesk.conf); if [ -n "$S" ]; then curl -s -o /dev/null -w 'health: %{http_code}\n' -H "Host: $S" http://127.0.0.1/api/health.php; else echo "PARE: ServerName nao encontrado no chronodesk.conf"; fi; unset S
```

- [ ] **27.** (8) Esperado: `QA timezone (banco real) OK`. **PARE se:** falha.

```bash
cd /var/www/chronodesk; runuser -u www-data -- php scripts/qa-timezone.php --db
```

- [ ] **28.** (8) Esperado: `FUMACA OK`. **PARE se:** qualquer falha da fumaca: nao reabra.

```bash
cd /var/www/chronodesk; S=$(awk 'tolower($1) == "servername" {print $2; exit}' /etc/apache2/sites-enabled/chronodesk.conf); if [ -n "$S" ]; then env PHPLOG=/var/log/chronodesk/php_error.log bash scripts/smoke-deploy1.sh "$S"; else echo "PARE: ServerName nao encontrado no chronodesk.conf"; fi; unset S
```

## 9. Conferencia no banco, so leitura

- [ ] **29.** (9) Esperado: os mesmos numeros do comando 21. **PARE se:** numero diferente.

```bash
cd /var/www/chronodesk; export D7=$(cat /var/backups/chronodesk/deploy7-commit); read -rsp 'Senha root do MySQL: ' P; echo; export P; bash -o pipefail -c 'git show "$D7:migrations/verificacao/20261117_025_chg_check.sql" | docker exec -i -e MYSQL_PWD="$P" Chrono_Desk_DB mysql -uroot --table sistema_pausas'; unset P D7
```

## 10. Ligar o Controle de CHG (`CHG_MODULE_ENABLED=true` no `/var/www/.env`), ainda em manutencao

- [ ] **30.** (10) Esperado: nada acima de `fim (esperado: nada acima)`. **PARE se:** qualquer linha do pool (o pool vence o `.env`: avise).

```bash
grep -rn 'CHG_MODULE_ENABLED' /etc/php/*/fpm/ ; echo "fim (esperado: nada acima)"
```

- [ ] **31.** (10) Esperado: `controle de CHG (desligado, sem sessao): 404`. **PARE se:** outro codigo.

```bash
S=$(awk 'tolower($1) == "servername" {print $2; exit}' /etc/apache2/sites-enabled/chronodesk.conf); if [ -n "$S" ]; then curl -s -o /dev/null -w 'controle de CHG (desligado, sem sessao): %{http_code}\n' -H "Host: $S" http://127.0.0.1/api/portal/chg.php; else echo "PARE: ServerName nao encontrado no chronodesk.conf"; fi; unset S
```

- [ ] **32.** (10) Esperado: `copia do .env: ... (600 root)` e `linhas CHG_MODULE_ENABLED=true no .env: 1`. **PARE se:** `PARE: copia do .env falhou` ou outro numero.

```bash
K=/var/backups/chronodesk/env-antes-deploy7-$(date +%Y%m%d-%H%M%S); if install -m 600 /var/www/.env "$K"; then echo "copia do .env: $K ($(stat -c '%a %U' "$K"))"; if grep -q '^CHG_MODULE_ENABLED=' /var/www/.env; then sed -i 's/^CHG_MODULE_ENABLED=.*/CHG_MODULE_ENABLED=true/' /var/www/.env; else echo 'CHG_MODULE_ENABLED=true' >> /var/www/.env; fi; echo "linhas CHG_MODULE_ENABLED=true no .env: $(grep -c '^CHG_MODULE_ENABLED=true$' /var/www/.env)"; else echo "PARE: copia do .env falhou, nada alterado"; fi; unset K
```

- [ ] **33.** (10) Esperado: recarga sem saida. **PARE se:** erro na recarga.

```bash
systemctl reload "php$(php -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;')-fpm"
```

- [ ] **34.** (10) Esperado: `controle de CHG (ligado, sem sessao): 401`. **PARE se:** outro codigo (desligue de novo pelos comandos 39 e 40 e avise).

```bash
S=$(awk 'tolower($1) == "servername" {print $2; exit}' /etc/apache2/sites-enabled/chronodesk.conf); if [ -n "$S" ]; then curl -s -o /dev/null -w 'controle de CHG (ligado, sem sessao): %{http_code}\n' -H "Host: $S" http://127.0.0.1/api/portal/chg.php; else echo "PARE: ServerName nao encontrado no chronodesk.conf"; fi; unset S
```

## 11. Reabertura e conferencia na tela

- [ ] **35.** (11) Esperado: `manutencao encerrada` e `portal visto de fora: 302`; na tela, como admin, sem salvar nada: o Controle de CHG no menu com o quadro vazio e os seis indicadores em 0, e o Grupo Tools ativo em Funcionarios (passo 11 do roteiro). **PARE se:** linha `PARE`, outro codigo, ou tela em branco ou quebrada. Depois, o passo 12 do roteiro (importacao pela tela, so contagens anotadas).

```bash
[ -e /var/www/chronodesk.maintenance ] && rm -- /var/www/chronodesk.maintenance; [ -e /var/www/chronodesk.maintenance ] && echo "PARE: arquivo de manutencao ainda presente" || echo "manutencao encerrada"; S=$(awk 'tolower($1) == "servername" {print $2; exit}' /etc/apache2/sites-enabled/chronodesk.conf); if [ -n "$S" ]; then curl -s -o /dev/null -w 'portal visto de fora: %{http_code}\n' "http://$S/"; else echo "PARE: ServerName nao encontrado no chronodesk.conf"; fi; unset S
```

## 13. Medicao do polling (alguns minutos depois da reabertura, 2 rodadas, sessao de gestao)

- [ ] **36.** (13) Esperado: p50 e p95 do `status.php` (anote a melhor rodada). **PARE se:** p95 acima de 50 ms **e** 20% acima da base do deploy 6: meca de novo e avise.

```bash
cd /var/www/chronodesk; bash scripts/medir-polling.sh
```

- [ ] **37.** (13) Esperado: p50 e p95 do `dashboard.php` (anote a melhor rodada). **PARE se:** o mesmo do comando anterior.

```bash
cd /var/www/chronodesk; URL=http://127.0.0.1/api/portal/dashboard.php bash scripts/medir-polling.sh
```

- [ ] **38.** (13) Esperado: p50 e p95 do `solicitacoes_pendentes.php` (anote a melhor rodada). **PARE se:** o mesmo do comando anterior.

```bash
cd /var/www/chronodesk; URL=http://127.0.0.1/api/solicitacoes_pendentes.php bash scripts/medir-polling.sh
```

## 14. Desligamento rapido do Controle de CHG (so se preciso, com aprovacao)

- [ ] **39.** (14) Esperado: `copia do .env: ... (600 root)` e `linhas CHG_MODULE_ENABLED=false no .env: 1`. **PARE se:** `PARE: copia do .env falhou`.

```bash
K=/var/backups/chronodesk/env-antes-desligar-chg-$(date +%Y%m%d-%H%M%S); if install -m 600 /var/www/.env "$K"; then echo "copia do .env: $K ($(stat -c '%a %U' "$K"))"; sed -i 's/^CHG_MODULE_ENABLED=.*/CHG_MODULE_ENABLED=false/' /var/www/.env; echo "linhas CHG_MODULE_ENABLED=false no .env: $(grep -c '^CHG_MODULE_ENABLED=false$' /var/www/.env)"; else echo "PARE: copia do .env falhou, nada alterado"; fi; unset K
```

- [ ] **40.** (14) Esperado: recarga sem saida; na tela, o menu sem o Controle de CHG. **PARE se:** erro na recarga.

```bash
systemctl reload "php$(php -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;')-fpm"
```

## 15. Rollback de codigo (so se preciso, com aprovacao)

- [ ] **41.** (15) Esperado: `codigo anterior no ar: <hash curto do deploy 6>`. **PARE se:** erro do checkout.

```bash
cd /var/www/chronodesk; git checkout --detach "$(cat /var/backups/chronodesk/pre-deploy7-commit)" && echo "codigo anterior no ar: $(git rev-parse --short HEAD)"
```

- [ ] **42.** (15) Esperado: `app/ anterior de volta (...)`. **PARE se:** linha `PARE`.

```bash
cd /var/www/chronodesk; A=/var/backups/chronodesk/app-antes-deploy7; X=/var/backups/chronodesk/app-deploy7-retirado-$(date +%Y%m%d-%H%M%S); if test -d "$A"; then { ! test -e app || mv app "$X"; } && cp -a "$A" app && diff -rq "$A" app && echo "app/ anterior de volta (o build retirado esta em $X)" || echo "PARE: a volta do app/ falhou"; else echo "PARE: copia $A ausente; nada mudou no app/"; fi; unset A X
```

- [ ] **43.** (15) Esperado: recarga sem saida. Depois, os comandos 25 a 28 e o 35. **PARE se:** erro na recarga.

```bash
systemctl reload "php$(php -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;')-fpm"
```

## 16. Rollback da 025 (so depois do rollback de codigo, e so com aprovacao)

- [ ] **44.** (16) Esperado: `rollback da 025 aplicado`. **PARE se:** `PARE` (com `rollback_025 abortado: ja existe copia *_bkp_025`: confira e renomeie as copias antes).

```bash
cd /var/www/chronodesk; export D7=$(cat /var/backups/chronodesk/deploy7-commit); read -rsp 'Senha root do MySQL: ' P; echo; export P; [ "$(git rev-parse HEAD)" = "$(cat /var/backups/chronodesk/pre-deploy7-commit)" ] && git cat-file -e "$D7:migrations/rollback/20261117_025_chg_down.sql" && bash -o pipefail -c 'git show "$D7:migrations/rollback/20261117_025_chg_down.sql" | docker exec -i -e MYSQL_PWD="$P" Chrono_Desk_DB mysql -uroot sistema_pausas' && echo "rollback da 025 aplicado" || echo "PARE: o codigo no ar nao e o do deploy 6 ou o rollback da 025 falhou"; unset P D7
```

- [ ] **45.** (16) Esperado: `tabelas_025 = 0`, `copias_de_rollback_025 = 10`, "025 ausente" e a assinatura dos cadastros igual a do comando 21. **PARE se:** outro numero.

```bash
cd /var/www/chronodesk; export D7=$(cat /var/backups/chronodesk/deploy7-commit); read -rsp 'Senha root do MySQL: ' P; echo; export P; bash -o pipefail -c 'git show "$D7:migrations/verificacao/20261117_025_chg_check.sql" | docker exec -i -e MYSQL_PWD="$P" Chrono_Desk_DB mysql -uroot --table sistema_pausas'; unset P D7
```

## Depois de um ciclo estavel

Com o responsavel: o push do commit validado para o `codex/modernizacao-ui-backend` (nunca `--force`); com aprovacao, a limpeza de `/var/backups/chronodesk` (`deploy7-<data>`, `app-antes-deploy7`, as copias `env-antes-*`, `pre-deploy7-commit` e `deploy7-commit`). A Lideranca e o admin marcam o grupo Tools pela tela.
