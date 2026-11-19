# Deploy 2 da Parte B: folha de execucao do dia

Gerada do roteiro "Deploy 2 da Parte B (pos-deploy 1 ...)" do `DEPLOY_LINUX.md`: os mesmos comandos, na mesma ordem (a trava `scripts/qa-deploy-runbook.php` confere). O roteiro e a referencia: o detalhe de cada esperado esta la. Mudou o roteiro, muda esta folha no mesmo commit.

**O que vai:** A, A2b, B, E, D e F do pos-deploy 1 (desenho 9.14), so codigo, **sem migration**; o lockfile mudou (`vite`, `zod`): `npm ci` obrigatorio. Producao esta no `2e27926` desde 10/11/2026.

**Shell root, sem `sudo`.** Abra o terminal da VM com `sudo -i` e rode **todos** os comandos desta folha nele, como estao (nenhum leva `sudo`; o que roda como `www-data` usa `runuser`). O comando 1 e a conferencia do ambiente. Terminal reaberto: `sudo -i` e o comando 1 de novo (os comandos leem o commit e o `ServerName` de arquivos, nao de variaveis antigas).

**Sem valor real nesta folha**, nem impressa nem anotada nela: nada de senha, IP, hostname, nome ou login. O `ServerName` e lido do `chronodesk.conf` na hora. Todo `curl` usa `http://` com o `ServerName` ou o loopback com o `Host`: o `https` da VM responde `200` de outro site. A senha do root do MySQL e sempre digitada no `read -rsp` (sem eco). Numeros anotados vao para o desenho (9.14), nunca para chat ou chamado.

**Regras de parada em toda a folha:** qualquer linha com `PARE`, ou saida diferente do esperado: pare e envie so a contagem ou a mensagem. Nada de `git pull`; o unico `git fetch` e o do comando 3.

## 0. Antes do dia, na estacao

- [ ] **0.** O responsavel publica o `feat/employee-form-rhf-zod`. Depois, a bateria completa sozinha (nada pesado em paralelo): `git fetch origin; git worktree add --detach ..\chronodesk-deploy2 origin/feat/employee-form-rhf-zod; Set-Location ..\chronodesk-deploy2\frontend; npm ci; Set-Location ..; $env:CHROME_PATH='C:\Program Files\Google\Chrome\Application\chrome.exe'; powershell -NoProfile -ExecutionPolicy Bypass -File scripts\test-local.ps1 -SkipHttp -Browser; git rev-parse HEAD`. Esperado: `Validacao local concluida com sucesso`, `frontend: 22 scripts npm rodados (...): 22 OK, 0 com falha`, navegador com `falharam: 0` e o hash completo: anote como `<commit validado na estacao>`. Qualquer falha: nao ha deploy com esse commit.

## 1. Conferencia do ambiente, commit validado e producao no `2e27926`

- [ ] **1.** (1, ambiente) Esperado: antes, `sudo -i`. Nove linhas `OK` (com o `umask 0022`) e `ambiente: OK`. **PARE se:** qualquer `BLOQUEADO` ou `PARE: ambiente com BLOQUEADO`.

```bash
R=OK; [ "$(id -u)" = 0 ] && echo "OK id -u = 0" || { echo "BLOQUEADO id -u (o shell nao e root)"; R=PARE; }; bash -c 'exit 0' && echo "OK bash -c" || { echo "BLOQUEADO bash -c"; R=PARE; }; tar -czf - -C /etc hostname 2>/dev/null | tar -tzf - >/dev/null 2>&1 && echo "OK tar com gzip" || { echo "BLOQUEADO tar com gzip"; R=PARE; }; [ "$(find /etc/hostname -maxdepth 0 -exec echo ok \; 2>/dev/null)" = ok ] && echo "OK find -exec" || { echo "BLOQUEADO find -exec"; R=PARE; }; runuser -u www-data -- php -v >/dev/null 2>&1 && echo "OK runuser -u www-data -- php -v" || { echo "BLOQUEADO runuser -u www-data -- php -v"; R=PARE; }; [ "$(runuser -u www-data -- php -r '$p = proc_open([PHP_BINARY, "-v"], [1 => ["pipe", "w"]], $x); if (!is_resource($p)) { exit(1); } stream_get_contents($x[1]); echo proc_close($p) === 0 ? "ok" : "falha";' 2>/dev/null)" = ok ] && echo "OK php filho como www-data (proc_open, como no qa-security)" || { echo "BLOQUEADO php filho como www-data"; R=PARE; }; npm -v >/dev/null 2>&1 && echo "OK npm -v" || { echo "BLOQUEADO npm -v"; R=PARE; }; git -C /var/www/chronodesk rev-parse --git-dir >/dev/null 2>&1 && echo "OK git no clone" || { echo "BLOQUEADO git no clone"; R=PARE; }; umask 022 && [ "$(umask)" = 0022 ] && echo "OK umask $(umask) (arquivos novos legiveis pelo www-data)" || { echo "BLOQUEADO umask $(umask)"; R=PARE; }; [ "$R" = OK ] && echo "ambiente: OK" || echo "PARE: ambiente com BLOQUEADO"; unset R
```

