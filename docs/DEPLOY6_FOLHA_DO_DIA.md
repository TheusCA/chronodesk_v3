# Deploy 6: folha de execucao do dia

Gerada do roteiro "Deploy 6 (rodizio de papeis do Critico; migration 024)" do `DEPLOY_LINUX.md`: os mesmos comandos, na mesma ordem. O roteiro e a referencia: o detalhe de cada esperado esta la. Mudou o roteiro, muda esta folha no mesmo commit.

**O que vai:** a migration 024 (tres tabelas novas e vazias: papeis, rodizio e trocas pontuais do Critico; nenhuma linha existente muda) e o codigo do rodizio de papeis (o papel do dia da a jornada e a presenca de cada um do Critico; a secao "Papeis do Critico" na tela Escala; o alerta de papel vago na Visao operacional). Sem papel e sem rodizio cadastrados, nada muda: a Lideranca cadastra pela tela depois. O lockfile nao mudou desde o commit do deploy 5: so o build. **Pre-requisito:** o deploy 5 executado com o commit dele (o hash completo do registro do deploy 5).

**Data, janela e quem acompanha: A DEFINIR.**

**Shell root, sem `sudo`.** Abra o terminal da VM com `sudo -i` e rode **todos** os comandos desta folha nele, como estao (nenhum leva `sudo`; o que roda como `www-data` usa `runuser`). O comando 1 e a conferencia do ambiente. Terminal reaberto: `sudo -i` e o comando 1 de novo.

**Sem valor real nesta folha**, nem impressa nem anotada nela: nada de senha, IP, hostname, nome ou login. O `ServerName` e lido do `chronodesk.conf` na hora. A senha do root do MySQL e sempre digitada no `read -rsp` (sem eco). Numeros anotados vao para o registro do deploy, nunca para chat ou chamado.

**Regras de parada em toda a folha:** qualquer linha com `PARE`, ou saida diferente do esperado: pare e envie so a contagem ou a mensagem. Nada de `git pull`; o unico `git fetch` e o do comando 3.

## 0. Antes do dia, na estacao

- [ ] **0.** O responsavel publica o `feat/employee-form-rhf-zod`. Depois, a bateria completa sozinha (nada pesado em paralelo): `git fetch origin; git worktree add --detach ..\chronodesk-deploy6 origin/feat/employee-form-rhf-zod; Set-Location ..\chronodesk-deploy6\frontend; npm ci; Set-Location ..; $env:CHROME_PATH='C:\Program Files\Google\Chrome\Application\chrome.exe'; powershell -NoProfile -ExecutionPolicy Bypass -File scripts\test-local.ps1 -SkipHttp -Browser; git rev-parse HEAD`. Esperado: `Validacao local concluida com sucesso`, `frontend: 29 scripts npm rodados (...): 29 OK, 0 com falha`, navegador com `falharam: 0` e o hash completo: anote como `<commit validado na estacao>`. Qualquer falha: nao ha deploy com esse commit.
- [ ] **0b.** Na vespera, o aviso a Lideranca e a gestao do roteiro (passo 0b), com a data e a janela preenchidas; sem nome, hostname nem endereco.

## 1. Conferencia do ambiente, commit validado e producao no commit do deploy 5

- [ ] **1.** (1, ambiente) Esperado: antes, `sudo -i`. Oito linhas `OK` e `ambiente: OK`. **PARE se:** qualquer `BLOQUEADO` ou `PARE: ambiente com BLOQUEADO`.

