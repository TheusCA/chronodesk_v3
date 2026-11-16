# Deploy 4: folha de execucao do dia

Gerada do roteiro "Deploy 4 (F10-2: times de rodizio, ciclo semana a semana e grupo da escala; migration 021)" do `DEPLOY_LINUX.md`: os mesmos comandos, na mesma ordem. O roteiro e a referencia: o detalhe de cada esperado esta la. Mudou o roteiro, muda esta folha no mesmo commit.

**O que vai:** a migration 021 (tabelas dos times de rodizio, `rotation_team_id` nas regras e `grupo_escala` nos cadastros; nenhuma linha muda) e o codigo do F10-2 (times na tela Escala, ciclo semana a semana, 409 da regra individual de membro, grupo da escala). Ninguem vira membro de time: a escala de todos fica igual. O lockfile nao mudou desde o `cd0653b`: so o build. **Pre-requisito:** o deploy 3 executado com o `cd0653b`.

**Data, janela e quem acompanha: A DEFINIR.**

**Shell root, sem `sudo`.** Abra o terminal da VM com `sudo -i` e rode **todos** os comandos desta folha nele, como estao (nenhum leva `sudo`; o que roda como `www-data` usa `runuser`). O comando 1 e a conferencia do ambiente. Terminal reaberto: `sudo -i` e o comando 1 de novo.

**Sem valor real nesta folha**, nem impressa nem anotada nela: nada de senha, IP, hostname, nome ou login. O `ServerName` e lido do `chronodesk.conf` na hora. A senha do root do MySQL e sempre digitada no `read -rsp` (sem eco). Numeros anotados vao para o registro do deploy, nunca para chat ou chamado.

**Regras de parada em toda a folha:** qualquer linha com `PARE`, ou saida diferente do esperado: pare e envie so a contagem ou a mensagem. Nada de `git pull`; o unico `git fetch` e o do comando 3.

## 0. Antes do dia, na estacao

- [ ] **0.** O responsavel publica o `feat/employee-form-rhf-zod`. Depois, a bateria completa sozinha (nada pesado em paralelo): `git fetch origin; git worktree add --detach ..\chronodesk-deploy4 origin/feat/employee-form-rhf-zod; Set-Location ..\chronodesk-deploy4\frontend; npm ci; Set-Location ..; $env:CHROME_PATH='C:\Program Files\Google\Chrome\Application\chrome.exe'; powershell -NoProfile -ExecutionPolicy Bypass -File scripts\test-local.ps1 -SkipHttp -Browser; git rev-parse HEAD`. Esperado: `Validacao local concluida com sucesso`, `frontend: 27 scripts npm rodados (...): 27 OK, 0 com falha`, navegador com `falharam: 0` e o hash completo: anote como `<commit validado na estacao>`. Qualquer falha: nao ha deploy com esse commit.
- [ ] **0b.** Na vespera, o aviso a Lideranca e a gestao do roteiro (passo 0b), com a data e a janela preenchidas; sem nome, hostname nem endereco.

## 1. Conferencia do ambiente, commit validado e producao no `cd0653b`

- [ ] **1.** (1, ambiente) Esperado: antes, `sudo -i`. Oito linhas `OK` e `ambiente: OK`. **PARE se:** qualquer `BLOQUEADO` ou `PARE: ambiente com BLOQUEADO`.

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

- [ ] **4.** (1) Esperado: `fast-forward OK: <N> commits a aplicar` (anote), `migrations alteradas:` com exatamente os quatro arquivos da 021 e `lockfile alterado: 0` (com `1`, anote: o comando 23 faz o `npm ci`). **PARE se:** linha `PARE` ou outra migration na lista.