- [ ] **2.** (1) Esperado: `HEAD de producao: 2e27926 (OK, gravado em pre-deploy2-commit)`, `branch: codex/modernizacao-ui-backend`, `alteracoes locais: 0`. **PARE se:** `PARE: HEAD de producao ...` (traga o hash curto) ou alteracao local.

```bash
cd /var/www/chronodesk; install -d -o root -g root -m 700 /var/backups/chronodesk; H=$(git rev-parse HEAD); if [ "$H" = "2e27926ebdd14c40e6bb173938e767a774fe640a" ]; then echo "$H" | tee /var/backups/chronodesk/pre-deploy2-commit >/dev/null; echo "HEAD de producao: 2e27926 (OK, gravado em pre-deploy2-commit)"; else echo "PARE: HEAD de producao $(git rev-parse --short HEAD), nao o 2e27926"; fi; echo "branch: $(git rev-parse --abbrev-ref HEAD)"; echo "alteracoes locais: $(git status --porcelain | wc -l)"; unset H
```

- [ ] **3.** (1) Esperado: antes, troque `<commit validado na estacao>` (item 0). O hash completo impresso (gravado em `deploy2-commit`). **PARE se:** `PARE: origin/... nao e o commit validado na estacao` ou erro do `fetch`.

```bash
cd /var/www/chronodesk; git fetch origin feat/employee-form-rhf-zod && K=$(git rev-parse origin/feat/employee-form-rhf-zod) && if [ "$K" = "<commit validado na estacao>" ]; then echo "$K" | tee /var/backups/chronodesk/deploy2-commit; else echo "PARE: origin/feat/employee-form-rhf-zod ($K) nao e o commit validado na estacao"; fi; unset K
```

- [ ] **4.** (1) Esperado: `fast-forward OK: <N> commits a aplicar` (anote), `migrations alteradas: 0`, `lockfile alterado: 1`. **PARE se:** linha `PARE` ou `migrations alteradas` maior que `0` (este roteiro nao aplica migration).

```bash
cd /var/www/chronodesk; D2=$(cat /var/backups/chronodesk/deploy2-commit); git cat-file -e "$D2^{commit}" && git cat-file -e "$D2:scripts/smoke-deploy1.sh" && git merge-base --is-ancestor HEAD "$D2" && echo "fast-forward OK: $(git rev-list --count HEAD.."$D2") commits a aplicar" || echo "PARE: deploy2-commit ausente, sem o smoke-deploy1.sh ou o HEAD nao e ancestral dele"; echo "migrations alteradas: $(git diff --name-only HEAD "$D2" -- migrations | wc -l)"; echo "lockfile alterado: $(git diff --name-only HEAD "$D2" -- frontend/package-lock.json | wc -l)"; unset D2
```

## 2. Ensaio do codigo do commit gravado (em `/tmp`, como `www-data`; nenhum QA toca o banco real)

- [ ] **5.** (2) Esperado: `/tmp/.env ausente: OK`. **PARE se:** `PARE: existe /tmp/.env`.

```bash
[ ! -e /tmp/.env ] && echo "/tmp/.env ausente: OK" || echo "PARE: existe /tmp/.env; o ensaio leria esse arquivo"
```

- [ ] **6.** (2) Esperado: `extraido em /tmp/ensaio-deploy2-qa.<sufixo>`. **PARE se:** `PARE: falha ao extrair o deploy2-commit`.