```bash
R=OK; [ "$(id -u)" = 0 ] && echo "OK id -u = 0" || { echo "BLOQUEADO id -u (o shell nao e root)"; R=PARE; }; bash -c 'exit 0' && echo "OK bash -c" || { echo "BLOQUEADO bash -c"; R=PARE; }; tar -czf - -C /etc hostname 2>/dev/null | tar -tzf - >/dev/null 2>&1 && echo "OK tar com gzip" || { echo "BLOQUEADO tar com gzip"; R=PARE; }; [ "$(find /etc/hostname -maxdepth 0 -exec echo ok \; 2>/dev/null)" = ok ] && echo "OK find -exec" || { echo "BLOQUEADO find -exec"; R=PARE; }; runuser -u www-data -- php -v >/dev/null 2>&1 && echo "OK runuser -u www-data -- php -v" || { echo "BLOQUEADO runuser -u www-data -- php -v"; R=PARE; }; [ "$(runuser -u www-data -- php -r '$p = proc_open([PHP_BINARY, "-v"], [1 => ["pipe", "w"]], $x); if (!is_resource($p)) { exit(1); } stream_get_contents($x[1]); echo proc_close($p) === 0 ? "ok" : "falha";' 2>/dev/null)" = ok ] && echo "OK php filho como www-data (proc_open, como no qa-security)" || { echo "BLOQUEADO php filho como www-data"; R=PARE; }; npm -v >/dev/null 2>&1 && echo "OK npm -v" || { echo "BLOQUEADO npm -v"; R=PARE; }; git -C /var/www/chronodesk rev-parse --git-dir >/dev/null 2>&1 && echo "OK git no clone" || { echo "BLOQUEADO git no clone"; R=PARE; }; [ "$R" = OK ] && echo "ambiente: OK" || echo "PARE: ambiente com BLOQUEADO"; unset R
```

- [ ] **2.** (1) Esperado: antes, troque `<commit do deploy 5>` pelo hash completo do registro do deploy 5. `HEAD de producao: o commit do deploy 5 (OK, gravado em pre-deploy6-commit)`, `branch: codex/modernizacao-ui-backend`, `alteracoes locais: 0`. **PARE se:** `PARE: HEAD de producao ...` (o deploy 5 nao terminou com o commit dele: traga o hash curto) ou alteracao local.

```bash
cd /var/www/chronodesk; install -d -o root -g root -m 700 /var/backups/chronodesk; H=$(git rev-parse HEAD); if [ "$H" = "<commit do deploy 5>" ]; then echo "$H" | tee /var/backups/chronodesk/pre-deploy6-commit >/dev/null; echo "HEAD de producao: o commit do deploy 5 (OK, gravado em pre-deploy6-commit)"; else echo "PARE: HEAD de producao $(git rev-parse --short HEAD), nao o commit do deploy 5"; fi; echo "branch: $(git rev-parse --abbrev-ref HEAD)"; echo "alteracoes locais: $(git status --porcelain | wc -l)"; unset H
```

- [ ] **3.** (1) Esperado: antes, troque `<commit validado na estacao>` (item 0). O hash completo impresso (gravado em `deploy6-commit`). **PARE se:** `PARE: origin/... nao e o commit validado na estacao` ou erro do `fetch`.

```bash
cd /var/www/chronodesk; git fetch origin feat/employee-form-rhf-zod && K=$(git rev-parse origin/feat/employee-form-rhf-zod) && if [ "$K" = "<commit validado na estacao>" ]; then echo "$K" | tee /var/backups/chronodesk/deploy6-commit; else echo "PARE: origin/feat/employee-form-rhf-zod ($K) nao e o commit validado na estacao"; fi; unset K
```

- [ ] **4.** (1) Esperado: `fast-forward OK: <N> commits a aplicar` (anote), `migrations alteradas:` com exatamente os quatro arquivos da 024 e `lockfile alterado: 0` (com `1`, anote: o comando 23 faz o `npm ci`). **PARE se:** linha `PARE` ou outra migration na lista.

```bash
cd /var/www/chronodesk; D6=$(cat /var/backups/chronodesk/deploy6-commit); git cat-file -e "$D6^{commit}" && git cat-file -e "$D6:scripts/smoke-deploy1.sh" && git cat-file -e "$D6:migrations/20261117_024_critical_roles.sql" && git merge-base --is-ancestor HEAD "$D6" && echo "fast-forward OK: $(git rev-list --count HEAD.."$D6") commits a aplicar" || echo "PARE: deploy6-commit ausente, sem o smoke-deploy1.sh, sem a 024 ou o HEAD nao e ancestral dele"; echo "migrations alteradas: $(git diff --name-only HEAD "$D6" -- migrations | sort | tr '\n' ' ')"; echo "lockfile alterado: $(git diff --name-only HEAD "$D6" -- frontend/package-lock.json | wc -l)"; unset D6
```