```bash
cd /var/www/chronodesk; D4=$(cat /var/backups/chronodesk/deploy4-commit); git cat-file -e "$D4^{commit}" && git cat-file -e "$D4:scripts/smoke-deploy1.sh" && git cat-file -e "$D4:migrations/20261115_021_rotation_teams.sql" && git merge-base --is-ancestor HEAD "$D4" && echo "fast-forward OK: $(git rev-list --count HEAD.."$D4") commits a aplicar" || echo "PARE: deploy4-commit ausente, sem o smoke-deploy1.sh, sem a 021 ou o HEAD nao e ancestral dele"; echo "migrations alteradas: $(git diff --name-only HEAD "$D4" -- migrations | sort | tr '\n' ' ')"; echo "lockfile alterado: $(git diff --name-only HEAD "$D4" -- frontend/package-lock.json | wc -l)"; unset D4
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

- [ ] **8.** (2) Esperado: os 19 QAs com `OK` (os 18 do deploy 3, mais o `qa-rotation-teams` do F10-2). **PARE se:** algum `FALHOU`: antes do comando 9, rode o QA de novo sem o `>/dev/null` e envie so o nome e a ultima linha.

```bash
[ ! -e /tmp/.env ] && for q in qa-smoke qa-auth qa-timezone qa-overtime qa-schedule-history qa-schedule-legacy-output qa-presence-engine qa-absences qa-calendar-text qa-archive qa-presence-cutoff qa-pa-conflicts qa-operational-view qa-employee-lunch qa-pa-distribution qa-paridade-escala qa-rotation-teams qa-security qa-deploy-runbook; do printf '%s: ' "$q"; runuser -u www-data -- php "$Q/scripts/$q.php" >/dev/null 2>&1 && echo OK || echo FALHOU; done
```

- [ ] **9.** (2) Esperado: `removido: /tmp/ensaio-deploy4-qa.<sufixo>` e `ensaios restantes: 0`. **PARE se:** `ensaios restantes` maior que `0`.

```bash
for Q in /tmp/ensaio-deploy4-qa.??????; do [ -d "$Q" ] && rm -r -- "$Q" && echo "removido: $Q"; done; echo "ensaios restantes: $(ls -d /tmp/ensaio-deploy4-qa.?????? 2>/dev/null | wc -l)"; unset Q D4
```

## 3. Ensaio da 021 no MySQL do servidor (banco descartavel `ensaio_*`; portal no ar)

- [ ] **10.** (3) Esperado: `extraido em /root/ensaio-deploy4.<sufixo>`. **PARE se:** `PARE: falha ao extrair as migrations do deploy4-commit`.

```bash
cd /var/www/chronodesk; export D4=$(cat /var/backups/chronodesk/deploy4-commit); EV=$(mktemp -d /root/ensaio-deploy4.XXXXXX); export EV; git cat-file -e "$D4^{commit}" && bash -o pipefail -c 'git archive "$D4" migrations scripts/ensaio-migration.sh | tar -x -C "$EV"' && echo "extraido em $EV" || echo "PARE: falha ao extrair as migrations do deploy4-commit"; unset D4
```

- [ ] **11.** (3, ensaio a) Esperado: pede a senha do root do MySQL (sem eco). Migration 2x `OK` (`tabelas_021 = 3`, `colunas_021 = 2`, `restricoes_021 = 9`, times em 0, todos `comum`); 1o rollback `OK` (`copias_de_rollback_021 = 5`, "021 ausente"); 2o rollback para de proposito (`rollback_021 abortado`); assinaturas de `regras` e `funcionarios` iguais nos cinco estados (anote as linhas); `Removido.` **PARE se:** `FALHOU` fora do 2o rollback ou assinatura diferente.

```bash
env DADOS=portal_schedule_rules,portal_schedule_exceptions VERIFICA="$EV/migrations/verificacao/20261115_021_rotation_teams_check.sql" bash "$EV/scripts/ensaio-migration.sh" d4_021 "$EV/migrations/20261115_021_rotation_teams.sql" "$EV/migrations/rollback/20261115_021_rotation_teams_down.sql" audit_log
```

- [ ] **12.** (3, ensaio b) Esperado: depois das linhas, `times = 1`, `versoes = 1`, `pertencas_abertas = 1`, `regras_de_time = 1`, as duas inconsistencias em 0 e um cadastro `separado` (os tres primeiros em 0 se a copia nao tiver N1 ativo com regra aberta); rollback volta, assinatura das regras igual a inicial; `Removido.` **PARE se:** `FALHOU` fora do 2o rollback ou assinatura diferente.

```bash
env PREPARO="$EV/migrations/20261115_021_rotation_teams.sql" DADOS=portal_schedule_rules,portal_schedule_exceptions VERIFICA="$EV/migrations/verificacao/20261115_021_rotation_teams_check.sql" bash "$EV/scripts/ensaio-migration.sh" d4_021_rollback "$EV/migrations/rollback/20261115_021_ensaio_linhas_f10.sql" "$EV/migrations/rollback/20261115_021_rotation_teams_down.sql" audit_log
```

- [ ] **13.** (3) Esperado: `removido: /root/ensaio-deploy4.<sufixo>`. **PARE se:** `PARE: diretorio do ensaio inesperado`.

```bash
case "$EV" in /root/ensaio-deploy4.*) rm -r "$EV" && echo "removido: $EV";; *) echo "PARE: diretorio do ensaio inesperado";; esac; unset EV
```

## 4. Espaco livre e backup rapido (portal no ar)

- [ ] **14.** (4) Esperado: seis linhas `OK` e `espaco livre: OK` (anote os livres). **PARE se:** qualquer `BAIXO` ou `PARE: espaco livre abaixo do minimo`.

```bash
cd /var/www/chronodesk/frontend; NPMC=$(npm config get cache); test -e "$NPMC" || NPMC=$(dirname "$NPMC"); DROOT=$(docker info --format '{{.DockerRootDir}}'); ESP=OK; for LIM in "/var/www/chronodesk:500" "$NPMC:200" "/var/backups:100" "$DROOT:200" "/tmp:50" "/root:50"; do DIR=${LIM%:*}; MIN=${LIM##*:}; LIVRE=$(df -BM --output=avail "$DIR" | tail -n 1 | tr -dc '0-9'); if [ "${LIVRE:-0}" -ge "$MIN" ]; then echo "OK $DIR (disco $(df --output=target "$DIR" | tail -n 1)): $LIVRE MB livres, minimo $MIN"; else echo "BAIXO $DIR: ${LIVRE:-?} MB livres, minimo $MIN"; ESP=PARE; fi; done; [ "$ESP" = OK ] && echo "espaco livre: OK" || echo "PARE: espaco livre abaixo do minimo"; unset NPMC DROOT ESP LIM DIR MIN LIVRE
```

- [ ] **15.** (4) Esperado: `backup concluido: /var/backups/chronodesk/deploy4-<data> (<tamanho>)` (anote). **PARE se:** `PARE: backup falhou`.

```bash
B=/var/backups/chronodesk/deploy4-$(date +%Y%m%d-%H%M%S); export B; read -rsp 'Senha root do MySQL: ' P; echo; export P; install -d -o root -g root -m 700 "$B" && bash -o pipefail -c 'docker exec -i -e MYSQL_PWD="$P" Chrono_Desk_DB mysqldump -uroot --single-transaction --routines --triggers --no-tablespaces --set-gtid-purged=OFF sistema_pausas | gzip | tee "$B/database.sql.gz" > /dev/null' && gzip -t "$B/database.sql.gz" && tar -czf "$B/estado.tar.gz" -C /var/www/chronodesk --ignore-failed-read estado.json pausas.csv config_sistema.json funcionarios.json && sh -c 'cd "$1" && sha256sum database.sql.gz estado.tar.gz > SHA256SUMS' _ "$B" && echo "backup concluido: $B ($(du -sh "$B" | cut -f1))" || echo "PARE: backup falhou (sem ponto de restauracao, o deploy nao segue)"; unset P
```

## 5. Manutencao e copia do `app/` (o portal fica em 503 ate o comando 29)

- [ ] **16.** (5) Esperado: `arquivo de manutencao: presente`. **PARE se:** `AUSENTE`.

```bash
touch /var/www/chronodesk.maintenance; echo "arquivo de manutencao: $(test -e /var/www/chronodesk.maintenance && echo presente || echo AUSENTE)"
```

- [ ] **17.** (5) Esperado: `copia do app/: /var/backups/chronodesk/app-antes-deploy4 (...)` (anote tamanho e arquivos). **PARE se:** `PARE: ...` (tire o arquivo-sinal com o comando 29 e avise).

```bash
cd /var/www/chronodesk; A=/var/backups/chronodesk/app-antes-deploy4; if test -e "$A"; then diff -rq app "$A" && echo "copia do app/ ja existe e e igual: $A" || echo "PARE: $A existe e e diferente do app/ atual"; else cp -a app "$A" && diff -rq app "$A" && echo "copia do app/: $A ($(du -sh "$A" | cut -f1); $(find "$A" -type f | wc -l) arquivos)" || echo "PARE: copia do app/ falhou"; fi; unset A
```

- [ ] **18.** (5) Esperado: `portal visto de fora (manutencao): 503`. **PARE se:** a estacao ve a tela do portal (tire o arquivo-sinal com o comando 29 e avise).

```bash
S=$(awk 'tolower($1) == "servername" {print $2; exit}' /etc/apache2/sites-enabled/chronodesk.conf); if [ -n "$S" ]; then curl -s -o /dev/null -w 'portal visto de fora (manutencao): %{http_code}\n' "http://$S/"; else echo "PARE: ServerName nao encontrado no chronodesk.conf"; fi; unset S
```

## 6. Migration 021, antes do merge, e a verificacao

- [ ] **19.** (6) Esperado: `021 aplicada`. **PARE se:** `PARE: a 021 nao foi aplicada`: fique em manutencao (o codigo ainda e o do deploy 3).

```bash
cd /var/www/chronodesk; export D4=$(cat /var/backups/chronodesk/deploy4-commit); read -rsp 'Senha root do MySQL: ' P; echo; export P; git cat-file -e "$D4:migrations/20261115_021_rotation_teams.sql" && bash -o pipefail -c 'git show "$D4:migrations/20261115_021_rotation_teams.sql" | docker exec -i -e MYSQL_PWD="$P" Chrono_Desk_DB mysql -uroot sistema_pausas' && echo "021 aplicada" || echo "PARE: a 021 nao foi aplicada"; unset P D4
```

- [ ] **20.** (6) Esperado: `tabelas_021 = 3`, `colunas_021 = 2`, `restricoes_021 = 9`, `copias_de_rollback_021 = 0`, times em 0, todos `comum`, assinaturas iguais as do estado inicial do ensaio a. **PARE se:** outro numero: fique em manutencao e avise (rollback da 021 so com aprovacao, comandos 36 e 37).

```bash
cd /var/www/chronodesk; export D4=$(cat /var/backups/chronodesk/deploy4-commit); read -rsp 'Senha root do MySQL: ' P; echo; export P; bash -o pipefail -c 'git show "$D4:migrations/verificacao/20261115_021_rotation_teams_check.sql" | docker exec -i -e MYSQL_PWD="$P" Chrono_Desk_DB mysql -uroot --table sistema_pausas'; unset P D4
```

## 7. `git merge --ff-only`, build e o QA do bundle

- [ ] **21.** (7) Esperado: o `merge` em fast-forward, `HEAD = deploy4-commit` e `alteracoes locais: 0`. **PARE se:** `PARE: HEAD diferente do deploy4-commit` (fique em manutencao; nada de `fetch` nem `pull`).

```bash
cd /var/www/chronodesk; D4=$(cat /var/backups/chronodesk/deploy4-commit); git merge --ff-only "$D4"; [ "$(git rev-parse HEAD)" = "$D4" ] && echo "HEAD = deploy4-commit" || echo "PARE: HEAD diferente do deploy4-commit"; echo "alteracoes locais: $(git status --porcelain | wc -l)"; unset D4
```

- [ ] **22.** (7) Esperado: `lockfile sem mudanca: so o build` e o build sem erro. **PARE se:** as mesmas regras do comando 16 do deploy 3 (espaco, rede, outra falha): rollback (33 a 35), com aprovacao.

```bash
cd /var/www/chronodesk/frontend && if [ "$(git diff --name-only "$(cat /var/backups/chronodesk/pre-deploy4-commit)" HEAD -- package-lock.json | wc -l)" = 0 ]; then echo "lockfile sem mudanca: so o build"; npm run build; else echo "lockfile alterado: npm ci e build"; npm ci && npm run build; fi
```

- [ ] **23.** (7) Esperado: `Bundle frontend QA OK (...)` com o CSS abaixo de 70000 bytes (68187 no F10-2). **PARE se:** falha do `qa:bundle`: rollback, com aprovacao.

```bash
cd /var/www/chronodesk/frontend && npm run -s qa:bundle
```

## 8. Recarga, health, fuso e teste de fumaca (ainda em manutencao)

- [ ] **24.** (8) Esperado: nenhuma saida. **PARE se:** erro na recarga.

```bash
systemctl reload "php$(php -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;')-fpm"
```

- [ ] **25.** (8) Esperado: `health: 200`. **PARE se:** diferente de `200`: rollback, com aprovacao.

```bash
S=$(awk 'tolower($1) == "servername" {print $2; exit}' /etc/apache2/sites-enabled/chronodesk.conf); if [ -n "$S" ]; then curl -s -o /dev/null -w 'health: %{http_code}\n' -H "Host: $S" http://127.0.0.1/api/health.php; else echo "PARE: ServerName nao encontrado no chronodesk.conf"; fi; unset S
```

- [ ] **26.** (8) Esperado: `sessao MySQL em America/Sao_Paulo` e `QA timezone (banco real) OK`. **PARE se:** falha: rollback, com aprovacao.

```bash
cd /var/www/chronodesk; runuser -u www-data -- php scripts/qa-timezone.php --db
```

- [ ] **27.** (8, teste de fumaca) Esperado: o mesmo do comando 21 do deploy 3, terminando em `FUMACA OK`. **PARE se:** qualquer `PARE`: nao reabra; envie so a saida.

```bash
cd /var/www/chronodesk; S=$(awk 'tolower($1) == "servername" {print $2; exit}' /etc/apache2/sites-enabled/chronodesk.conf); if [ -n "$S" ]; then env PHPLOG=/var/log/chronodesk/php_error.log bash scripts/smoke-deploy1.sh "$S"; else echo "PARE: ServerName nao encontrado no chronodesk.conf"; fi; unset S
```

## 9. Conferencia no banco (so leitura)

- [ ] **28.** (9) Esperado: os mesmos numeros do comando 20. **PARE se:** numero diferente.

```bash
cd /var/www/chronodesk; export D4=$(cat /var/backups/chronodesk/deploy4-commit); read -rsp 'Senha root do MySQL: ' P; echo; export P; bash -o pipefail -c 'git show "$D4:migrations/verificacao/20261115_021_rotation_teams_check.sql" | docker exec -i -e MYSQL_PWD="$P" Chrono_Desk_DB mysql -uroot --table sistema_pausas'; unset P D4
```

## 10. Reabertura e conferencia na tela

- [ ] **29.** (10) Esperado: so com os comandos 25 a 27 como esperado. `manutencao encerrada` e `portal visto de fora: 302`. Na tela, como admin (Ctrl+F5), sem salvar: **Escala presencial** com "Times de rodizio" vazia e "Novo time" (abra e feche), "Editar" em todas as regras; a regra "Ciclo" com "Em blocos" e "Semana a semana" (a grade e a data na segunda; volte para "Sem escala definida"); **Funcionarios**, "Editar", com "Grupo da escala: Comum" (feche sem salvar). **PARE se:** `PARE`, outro codigo, ou tela em branco ou quebrada: avise.

```bash
[ -e /var/www/chronodesk.maintenance ] && rm -- /var/www/chronodesk.maintenance; [ -e /var/www/chronodesk.maintenance ] && echo "PARE: arquivo de manutencao ainda presente" || echo "manutencao encerrada"; S=$(awk 'tolower($1) == "servername" {print $2; exit}' /etc/apache2/sites-enabled/chronodesk.conf); if [ -n "$S" ]; then curl -s -o /dev/null -w 'portal visto de fora: %{http_code}\n' "http://$S/"; else echo "PARE: ServerName nao encontrado no chronodesk.conf"; fi; unset S
```

## 11. Medicao do polling (alguns minutos depois da reabertura; 2 rodadas por endpoint, valendo a melhor; sessao de gestao)

- [ ] **30.** (11) Esperado: p50 e p95 do `status.php`, na melhor de 2 rodadas (anote). **PARE se:** p95 mais de 50 ms **e** mais de 20% acima da base do deploy 3: meca de novo e avise.

```bash
cd /var/www/chronodesk; bash scripts/medir-polling.sh
```

- [ ] **31.** (11) Esperado: p50 e p95 do `dashboard.php`, na melhor de 2 rodadas (anote). **PARE se:** p95 mais de 50 ms **e** mais de 20% acima da base do deploy 3: meca de novo e avise.

```bash
cd /var/www/chronodesk; URL=http://127.0.0.1/api/portal/dashboard.php bash scripts/medir-polling.sh
```

- [ ] **32.** (11) Esperado: p50 e p95 do `solicitacoes_pendentes.php`, na melhor de 2 rodadas (anote). **PARE se:** p95 mais de 50 ms **e** mais de 20% acima da base do deploy 3, ou codigo diferente de `200` (sessao de gestao).

```bash
cd /var/www/chronodesk; URL=http://127.0.0.1/api/solicitacoes_pendentes.php bash scripts/medir-polling.sh
```

## 12. Rollback de codigo (so se preciso, com aprovacao do responsavel)

- [ ] **33.** (12) Esperado: `codigo anterior no ar: cd0653b`. **PARE se:** sem a mensagem.

```bash
cd /var/www/chronodesk; git checkout --detach "$(cat /var/backups/chronodesk/pre-deploy4-commit)" && echo "codigo anterior no ar: $(git rev-parse --short HEAD)"
```

- [ ] **34.** (12) Esperado: `app/ anterior de volta (o build retirado esta em ...)`. **PARE se:** `PARE: ...`: fique em manutencao e avise.

```bash
cd /var/www/chronodesk; A=/var/backups/chronodesk/app-antes-deploy4; X=/var/backups/chronodesk/app-deploy4-retirado-$(date +%Y%m%d-%H%M%S); if test -d "$A"; then { ! test -e app || mv app "$X"; } && cp -a "$A" app && diff -rq "$A" app && echo "app/ anterior de volta (o build retirado esta em $X)" || echo "PARE: a volta do app/ falhou"; else echo "PARE: copia $A ausente; nada mudou no app/"; fi; unset A X
```

- [ ] **35.** (12) Esperado: nenhuma saida. Depois: comandos 25 a 27 e a reabertura (29). **PARE se:** erro na recarga.

```bash
systemctl reload "php$(php -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;')-fpm"
```

## 13. Rollback da 021 (so depois do 12 e so com aprovacao)

- [ ] **36.** (13, so com aprovacao) Esperado: `rollback da 021 aplicado` (so com o codigo do deploy 3 no ar). **PARE se:** `PARE: o codigo no ar nao e o do deploy 3 ou o rollback da 021 falhou`: fique em manutencao e avise.

```bash
cd /var/www/chronodesk; export D4=$(cat /var/backups/chronodesk/deploy4-commit); read -rsp 'Senha root do MySQL: ' P; echo; export P; [ "$(git rev-parse HEAD)" = "$(cat /var/backups/chronodesk/pre-deploy4-commit)" ] && git cat-file -e "$D4:migrations/rollback/20261115_021_rotation_teams_down.sql" && bash -o pipefail -c 'git show "$D4:migrations/rollback/20261115_021_rotation_teams_down.sql" | docker exec -i -e MYSQL_PWD="$P" Chrono_Desk_DB mysql -uroot sistema_pausas' && echo "rollback da 021 aplicado" || echo "PARE: o codigo no ar nao e o do deploy 3 ou o rollback da 021 falhou"; unset P D4
```

- [ ] **37.** (13) Esperado: `tabelas_021 = 0`, `colunas_021 = 0`, `copias_de_rollback_021 = 5`, "021 ausente". **PARE se:** outro numero: avise.

```bash
cd /var/www/chronodesk; export D4=$(cat /var/backups/chronodesk/deploy4-commit); read -rsp 'Senha root do MySQL: ' P; echo; export P; bash -o pipefail -c 'git show "$D4:migrations/verificacao/20261115_021_rotation_teams_check.sql" | docker exec -i -e MYSQL_PWD="$P" Chrono_Desk_DB mysql -uroot --table sistema_pausas'; unset P D4
```

## Depois de um ciclo estavel, com o responsavel

- [ ] Push do commit validado para o `codex/modernizacao-ui-backend` (como depois dos deploys 1 a 3; nunca `--force`).
- [ ] Limpeza com aprovacao em `/var/backups/chronodesk`: `deploy4-<data>` (dados pessoais), `app-antes-deploy4`, `pre-deploy4-commit` e `deploy4-commit` (listar antes so nome, modo, dono e tamanho).
- [ ] A conversao das regras de rodizio em membros de time so depois de aprovada (desenho do F10, 8.6): primeiro o plano so de leitura, depois os times e as entradas pela tela.