```bash
cd /var/www/chronodesk; export D2=$(cat /var/backups/chronodesk/deploy2-commit); Q=$(mktemp -d /tmp/ensaio-deploy2-qa.XXXXXX); export Q; bash -o pipefail -c 'git archive "$D2" | tar -x -C "$Q"' && chown -R www-data: "$Q" && echo "extraido em $Q" || echo "PARE: falha ao extrair o deploy2-commit"
```

- [ ] **7.** (2) Esperado: nenhuma linha antes de `fim do php -l`. **PARE se:** qualquer linha antes de `fim do php -l`.

```bash
find "$Q" -name '*.php' -not -path '*/node_modules/*' -exec runuser -u www-data -- php -l {} \; | grep -v '^No syntax errors' ; echo "fim do php -l"
```

- [ ] **8.** (2) Esperado: os 16 QAs com `OK`; o `qa-security` **sem contorno** (o falso negativo do deploy 1 foi corrigido no `28fb746`). **PARE se:** algum `FALHOU`: antes do comando 9, rode o QA de novo sem o `>/dev/null` e envie so o nome e a ultima linha.

```bash
[ ! -e /tmp/.env ] && for q in qa-smoke qa-auth qa-timezone qa-overtime qa-schedule-history qa-schedule-legacy-output qa-presence-engine qa-absences qa-calendar-text qa-archive qa-presence-cutoff qa-pa-conflicts qa-operational-view qa-employee-lunch qa-security qa-deploy-runbook; do printf '%s: ' "$q"; runuser -u www-data -- php "$Q/scripts/$q.php" >/dev/null 2>&1 && echo OK || echo FALHOU; done
```

- [ ] **9.** (2) Esperado: `removido: /tmp/ensaio-deploy2-qa.<sufixo>` e `ensaios restantes: 0`. **PARE se:** `ensaios restantes` maior que `0`.

```bash
for Q in /tmp/ensaio-deploy2-qa.??????; do [ -d "$Q" ] && rm -r -- "$Q" && echo "removido: $Q"; done; echo "ensaios restantes: $(ls -d /tmp/ensaio-deploy2-qa.?????? 2>/dev/null | wc -l)"; unset Q D2
```

## 3. Espaco livre e backup rapido (portal no ar)

- [ ] **10.** (3) Esperado: seis linhas `OK` e `espaco livre: OK` (anote os livres). **PARE se:** qualquer `BAIXO` ou `PARE: espaco livre abaixo do minimo` (liberar espaco e da infraestrutura).

```bash
cd /var/www/chronodesk/frontend; NPMC=$(npm config get cache); test -e "$NPMC" || NPMC=$(dirname "$NPMC"); DROOT=$(docker info --format '{{.DockerRootDir}}'); ESP=OK; for LIM in "/var/www/chronodesk:500" "$NPMC:200" "/var/backups:100" "$DROOT:200" "/tmp:50" "/root:50"; do DIR=${LIM%:*}; MIN=${LIM##*:}; LIVRE=$(df -BM --output=avail "$DIR" | tail -n 1 | tr -dc '0-9'); if [ "${LIVRE:-0}" -ge "$MIN" ]; then echo "OK $DIR (disco $(df --output=target "$DIR" | tail -n 1)): $LIVRE MB livres, minimo $MIN"; else echo "BAIXO $DIR: ${LIVRE:-?} MB livres, minimo $MIN"; ESP=PARE; fi; done; [ "$ESP" = OK ] && echo "espaco livre: OK" || echo "PARE: espaco livre abaixo do minimo"; unset NPMC DROOT ESP LIM DIR MIN LIVRE
```

- [ ] **11.** (3) Esperado: `backup concluido: /var/backups/chronodesk/deploy2-<data> (<tamanho>)` (anote; o do deploy 1 tinha 32K). **PARE se:** `PARE: backup falhou`.

```bash
B=/var/backups/chronodesk/deploy2-$(date +%Y%m%d-%H%M%S); export B; read -rsp 'Senha root do MySQL: ' P; echo; export P; install -d -o root -g root -m 700 "$B" && bash -o pipefail -c 'docker exec -i -e MYSQL_PWD="$P" Chrono_Desk_DB mysqldump -uroot --single-transaction --routines --triggers --no-tablespaces --set-gtid-purged=OFF sistema_pausas | gzip | tee "$B/database.sql.gz" > /dev/null' && gzip -t "$B/database.sql.gz" && tar -czf "$B/estado.tar.gz" -C /var/www/chronodesk --ignore-failed-read estado.json pausas.csv config_sistema.json funcionarios.json && sh -c 'cd "$1" && sha256sum database.sql.gz estado.tar.gz > SHA256SUMS' _ "$B" && echo "backup concluido: $B ($(du -sh "$B" | cut -f1))" || echo "PARE: backup falhou (sem ponto de restauracao, o deploy nao segue)"; unset P
```