## 2. Ensaio do codigo do commit gravado (em `/tmp`, como `www-data`; nenhum QA toca o banco real)

- [ ] **5.** (2) Esperado: `/tmp/.env ausente: OK`. **PARE se:** `PARE: existe /tmp/.env`.

```bash
[ ! -e /tmp/.env ] && echo "/tmp/.env ausente: OK" || echo "PARE: existe /tmp/.env; o ensaio leria esse arquivo"
```

- [ ] **6.** (2) Esperado: `extraido em /tmp/ensaio-deploy6-qa.<sufixo>`. **PARE se:** `PARE: falha ao extrair o deploy6-commit`.

```bash
cd /var/www/chronodesk; export D6=$(cat /var/backups/chronodesk/deploy6-commit); Q=$(mktemp -d /tmp/ensaio-deploy6-qa.XXXXXX); export Q; bash -o pipefail -c 'git archive "$D6" | tar -x -C "$Q"' && chown -R www-data: "$Q" && echo "extraido em $Q" || echo "PARE: falha ao extrair o deploy6-commit"
```

- [ ] **7.** (2) Esperado: nenhuma linha antes de `fim do php -l`. **PARE se:** qualquer linha antes de `fim do php -l`.

```bash
find "$Q" -name '*.php' -not -path '*/node_modules/*' -exec runuser -u www-data -- php -l {} \; | grep -v '^No syntax errors' ; echo "fim do php -l"
```

- [ ] **8.** (2) Esperado: os 21 QAs com `OK` (os 20 do deploy 5, mais o `qa-critical-roles`). **PARE se:** algum `FALHOU`: antes do comando 9, rode o QA de novo sem o `>/dev/null` e envie so o nome e a ultima linha.

```bash
[ ! -e /tmp/.env ] && for q in qa-smoke qa-auth qa-timezone qa-overtime qa-schedule-history qa-schedule-legacy-output qa-presence-engine qa-absences qa-calendar-text qa-archive qa-presence-cutoff qa-pa-conflicts qa-operational-view qa-employee-lunch qa-pa-distribution qa-paridade-escala qa-rotation-teams qa-critico-hibrido qa-critical-roles qa-security qa-deploy-runbook; do printf '%s: ' "$q"; runuser -u www-data -- php "$Q/scripts/$q.php" >/dev/null 2>&1 && echo OK || echo FALHOU; done
```

- [ ] **9.** (2) Esperado: `removido: /tmp/ensaio-deploy6-qa.<sufixo>` e `ensaios restantes: 0`. **PARE se:** `ensaios restantes` maior que `0`.

```bash
for Q in /tmp/ensaio-deploy6-qa.??????; do [ -d "$Q" ] && rm -r -- "$Q" && echo "removido: $Q"; done; echo "ensaios restantes: $(ls -d /tmp/ensaio-deploy6-qa.?????? 2>/dev/null | wc -l)"; unset Q D6
```

## 3. Q-PC e ensaio da 024 no MySQL do servidor (Q-PC so leitura; ensaio em banco descartavel `ensaio_*`; portal no ar)

- [ ] **10.** (3, Q-PC) Esperado: pede a senha do root do MySQL (sem eco). `tabelas_024 = 0`, `checks_024 = 0`, `copias_de_rollback_024 = 0`, "024 ausente" (anote os cadastros do Critico por situacao e as linhas das assinaturas). **PARE se:** erro na consulta: envie so as contagens.

```bash
cd /var/www/chronodesk; export D6=$(cat /var/backups/chronodesk/deploy6-commit); read -rsp 'Senha root do MySQL: ' P; echo; export P; bash -o pipefail -c 'git show "$D6:migrations/verificacao/20261117_024_critical_roles_check.sql" | docker exec -i -e MYSQL_PWD="$P" Chrono_Desk_DB mysql -uroot --table sistema_pausas'; unset P D6
```