## 4. Manutencao e copia do `app/` (o portal fica em 503 ate o comando 23)

- [ ] **12.** (4) Esperado: `arquivo de manutencao: presente`. **PARE se:** `AUSENTE`.

```bash
touch /var/www/chronodesk.maintenance; echo "arquivo de manutencao: $(test -e /var/www/chronodesk.maintenance && echo presente || echo AUSENTE)"
```

- [ ] **13.** (4) Esperado: `copia do app/: /var/backups/chronodesk/app-antes-deploy2 (...)` (anote tamanho e arquivos). **PARE se:** `PARE: ...` (tire o arquivo-sinal com o comando 23 e avise).

```bash
cd /var/www/chronodesk; A=/var/backups/chronodesk/app-antes-deploy2; if test -e "$A"; then diff -rq app "$A" && echo "copia do app/ ja existe e e igual: $A" || echo "PARE: $A existe e e diferente do app/ atual"; else cp -a app "$A" && diff -rq app "$A" && echo "copia do app/: $A ($(du -sh "$A" | cut -f1); $(find "$A" -type f | wc -l) arquivos)" || echo "PARE: copia do app/ falhou"; fi; unset A
```

- [ ] **14.** (4) Esperado: `portal visto de fora (manutencao): 503` (sem `503`: confira de uma estacao que aparece "ChronoDesk temporariamente indisponivel."; o `ServerName` pode resolver para o loopback na VM). **PARE se:** a estacao ve a tela do portal (tire o arquivo-sinal com o comando 23 e avise).

```bash
S=$(awk 'tolower($1) == "servername" {print $2; exit}' /etc/apache2/sites-enabled/chronodesk.conf); if [ -n "$S" ]; then curl -s -o /dev/null -w 'portal visto de fora (manutencao): %{http_code}\n' "http://$S/"; else echo "PARE: ServerName nao encontrado no chronodesk.conf"; fi; unset S
```

## 5. `git merge --ff-only` do commit gravado

- [ ] **15.** (5) Esperado: o `merge` em fast-forward, `HEAD = deploy2-commit` e `alteracoes locais: 0`. **PARE se:** `PARE: HEAD diferente do deploy2-commit` (fique em manutencao; nada de `fetch` nem `pull`).

```bash
cd /var/www/chronodesk; D2=$(cat /var/backups/chronodesk/deploy2-commit); git merge --ff-only "$D2"; [ "$(git rev-parse HEAD)" = "$D2" ] && echo "HEAD = deploy2-commit" || echo "PARE: HEAD diferente do deploy2-commit"; echo "alteracoes locais: $(git status --porcelain | wc -l)"; unset D2
```

## 6. Build do frontend (lockfile mudou: `npm ci`)

- [ ] **16.** (6) Esperado: build sem erro (o do deploy 1 levou 880 ms). **PARE se:** falha por falta de espaco (`ENOSPC`) ou de rede (`ETIMEDOUT`, `ECONNRESET`, `ECONNREFUSED`, `EAI_AGAIN`, `ENOTFOUND`, `E403`, `E404`, `E5xx`, `EINTEGRITY`): fique em manutencao, sem recarga, e avise a infraestrutura; resolvido, repita uma vez; senao, rollback (comandos 26 a 28, o `app/` volta da copia, sem `npm`); outra falha no build: rollback, com aprovacao.

```bash
cd /var/www/chronodesk/frontend && npm ci && npm run build
```

- [ ] **17.** (6) Esperado: `sem leitura para o www-data: 0 (arquivos do deploy e app/)` (licao do deploy 4: com `umask 077` no shell, o merge e o build gravaram arquivos com 600/700 e o portal parou). **PARE se:** `PARE: ... sem leitura para o www-data` ou `PARE: pre-deploy2-commit ausente`: fique em manutencao, nao reabra e avise.