- [ ] **11.** (3) Esperado: `extraido em /root/ensaio-deploy6.<sufixo>`. **PARE se:** `PARE: falha ao extrair as migrations do deploy6-commit`.

```bash
cd /var/www/chronodesk; export D6=$(cat /var/backups/chronodesk/deploy6-commit); EV=$(mktemp -d /root/ensaio-deploy6.XXXXXX); export EV; git cat-file -e "$D6^{commit}" && bash -o pipefail -c 'git archive "$D6" migrations scripts/ensaio-migration.sh | tar -x -C "$EV"' && echo "extraido em $EV" || echo "PARE: falha ao extrair as migrations do deploy6-commit"; unset D6
```

- [ ] **12.** (3, ensaio a) Esperado: pede a senha do root do MySQL (sem eco). Migration 2x `OK` (`tabelas_024 = 3`, `checks_024 = 6`, `papeis = 0`, `rodizios = 0`, `trocas = 0`); rollback 1a vez `OK` (`tabelas_024 = 0`, `copias_de_rollback_024 = 3`, "024 ausente"); a 2a vez para de proposito (`ja existe copia *_bkp_024`); assinaturas iguais nos cinco estados; `Removido.`. **PARE se:** `FALHOU` em etapa que nao seja o 2o rollback, ou assinatura diferente: envie so as contagens.

```bash
env DADOS=portal_schedule_rules,portal_schedule_exceptions VERIFICA="$EV/migrations/verificacao/20261117_024_critical_roles_check.sql" bash "$EV/scripts/ensaio-migration.sh" d6_024 "$EV/migrations/20261117_024_critical_roles.sql" "$EV/migrations/rollback/20261117_024_critical_roles_down.sql" portal_pa_assignments,portal_absences,portal_shift_attachments,portal_overtime_entries,portal_time_adjustments,portal_oncall_shifts,audit_log
```

- [ ] **13.** (3, ensaio b) Esperado: pede a senha. Depois das linhas, `papeis = 2`, `rodizios = 1`, `trocas = 1` e as contagens de problema em 0; o 1o rollback `OK` (`copias_de_rollback_024 = 3`) e o 2o para de proposito; a assinatura de `regras` igual nos cinco estados (a de `cadastros` muda se a copia nao tinha Critico ativo: as linhas marcam um N2); `Removido.`. **PARE se:** migration com `FALHOU`, contagem de problema maior que 0 ou 1o rollback que falha: envie so as contagens.

```bash
env PREPARO="$EV/migrations/20261117_024_critical_roles.sql" DADOS=portal_schedule_rules,portal_schedule_exceptions VERIFICA="$EV/migrations/verificacao/20261117_024_critical_roles_check.sql" bash "$EV/scripts/ensaio-migration.sh" d6_024_rollback "$EV/migrations/rollback/20261117_024_ensaio_linhas_pc.sql" "$EV/migrations/rollback/20261117_024_critical_roles_down.sql" portal_pa_assignments,portal_absences,portal_shift_attachments,portal_overtime_entries,portal_time_adjustments,portal_oncall_shifts,audit_log
```

- [ ] **14.** (3) Esperado: `removido: /root/ensaio-deploy6.<sufixo>`. **PARE se:** `PARE: diretorio do ensaio inesperado`.

```bash
case "$EV" in /root/ensaio-deploy6.*) rm -r "$EV" && echo "removido: $EV";; *) echo "PARE: diretorio do ensaio inesperado";; esac; unset EV
```

## 4. Espaco livre e backup rapido (portal no ar)

- [ ] **15.** (4) Esperado: seis linhas `OK` e `espaco livre: OK` (anote os livres). **PARE se:** qualquer `BAIXO` ou `PARE: espaco livre abaixo do minimo`.

```bash
cd /var/www/chronodesk/frontend; NPMC=$(npm config get cache); test -e "$NPMC" || NPMC=$(dirname "$NPMC"); DROOT=$(docker info --format '{{.DockerRootDir}}'); ESP=OK; for LIM in "/var/www/chronodesk:500" "$NPMC:200" "/var/backups:100" "$DROOT:200" "/tmp:50" "/root:50"; do DIR=${LIM%:*}; MIN=${LIM##*:}; LIVRE=$(df -BM --output=avail "$DIR" | tail -n 1 | tr -dc '0-9'); if [ "${LIVRE:-0}" -ge "$MIN" ]; then echo "OK $DIR (disco $(df --output=target "$DIR" | tail -n 1)): $LIVRE MB livres, minimo $MIN"; else echo "BAIXO $DIR: ${LIVRE:-?} MB livres, minimo $MIN"; ESP=PARE; fi; done; [ "$ESP" = OK ] && echo "espaco livre: OK" || echo "PARE: espaco livre abaixo do minimo"; unset NPMC DROOT ESP LIM DIR MIN LIVRE
```

- [ ] **16.** (4) Esperado: pede a senha. `backup concluido: /var/backups/chronodesk/deploy6-<data> (<tamanho>)` (anote). **PARE se:** `PARE: backup falhou`.

```bash
B=/var/backups/chronodesk/deploy6-$(date +%Y%m%d-%H%M%S); export B; read -rsp 'Senha root do MySQL: ' P; echo; export P; install -d -o root -g root -m 700 "$B" && bash -o pipefail -c 'docker exec -i -e MYSQL_PWD="$P" Chrono_Desk_DB mysqldump -uroot --single-transaction --routines --triggers --no-tablespaces --set-gtid-purged=OFF sistema_pausas | gzip | tee "$B/database.sql.gz" > /dev/null' && gzip -t "$B/database.sql.gz" && tar -czf "$B/estado.tar.gz" -C /var/www/chronodesk --ignore-failed-read estado.json pausas.csv config_sistema.json funcionarios.json && sh -c 'cd "$1" && sha256sum database.sql.gz estado.tar.gz > SHA256SUMS' _ "$B" && echo "backup concluido: $B ($(du -sh "$B" | cut -f1))" || echo "PARE: backup falhou (sem ponto de restauracao, o deploy nao segue)"; unset P
```

## 5. Manutencao e copia do `app/` (o portal fica em 503 ate o comando 30)

- [ ] **17.** (5) Esperado: `arquivo de manutencao: presente`. **PARE se:** `AUSENTE`.

```bash
touch /var/www/chronodesk.maintenance; echo "arquivo de manutencao: $(test -e /var/www/chronodesk.maintenance && echo presente || echo AUSENTE)"
```

- [ ] **18.** (5) Esperado: `copia do app/: /var/backups/chronodesk/app-antes-deploy6 (...)` (anote tamanho e arquivos). **PARE se:** `PARE: ...` (copia diferente ou que falhou).

```bash
cd /var/www/chronodesk; A=/var/backups/chronodesk/app-antes-deploy6; if test -e "$A"; then diff -rq app "$A" && echo "copia do app/ ja existe e e igual: $A" || echo "PARE: $A existe e e diferente do app/ atual"; else cp -a app "$A" && diff -rq app "$A" && echo "copia do app/: $A ($(du -sh "$A" | cut -f1); $(find "$A" -type f | wc -l) arquivos)" || echo "PARE: copia do app/ falhou"; fi; unset A
```

- [ ] **19.** (5) Esperado: `portal visto de fora (manutencao): 503`. **PARE se:** a estacao ve a tela do portal (tire o arquivo-sinal com o comando 30 e avise).

```bash
S=$(awk 'tolower($1) == "servername" {print $2; exit}' /etc/apache2/sites-enabled/chronodesk.conf); if [ -n "$S" ]; then curl -s -o /dev/null -w 'portal visto de fora (manutencao): %{http_code}\n' "http://$S/"; else echo "PARE: ServerName nao encontrado no chronodesk.conf"; fi; unset S
```

## 6. Migration 024, antes do merge, e a verificacao