```bash
cd /var/www/chronodesk; PRE=$(cat /var/backups/chronodesk/pre-deploy2-commit); if git cat-file -e "$PRE^{commit}" 2>/dev/null; then N=$( { git diff -z --name-only --diff-filter=d "$PRE" HEAD; find app -print0; } | runuser -u www-data -- xargs -0 -r sh -c 'for f; do if [ -d "$f" ]; then { [ -r "$f" ] && [ -x "$f" ]; } || echo x; else [ -r "$f" ] || echo x; fi; done' _ | wc -l); [ "$N" = 0 ] && echo "sem leitura para o www-data: 0 (arquivos do deploy e app/)" || echo "PARE: $N arquivos ou diretorios do deploy ou do app/ sem leitura para o www-data"; else echo "PARE: pre-deploy2-commit ausente"; fi; unset PRE N
```

## 7. Recarga, health, fuso e teste de fumaca (ainda em manutencao)

- [ ] **18.** (7) Esperado: nenhuma saida. **PARE se:** erro na recarga.

```bash
systemctl reload "php$(php -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;')-fpm"
```

- [ ] **19.** (7) Esperado: `health: 200`. **PARE se:** diferente de `200`: rollback, com aprovacao.

```bash
S=$(awk 'tolower($1) == "servername" {print $2; exit}' /etc/apache2/sites-enabled/chronodesk.conf); if [ -n "$S" ]; then curl -s -o /dev/null -w 'health: %{http_code}\n' -H "Host: $S" http://127.0.0.1/api/health.php; else echo "PARE: ServerName nao encontrado no chronodesk.conf"; fi; unset S
```

- [ ] **20.** (7) Esperado: `sessao MySQL em America/Sao_Paulo` e `QA timezone (banco real) OK`. **PARE se:** falha: rollback, com aprovacao.

```bash
cd /var/www/chronodesk; runuser -u www-data -- php scripts/qa-timezone.php --db
```

- [ ] **21.** (7, teste de fumaca) Esperado: pede login e senha do AD do responsavel (admin), a senha sem eco. `login: 200`, perfil `admin`, os quatro `GET` com `200`, `ausencia de teste lancada: 201`, `ausencia de teste cancelada: 200`, `logout: 200`, `linhas novas no log do PHP, fora o login: 0`, `logins bem-sucedidos no log: 1` e, na ultima linha, `FUMACA OK`. **PARE se:** qualquer `PARE`: nao reabra, fique em manutencao e envie so a saida; ausencia de teste que ficou lancada: cancele pela tela; senha errada (`401`): uma nova tentativa; outro codigo ou linha nova no log: rollback, com aprovacao.

```bash
cd /var/www/chronodesk; S=$(awk 'tolower($1) == "servername" {print $2; exit}' /etc/apache2/sites-enabled/chronodesk.conf); if [ -n "$S" ]; then env PHPLOG=/var/log/chronodesk/php_error.log bash scripts/smoke-deploy1.sh "$S"; else echo "PARE: ServerName nao encontrado no chronodesk.conf"; fi; unset S
```

## 8. `AUTO_INCREMENT` de `funcionarios` (so leitura; nenhum cadastro de teste)

- [ ] **22.** (8) Esperado: `id_extra = auto_increment`, `id_tipo = int`, `proximo_id` maior que `maior_id` (em 10/11/2026: 334, 333 e 30 cadastros; anote os de hoje). No **primeiro cadastro real** pela tela: a mensagem traz o ID gerado ("Funcionario '...' adicionado com o ID N.") e este comando de novo mostra `maior_id = N`, `proximo_id` anotado mais 1 e `cadastros` mais 1. **PARE se:** `id_extra` sem `auto_increment` ou `proximo_id` menor ou igual a `maior_id`.

```bash
read -rsp 'Senha root do MySQL: ' P; echo; docker exec -i -e MYSQL_PWD="$P" Chrono_Desk_DB mysql -uroot --table sistema_pausas -e "SET SESSION information_schema_stats_expiry = 0; SELECT (SELECT EXTRA FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'funcionarios' AND COLUMN_NAME = 'id') AS id_extra, (SELECT COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'funcionarios' AND COLUMN_NAME = 'id') AS id_tipo, (SELECT AUTO_INCREMENT FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'funcionarios') AS proximo_id, (SELECT MAX(id) FROM funcionarios) AS maior_id, (SELECT COUNT(*) FROM funcionarios) AS cadastros;"; unset P
```

## 9. Reabertura e conferencia na tela