- [ ] **20.** (6) Esperado: pede a senha. `024 aplicada` (so cria tabelas vazias: menos de 1 s). **PARE se:** `PARE: a 024 nao foi aplicada`: fique em manutencao (o codigo ainda e o do deploy 5).

```bash
cd /var/www/chronodesk; export D6=$(cat /var/backups/chronodesk/deploy6-commit); read -rsp 'Senha root do MySQL: ' P; echo; export P; git cat-file -e "$D6:migrations/20261117_024_critical_roles.sql" && bash -o pipefail -c 'git show "$D6:migrations/20261117_024_critical_roles.sql" | docker exec -i -e MYSQL_PWD="$P" Chrono_Desk_DB mysql -uroot sistema_pausas' && echo "024 aplicada" || echo "PARE: a 024 nao foi aplicada"; unset P D6
```

- [ ] **21.** (6) Esperado: `tabelas_024 = 3`, `checks_024 = 6`, `copias_de_rollback_024 = 0`, `papeis = 0`, `rodizios = 0`, `trocas = 0`, contagens de problema em 0, assinaturas iguais as do comando 10. **PARE se:** outro numero: fique em manutencao e avise.

```bash
cd /var/www/chronodesk; export D6=$(cat /var/backups/chronodesk/deploy6-commit); read -rsp 'Senha root do MySQL: ' P; echo; export P; bash -o pipefail -c 'git show "$D6:migrations/verificacao/20261117_024_critical_roles_check.sql" | docker exec -i -e MYSQL_PWD="$P" Chrono_Desk_DB mysql -uroot --table sistema_pausas'; unset P D6
```

## 7. `git merge --ff-only`, build e o QA do bundle

- [ ] **22.** (7) Esperado: o `merge` em fast-forward, `HEAD = deploy6-commit` e `alteracoes locais: 0`. **PARE se:** `PARE: HEAD diferente do deploy6-commit` ou alteracao local.

```bash
cd /var/www/chronodesk; D6=$(cat /var/backups/chronodesk/deploy6-commit); git merge --ff-only "$D6"; [ "$(git rev-parse HEAD)" = "$D6" ] && echo "HEAD = deploy6-commit" || echo "PARE: HEAD diferente do deploy6-commit"; echo "alteracoes locais: $(git status --porcelain | wc -l)"; unset D6
```

- [ ] **23.** (7) Esperado: `lockfile sem mudanca: so o build` e o build sem erro. **PARE se:** as mesmas regras do comando 22 do deploy 4 (espaco, rede, erro do build): rollback, com aprovacao.

```bash
cd /var/www/chronodesk/frontend && if [ "$(git diff --name-only "$(cat /var/backups/chronodesk/pre-deploy6-commit)" HEAD -- package-lock.json | wc -l)" = 0 ]; then echo "lockfile sem mudanca: so o build"; npm run build; else echo "lockfile alterado: npm ci e build"; npm ci && npm run build; fi
```

- [ ] **24.** (7) Esperado: `Bundle frontend QA OK (...)` com o CSS abaixo de 70000 bytes (68333 com o rodizio). **PARE se:** falha do `qa:bundle`: rollback, com aprovacao.

```bash
cd /var/www/chronodesk/frontend && npm run -s qa:bundle
```

## 8. Recarga, health, fuso e teste de fumaca (ainda em manutencao)

- [ ] **25.** (8) Esperado: nenhuma saida. **PARE se:** erro na recarga.

```bash
systemctl reload "php$(php -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;')-fpm"
```

- [ ] **26.** (8) Esperado: `health: 200`. **PARE se:** diferente de `200`: rollback, com aprovacao.

```bash
S=$(awk 'tolower($1) == "servername" {print $2; exit}' /etc/apache2/sites-enabled/chronodesk.conf); if [ -n "$S" ]; then curl -s -o /dev/null -w 'health: %{http_code}\n' -H "Host: $S" http://127.0.0.1/api/health.php; else echo "PARE: ServerName nao encontrado no chronodesk.conf"; fi; unset S
```

- [ ] **27.** (8) Esperado: `sessao MySQL em America/Sao_Paulo` e `QA timezone (banco real) OK`. **PARE se:** falha: rollback, com aprovacao.

```bash
cd /var/www/chronodesk; runuser -u www-data -- php scripts/qa-timezone.php --db
```

- [ ] **28.** (8, teste de fumaca) Esperado: o mesmo do comando 27 do deploy 4, terminando em `FUMACA OK`. **PARE se:** qualquer `PARE`: nao reabra; rollback, com aprovacao.

```bash
cd /var/www/chronodesk; S=$(awk 'tolower($1) == "servername" {print $2; exit}' /etc/apache2/sites-enabled/chronodesk.conf); if [ -n "$S" ]; then env PHPLOG=/var/log/chronodesk/php_error.log bash scripts/smoke-deploy1.sh "$S"; else echo "PARE: ServerName nao encontrado no chronodesk.conf"; fi; unset S
```

## 9. Conferencia no banco (so leitura)

- [ ] **29.** (9) Esperado: os mesmos numeros do comando 21. **PARE se:** numero diferente.

```bash
cd /var/www/chronodesk; export D6=$(cat /var/backups/chronodesk/deploy6-commit); read -rsp 'Senha root do MySQL: ' P; echo; export P; bash -o pipefail -c 'git show "$D6:migrations/verificacao/20261117_024_critical_roles_check.sql" | docker exec -i -e MYSQL_PWD="$P" Chrono_Desk_DB mysql -uroot --table sistema_pausas'; unset P D6
```

## 10. Reabertura e conferencia na tela

- [ ] **30.** (10) Esperado: so com os comandos 26 a 28 como esperado. `manutencao encerrada` e `portal visto de fora: 302`. Na tela, como admin (Ctrl+F5), sem salvar: **Escala** com a secao "Papeis do Critico" depois dos times de rodizio ("Nenhum rodizio vigente: a jornada de cada um vem do cadastro." ou "Sem pessoas no Critico"; "Papeis e rodizio" abre o painel com as listas vazias, feche sem salvar); **Visao operacional** sem o alerta "Papel vago no Critico hoje"; **Pausas** como antes. **PARE se:** `PARE`, outro codigo, ou tela em branco ou quebrada: avise.

```bash
[ -e /var/www/chronodesk.maintenance ] && rm -- /var/www/chronodesk.maintenance; [ -e /var/www/chronodesk.maintenance ] && echo "PARE: arquivo de manutencao ainda presente" || echo "manutencao encerrada"; S=$(awk 'tolower($1) == "servername" {print $2; exit}' /etc/apache2/sites-enabled/chronodesk.conf); if [ -n "$S" ]; then curl -s -o /dev/null -w 'portal visto de fora: %{http_code}\n' "http://$S/"; else echo "PARE: ServerName nao encontrado no chronodesk.conf"; fi; unset S
```

## 11. Medicao do polling (alguns minutos depois da reabertura; 2 rodadas por endpoint, valendo a melhor; sessao de gestao)

- [ ] **31.** (11) Esperado: p50 e p95 do `status.php`, na melhor de 2 rodadas (anote). **PARE se:** p95 mais de 50 ms **e** mais de 20% acima da base do deploy 5: meca de novo e avise.

```bash
cd /var/www/chronodesk; bash scripts/medir-polling.sh
```

- [ ] **32.** (11) Esperado: p50 e p95 do `dashboard.php`, na melhor de 2 rodadas (anote). **PARE se:** p95 mais de 50 ms **e** mais de 20% acima da base do deploy 5: meca de novo e avise.

```bash
cd /var/www/chronodesk; URL=http://127.0.0.1/api/portal/dashboard.php bash scripts/medir-polling.sh
```

- [ ] **33.** (11) Esperado: p50 e p95 do `solicitacoes_pendentes.php`, na melhor de 2 rodadas (anote). **PARE se:** p95 mais de 50 ms **e** mais de 20% acima da base do deploy 5: meca de novo e avise.

```bash
cd /var/www/chronodesk; URL=http://127.0.0.1/api/solicitacoes_pendentes.php bash scripts/medir-polling.sh
```