- [ ] **23.** (9) Esperado: so com os comandos 19 a 21 como esperado (`FUMACA OK`). `manutencao encerrada` e `portal visto de fora: 302`. Na tela, como admin, Administracao > Funcionarios: filtro "Almoco" com as contagens e a coluna "Almoco" entre Jornada e Status (390 px: cartoes com Jornada e Almoco); "Adicionar funcionario" sem o campo ID; na edicao, o ID so como texto. **PARE se:** `PARE: arquivo de manutencao ainda presente` ou outro codigo.

```bash
[ -e /var/www/chronodesk.maintenance ] && rm -- /var/www/chronodesk.maintenance; [ -e /var/www/chronodesk.maintenance ] && echo "PARE: arquivo de manutencao ainda presente" || echo "manutencao encerrada"; S=$(awk 'tolower($1) == "servername" {print $2; exit}' /etc/apache2/sites-enabled/chronodesk.conf); if [ -n "$S" ]; then curl -s -o /dev/null -w 'portal visto de fora: %{http_code}\n' "http://$S/"; else echo "PARE: ServerName nao encontrado no chronodesk.conf"; fi; unset S
```

## 10. Medicao do polling (base do deploy 1: p95 de 11,0 ms no `status.php` e 9,9 ms no `dashboard.php`)

- [ ] **24.** (10) Esperado: p50 e p95 do `status.php` (2 ou 3 rodadas, a mesma conta, sem `CONSULTAS`, `N=200`, aba fechada; anote na 9.14). **PARE se:** p95 mais de 50 ms **e** mais de 20% acima de 11,0 ms: meca de novo e avise.

```bash
cd /var/www/chronodesk; bash scripts/medir-polling.sh
```

- [ ] **25.** (10) Esperado: p50 e p95 do `dashboard.php` (anote na 9.14). **PARE se:** p95 mais de 50 ms **e** mais de 20% acima de 9,9 ms: meca de novo e avise.

```bash
cd /var/www/chronodesk; URL=http://127.0.0.1/api/portal/dashboard.php bash scripts/medir-polling.sh
```

## 11. Rollback de codigo (so se preciso, com aprovacao do responsavel)

Quando: build que nao se resolve (16), `health` diferente de `200` (18), `qa-timezone --db` com falha (19) ou teste de fumaca com `PARE` ou linha nova no log (20). Sem migration a desfazer. Depois: comandos 19 a 21 e a reabertura (22).

- [ ] **26.** (11) Esperado: `codigo anterior no ar: 2e27926`. **PARE se:** sem a mensagem.

```bash
cd /var/www/chronodesk; git checkout --detach "$(cat /var/backups/chronodesk/pre-deploy2-commit)" && echo "codigo anterior no ar: $(git rev-parse --short HEAD)"
```

- [ ] **27.** (11) Esperado: `app/ anterior de volta (o build retirado esta em ...)` (a copia do comando 13, sem `npm`; nada e apagado). **PARE se:** `PARE: a volta do app/ falhou` ou `PARE: copia ... ausente`: fique em manutencao e avise.

```bash
cd /var/www/chronodesk; A=/var/backups/chronodesk/app-antes-deploy2; X=/var/backups/chronodesk/app-deploy2-retirado-$(date +%Y%m%d-%H%M%S); if test -d "$A"; then { ! test -e app || mv app "$X"; } && cp -a "$A" app && diff -rq "$A" app && echo "app/ anterior de volta (o build retirado esta em $X)" || echo "PARE: a volta do app/ falhou"; else echo "PARE: copia $A ausente; nada mudou no app/"; fi; unset A X
```

- [ ] **28.** (11) Esperado: nenhuma saida. **PARE se:** erro na recarga.

```bash
systemctl reload "php$(php -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;')-fpm"
```

## Depois de um ciclo estavel, com o responsavel

- [ ] Push do commit validado para o `codex/modernizacao-ui-backend` (como o 9a do deploy 1; nunca `--force`).
- [ ] Limpeza com aprovacao em `/var/backups/chronodesk`: `deploy2-<data>` (dados pessoais), `app-antes-deploy2`, `pre-deploy2-commit` e `deploy2-commit` (listar antes so nome, modo, dono e tamanho).
- [ ] Primeiro cadastro real pela tela: o comando 22 de novo (ID gerado e `proximo_id` mais 1); registrar so os numeros na 9.14.