## 12. Rollback de codigo (so se preciso, com aprovacao do responsavel)

- [ ] **34.** (12) Esperado: `codigo anterior no ar: <hash curto do deploy 5>`. **PARE se:** sem a mensagem.

```bash
cd /var/www/chronodesk; git checkout --detach "$(cat /var/backups/chronodesk/pre-deploy6-commit)" && echo "codigo anterior no ar: $(git rev-parse --short HEAD)"
```

- [ ] **35.** (12) Esperado: `app/ anterior de volta (o build retirado esta em ...)`. **PARE se:** `PARE: ...`: fique em manutencao e avise.

```bash
cd /var/www/chronodesk; A=/var/backups/chronodesk/app-antes-deploy6; X=/var/backups/chronodesk/app-deploy6-retirado-$(date +%Y%m%d-%H%M%S); if test -d "$A"; then { ! test -e app || mv app "$X"; } && cp -a "$A" app && diff -rq "$A" app && echo "app/ anterior de volta (o build retirado esta em $X)" || echo "PARE: a volta do app/ falhou"; else echo "PARE: copia $A ausente; nada mudou no app/"; fi; unset A X
```

- [ ] **36.** (12) Esperado: nenhuma saida. Depois: comandos 26 a 28 e a reabertura (30). **PARE se:** erro na recarga.

```bash
systemctl reload "php$(php -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;')-fpm"
```

## 13. Rollback da 024 (so depois do 12 e so com aprovacao)

- [ ] **37.** (13, so com aprovacao) Esperado: pede a senha. `rollback da 024 aplicado` (so com o codigo do deploy 5 no ar; os papeis, o rodizio e as trocas vao para as copias `*_bkp_024`). **PARE se:** `PARE: o codigo no ar nao e o do deploy 5 ...` ou `rollback_024 abortado: ja existe copia *_bkp_024` (confira e renomeie as copias antes): avise.

```bash
cd /var/www/chronodesk; export D6=$(cat /var/backups/chronodesk/deploy6-commit); read -rsp 'Senha root do MySQL: ' P; echo; export P; [ "$(git rev-parse HEAD)" = "$(cat /var/backups/chronodesk/pre-deploy6-commit)" ] && git cat-file -e "$D6:migrations/rollback/20261117_024_critical_roles_down.sql" && bash -o pipefail -c 'git show "$D6:migrations/rollback/20261117_024_critical_roles_down.sql" | docker exec -i -e MYSQL_PWD="$P" Chrono_Desk_DB mysql -uroot sistema_pausas' && echo "rollback da 024 aplicado" || echo "PARE: o codigo no ar nao e o do deploy 5 ou o rollback da 024 falhou"; unset P D6
```

- [ ] **38.** (13) Esperado: `tabelas_024 = 0`, `copias_de_rollback_024 = 3`, "024 ausente" e as assinaturas iguais as do comando 21. **PARE se:** outro numero: avise.

```bash
cd /var/www/chronodesk; export D6=$(cat /var/backups/chronodesk/deploy6-commit); read -rsp 'Senha root do MySQL: ' P; echo; export P; bash -o pipefail -c 'git show "$D6:migrations/verificacao/20261117_024_critical_roles_check.sql" | docker exec -i -e MYSQL_PWD="$P" Chrono_Desk_DB mysql -uroot --table sistema_pausas'; unset P D6
```

## Depois de um ciclo estavel, com o responsavel

- [ ] Push do commit validado para o `codex/modernizacao-ui-backend` (como depois dos deploys 1 a 5; nunca `--force`).
- [ ] Limpeza com aprovacao em `/var/backups/chronodesk`: `deploy6-<data>` (dados pessoais), `app-antes-deploy6`, `pre-deploy6-commit` e `deploy6-commit` (listar antes so nome, modo, dono e tamanho).
- [ ] A Lideranca cadastra pela tela os papeis, o rodizio (com a previa) e as trocas; quem fica com qual papel nunca vai para o git nem para o chat; a verificacao da 024 conta.
