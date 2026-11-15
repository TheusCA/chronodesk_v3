# Deploy 3: folha de execucao do dia

Gerada do roteiro "Deploy 3 (F10-1 sem efeito em producao, redesenho R1 a R4d e limpeza do sdk-icon.png ...)" do `DEPLOY_LINUX.md`: os mesmos comandos, na mesma ordem. O roteiro e a referencia: o detalhe de cada esperado esta la. Mudou o roteiro, muda esta folha no mesmo commit.

**O que vai:** F10-1 (sem efeito em producao), o redesenho R1 a R4d (o visual muda para todos) e a limpeza do `sdk-icon.png`; so codigo, **sem migration**; o lockfile nao mudou desde o `cf73984`: so o build. Producao esta no `cf73984` desde 12/11/2026.

**Data, janela e quem acompanha: A DEFINIR.**

**Shell root, sem `sudo`.** Abra o terminal da VM com `sudo -i` e rode **todos** os comandos desta folha nele, como estao (nenhum leva `sudo`; o que roda como `www-data` usa `runuser`). O comando 1 e a conferencia do ambiente. Terminal reaberto: `sudo -i` e o comando 1 de novo.

**Sem valor real nesta folha**, nem impressa nem anotada nela: nada de senha, IP, hostname, nome ou login. O `ServerName` e lido do `chronodesk.conf` na hora. Todo `curl` usa `http://` com o `ServerName` ou o loopback com o `Host`. A senha do root do MySQL e sempre digitada no `read -rsp` (sem eco). Numeros anotados vao para o registro do deploy, nunca para chat ou chamado.

**Regras de parada em toda a folha:** qualquer linha com `PARE`, ou saida diferente do esperado: pare e envie so a contagem ou a mensagem. Nada de `git pull`; o unico `git fetch` e o do comando 3.

## 0. Antes do dia, na estacao

- [ ] **0.** O responsavel publica o `feat/employee-form-rhf-zod`. Depois, a bateria completa sozinha (nada pesado em paralelo, nem outra bateria nem capturas): `git fetch origin; git worktree add --detach ..\chronodesk-deploy3 origin/feat/employee-form-rhf-zod; Set-Location ..\chronodesk-deploy3\frontend; npm ci; Set-Location ..; $env:CHROME_PATH='C:\Program Files\Google\Chrome\Application\chrome.exe'; powershell -NoProfile -ExecutionPolicy Bypass -File scripts\test-local.ps1 -SkipHttp -Browser; git rev-parse HEAD`. Esperado: `Validacao local concluida com sucesso`, `frontend: 26 scripts npm rodados (...): 26 OK, 0 com falha`, navegador com `falharam: 0` e o hash completo: anote como `<commit validado na estacao>`. Qualquer falha: nao ha deploy com esse commit.
- [ ] **0b.** Na vespera, o aviso a gestao do roteiro (passo 0b), com a data e a janela preenchidas; sem nome, hostname nem endereco.

## 1. Conferencia do ambiente, commit validado e producao no `cf73984`

- [ ] **1.** (1, ambiente) Esperado: antes, `sudo -i`. Oito linhas `OK` e `ambiente: OK`. **PARE se:** qualquer `BLOQUEADO` ou `PARE: ambiente com BLOQUEADO`.

```bash
R=OK; [ "$(id -u)" = 0 ] && echo "OK id -u = 0" || { echo "BLOQUEADO id -u (o shell nao e root)"; R=PARE; }; bash -c 'exit 0' && echo "OK bash -c" || { echo "BLOQUEADO bash -c"; R=PARE; }; tar -czf - -C /etc hostname 2>/dev/null | tar -tzf - >/dev/null 2>&1 && echo "OK tar com gzip" || { echo "BLOQUEADO tar com gzip"; R=PARE; }; [ "$(find /etc/hostname -maxdepth 0 -exec echo ok \; 2>/dev/null)" = ok ] && echo "OK find -exec" || { echo "BLOQUEADO find -exec"; R=PARE; }; runuser -u www-data -- php -v >/dev/null 2>&1 && echo "OK runuser -u www-data -- php -v" || { echo "BLOQUEADO runuser -u www-data -- php -v"; R=PARE; }; [ "$(runuser -u www-data -- php -r '$p = proc_open([PHP_BINARY, "-v"], [1 => ["pipe", "w"]], $x); if (!is_resource($p)) { exit(1); } stream_get_contents($x[1]); echo proc_close($p) === 0 ? "ok" : "falha";' 2>/dev/null)" = ok ] && echo "OK php filho como www-data (proc_open, como no qa-security)" || { echo "BLOQUEADO php filho como www-data"; R=PARE; }; npm -v >/dev/null 2>&1 && echo "OK npm -v" || { echo "BLOQUEADO npm -v"; R=PARE; }; git -C /var/www/chronodesk rev-parse --git-dir >/dev/null 2>&1 && echo "OK git no clone" || { echo "BLOQUEADO git no clone"; R=PARE; }; [ "$R" = OK ] && echo "ambiente: OK" || echo "PARE: ambiente com BLOQUEADO"; unset R
```

- [ ] **2.** (1) Esperado: `HEAD de producao: cf73984 (OK, gravado em pre-deploy3-commit)`, `branch: codex/modernizacao-ui-backend`, `alteracoes locais: 0`. **PARE se:** `PARE: HEAD de producao ...` (traga o hash curto) ou alteracao local.

```bash
cd /var/www/chronodesk; install -d -o root -g root -m 700 /var/backups/chronodesk; H=$(git rev-parse HEAD); if [ "$H" = "cf73984b26197bafe492a3671c1a80468848b83e" ]; then echo "$H" | tee /var/backups/chronodesk/pre-deploy3-commit >/dev/null; echo "HEAD de producao: cf73984 (OK, gravado em pre-deploy3-commit)"; else echo "PARE: HEAD de producao $(git rev-parse --short HEAD), nao o cf73984"; fi; echo "branch: $(git rev-parse --abbrev-ref HEAD)"; echo "alteracoes locais: $(git status --porcelain | wc -l)"; unset H
```

- [ ] **3.** (1) Esperado: antes, troque `<commit validado na estacao>` (item 0). O hash completo impresso (gravado em `deploy3-commit`). **PARE se:** `PARE: origin/... nao e o commit validado na estacao` ou erro do `fetch`.

```bash
cd /var/www/chronodesk; git fetch origin feat/employee-form-rhf-zod && K=$(git rev-parse origin/feat/employee-form-rhf-zod) && if [ "$K" = "<commit validado na estacao>" ]; then echo "$K" | tee /var/backups/chronodesk/deploy3-commit; else echo "PARE: origin/feat/employee-form-rhf-zod ($K) nao e o commit validado na estacao"; fi; unset K
```

- [ ] **4.** (1) Esperado: `fast-forward OK: <N> commits a aplicar` (anote), `migrations alteradas: 0`, `lockfile alterado: 0` (com `1`, anote: o comando 16 faz o `npm ci`) e `sdk-icon.png no commit: removido`. **PARE se:** linha `PARE`, `migrations alteradas` maior que `0` (este roteiro nao aplica migration) ou `sdk-icon.png no commit: presente`.

```bash
cd /var/www/chronodesk; D3=$(cat /var/backups/chronodesk/deploy3-commit); git cat-file -e "$D3^{commit}" && git cat-file -e "$D3:scripts/smoke-deploy1.sh" && git merge-base --is-ancestor HEAD "$D3" && echo "fast-forward OK: $(git rev-list --count HEAD.."$D3") commits a aplicar" || echo "PARE: deploy3-commit ausente, sem o smoke-deploy1.sh ou o HEAD nao e ancestral dele"; echo "migrations alteradas: $(git diff --name-only HEAD "$D3" -- migrations | wc -l)"; echo "lockfile alterado: $(git diff --name-only HEAD "$D3" -- frontend/package-lock.json | wc -l)"; echo "sdk-icon.png no commit: $(git cat-file -e "$D3:frontend/public/sdk-icon.png" 2>/dev/null && echo presente || echo removido)"; unset D3
```

## 2. Ensaio do codigo do commit gravado (em `/tmp`, como `www-data`; nenhum QA toca o banco real)

- [ ] **5.** (2) Esperado: `/tmp/.env ausente: OK`. **PARE se:** `PARE: existe /tmp/.env`.

```bash
[ ! -e /tmp/.env ] && echo "/tmp/.env ausente: OK" || echo "PARE: existe /tmp/.env; o ensaio leria esse arquivo"
```

- [ ] **6.** (2) Esperado: `extraido em /tmp/ensaio-deploy3-qa.<sufixo>`. **PARE se:** `PARE: falha ao extrair o deploy3-commit`.

```bash
cd /var/www/chronodesk; export D3=$(cat /var/backups/chronodesk/deploy3-commit); Q=$(mktemp -d /tmp/ensaio-deploy3-qa.XXXXXX); export Q; bash -o pipefail -c 'git archive "$D3" | tar -x -C "$Q"' && chown -R www-data: "$Q" && echo "extraido em $Q" || echo "PARE: falha ao extrair o deploy3-commit"
```

- [ ] **7.** (2) Esperado: nenhuma linha antes de `fim do php -l`. **PARE se:** qualquer linha antes de `fim do php -l`.

```bash
find "$Q" -name '*.php' -not -path '*/node_modules/*' -exec runuser -u www-data -- php -l {} \; | grep -v '^No syntax errors' ; echo "fim do php -l"
```

- [ ] **8.** (2) Esperado: os 18 QAs com `OK` (os 16 do deploy 2, mais o `qa-pa-distribution` e o `qa-paridade-escala` do F10-1). **PARE se:** algum `FALHOU`: antes do comando 9, rode o QA de novo sem o `>/dev/null` e envie so o nome e a ultima linha.

```bash
[ ! -e /tmp/.env ] && for q in qa-smoke qa-auth qa-timezone qa-overtime qa-schedule-history qa-schedule-legacy-output qa-presence-engine qa-absences qa-calendar-text qa-archive qa-presence-cutoff qa-pa-conflicts qa-operational-view qa-employee-lunch qa-pa-distribution qa-paridade-escala qa-security qa-deploy-runbook; do printf '%s: ' "$q"; runuser -u www-data -- php "$Q/scripts/$q.php" >/dev/null 2>&1 && echo OK || echo FALHOU; done
```

- [ ] **9.** (2) Esperado: `removido: /tmp/ensaio-deploy3-qa.<sufixo>` e `ensaios restantes: 0`. **PARE se:** `ensaios restantes` maior que `0`.

```bash
for Q in /tmp/ensaio-deploy3-qa.??????; do [ -d "$Q" ] && rm -r -- "$Q" && echo "removido: $Q"; done; echo "ensaios restantes: $(ls -d /tmp/ensaio-deploy3-qa.?????? 2>/dev/null | wc -l)"; unset Q D3
```

## 3. Espaco livre e backup rapido (portal no ar)

- [ ] **10.** (3) Esperado: seis linhas `OK` e `espaco livre: OK` (anote os livres). **PARE se:** qualquer `BAIXO` ou `PARE: espaco livre abaixo do minimo` (liberar espaco e da infraestrutura).

```bash
cd /var/www/chronodesk/frontend; NPMC=$(npm config get cache); test -e "$NPMC" || NPMC=$(dirname "$NPMC"); DROOT=$(docker info --format '{{.DockerRootDir}}'); ESP=OK; for LIM in "/var/www/chronodesk:500" "$NPMC:200" "/var/backups:100" "$DROOT:200" "/tmp:50" "/root:50"; do DIR=${LIM%:*}; MIN=${LIM##*:}; LIVRE=$(df -BM --output=avail "$DIR" | tail -n 1 | tr -dc '0-9'); if [ "${LIVRE:-0}" -ge "$MIN" ]; then echo "OK $DIR (disco $(df --output=target "$DIR" | tail -n 1)): $LIVRE MB livres, minimo $MIN"; else echo "BAIXO $DIR: ${LIVRE:-?} MB livres, minimo $MIN"; ESP=PARE; fi; done; [ "$ESP" = OK ] && echo "espaco livre: OK" || echo "PARE: espaco livre abaixo do minimo"; unset NPMC DROOT ESP LIM DIR MIN LIVRE
```

- [ ] **11.** (3) Esperado: `backup concluido: /var/backups/chronodesk/deploy3-<data> (<tamanho>)` (anote; o do deploy 2 tinha 52K). **PARE se:** `PARE: backup falhou`.

```bash
B=/var/backups/chronodesk/deploy3-$(date +%Y%m%d-%H%M%S); export B; read -rsp 'Senha root do MySQL: ' P; echo; export P; install -d -o root -g root -m 700 "$B" && bash -o pipefail -c 'docker exec -i -e MYSQL_PWD="$P" Chrono_Desk_DB mysqldump -uroot --single-transaction --routines --triggers --no-tablespaces --set-gtid-purged=OFF sistema_pausas | gzip | tee "$B/database.sql.gz" > /dev/null' && gzip -t "$B/database.sql.gz" && tar -czf "$B/estado.tar.gz" -C /var/www/chronodesk --ignore-failed-read estado.json pausas.csv config_sistema.json funcionarios.json && sh -c 'cd "$1" && sha256sum database.sql.gz estado.tar.gz > SHA256SUMS' _ "$B" && echo "backup concluido: $B ($(du -sh "$B" | cut -f1))" || echo "PARE: backup falhou (sem ponto de restauracao, o deploy nao segue)"; unset P
```

## 4. Manutencao e copia do `app/` (o portal fica em 503 ate o comando 22)

- [ ] **12.** (4) Esperado: `arquivo de manutencao: presente`. **PARE se:** `AUSENTE`.

```bash
touch /var/www/chronodesk.maintenance; echo "arquivo de manutencao: $(test -e /var/www/chronodesk.maintenance && echo presente || echo AUSENTE)"
```

- [ ] **13.** (4) Esperado: `copia do app/: /var/backups/chronodesk/app-antes-deploy3 (...)` (anote tamanho e arquivos; no deploy 2, 868K e 28). **PARE se:** `PARE: ...` (tire o arquivo-sinal com o comando 22 e avise).

```bash
cd /var/www/chronodesk; A=/var/backups/chronodesk/app-antes-deploy3; if test -e "$A"; then diff -rq app "$A" && echo "copia do app/ ja existe e e igual: $A" || echo "PARE: $A existe e e diferente do app/ atual"; else cp -a app "$A" && diff -rq app "$A" && echo "copia do app/: $A ($(du -sh "$A" | cut -f1); $(find "$A" -type f | wc -l) arquivos)" || echo "PARE: copia do app/ falhou"; fi; unset A
```

- [ ] **14.** (4) Esperado: `portal visto de fora (manutencao): 503` (sem `503`: confira de uma estacao que aparece "ChronoDesk temporariamente indisponivel."). **PARE se:** a estacao ve a tela do portal (tire o arquivo-sinal com o comando 22 e avise).

```bash
S=$(awk 'tolower($1) == "servername" {print $2; exit}' /etc/apache2/sites-enabled/chronodesk.conf); if [ -n "$S" ]; then curl -s -o /dev/null -w 'portal visto de fora (manutencao): %{http_code}\n' "http://$S/"; else echo "PARE: ServerName nao encontrado no chronodesk.conf"; fi; unset S
```

## 5. `git merge --ff-only` do commit gravado

- [ ] **15.** (5) Esperado: o `merge` em fast-forward, `HEAD = deploy3-commit` e `alteracoes locais: 0`. **PARE se:** `PARE: HEAD diferente do deploy3-commit` (fique em manutencao; nada de `fetch` nem `pull`).

```bash
cd /var/www/chronodesk; D3=$(cat /var/backups/chronodesk/deploy3-commit); git merge --ff-only "$D3"; [ "$(git rev-parse HEAD)" = "$D3" ] && echo "HEAD = deploy3-commit" || echo "PARE: HEAD diferente do deploy3-commit"; echo "alteracoes locais: $(git status --porcelain | wc -l)"; unset D3
```

## 6. Build do frontend e o QA do bundle (lockfile sem mudanca: so o build)

- [ ] **16.** (6) Esperado: `lockfile sem mudanca: so o build` e o build sem erro (o do deploy 2 levou 1,22 s). **PARE se:** falha por falta de espaco (`ENOSPC`) ou de rede (`ETIMEDOUT`, `ECONNRESET`, `ECONNREFUSED`, `EAI_AGAIN`, `ENOTFOUND`, `E403`, `E404`, `E5xx`, `EINTEGRITY`): fique em manutencao, sem recarga, e avise; resolvido, repita uma vez; senao, rollback (comandos 26 a 28). Outra falha: rollback, com aprovacao.

```bash
cd /var/www/chronodesk/frontend && if [ "$(git diff --name-only "$(cat /var/backups/chronodesk/pre-deploy3-commit)" HEAD -- package-lock.json | wc -l)" = 0 ]; then echo "lockfile sem mudanca: so o build"; npm run build; else echo "lockfile alterado: npm ci e build"; npm ci && npm run build; fi
```

- [ ] **17.** (6) Esperado: `Bundle frontend QA OK (...)` com o CSS abaixo de 70000 bytes e `sdk-icon.png fora do build`. **PARE se:** falha do `qa:bundle` ou `PARE: sdk-icon.png ainda no build`: rollback, com aprovacao.

```bash
cd /var/www/chronodesk/frontend && npm run -s qa:bundle; test -e ../app/sdk-icon.png && echo "PARE: sdk-icon.png ainda no build" || echo "sdk-icon.png fora do build"
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

- [ ] **21.** (7, teste de fumaca) Esperado: pede login e senha do AD do responsavel (admin), a senha sem eco. `login: 200`, perfil `admin`, os quatro `GET` com `200`, `ausencia de teste lancada: 201`, `ausencia de teste cancelada: 200`, `logout: 200`, `linhas novas no log do PHP, fora o login: 0`, `logins bem-sucedidos no log: 1` e, na ultima linha, `FUMACA OK`. **PARE se:** qualquer `PARE`: nao reabra; envie so a saida. Ausencia de teste que ficou lancada: cancele pela tela. Senha errada (`401`): uma nova tentativa. Outro codigo ou linha nova no log: rollback, com aprovacao.

```bash
cd /var/www/chronodesk; S=$(awk 'tolower($1) == "servername" {print $2; exit}' /etc/apache2/sites-enabled/chronodesk.conf); if [ -n "$S" ]; then env PHPLOG=/var/log/chronodesk/php_error.log bash scripts/smoke-deploy1.sh "$S"; else echo "PARE: ServerName nao encontrado no chronodesk.conf"; fi; unset S
```

## 9. Reabertura e conferencia na tela (8: nada a conferir no banco)

- [ ] **22.** (9) Esperado: so com os comandos 19 a 21 como esperado (`FUMACA OK`). `manutencao encerrada` e `portal visto de fora: 302`. Na tela, como admin, de uma estacao (Ctrl+F5 uma vez): **Pausas** com os tres motivos a vista e a lista com filtro; **Visao operacional** com "Disponiveis agora", N1/N2, "Pausas em andamento", "Precisa de voce" e o contador do menu so com o numero; **modo painel** em `/app/dashboard?painel=1` sem menu nem acoes, sem a observacao de reuniao, e "Sair do modo painel"; **Aprovacoes** com o filtro por tipo e um so "Aprovar" destacado (sem aprovar); **Funcionarios** com a lista primeiro, o filtro "Almoco" (contagens, coluna, filtro ativo na fileira), "Adicionar" e "Editar" no painel lateral (feche sem salvar) e o "Grupo Tools" desabilitado; **tema escuro** pelo botao do topo e de volta. **PARE se:** `PARE`, outro codigo, ou tela em branco ou quebrada: avise (o rollback volta o `app/` em segundos).

```bash
[ -e /var/www/chronodesk.maintenance ] && rm -- /var/www/chronodesk.maintenance; [ -e /var/www/chronodesk.maintenance ] && echo "PARE: arquivo de manutencao ainda presente" || echo "manutencao encerrada"; S=$(awk 'tolower($1) == "servername" {print $2; exit}' /etc/apache2/sites-enabled/chronodesk.conf); if [ -n "$S" ]; then curl -s -o /dev/null -w 'portal visto de fora: %{http_code}\n' "http://$S/"; else echo "PARE: ServerName nao encontrado no chronodesk.conf"; fi; unset S
```

## 10. Medicao do polling (alguns minutos depois da reabertura; 2 rodadas por endpoint, valendo a melhor; sessao de gestao)

- [ ] **23.** (10) Esperado: p50 e p95 do `status.php`, na melhor de 2 rodadas (a mesma conta, sem `CONSULTAS`, `N=200`, aba fechada; anote). **PARE se:** p95 mais de 50 ms **e** mais de 20% acima de 9,6 ms (2a rodada do deploy 2): meca de novo e avise.

```bash
cd /var/www/chronodesk; bash scripts/medir-polling.sh
```

- [ ] **24.** (10) Esperado: p50 e p95 do `dashboard.php`, na melhor de 2 rodadas (anote). **PARE se:** p95 mais de 50 ms **e** mais de 20% acima de 14,6 ms: meca de novo e avise.

```bash
cd /var/www/chronodesk; URL=http://127.0.0.1/api/portal/dashboard.php bash scripts/medir-polling.sh
```

- [ ] **25.** (10, contador do menu) Esperado: p50 e p95 do `solicitacoes_pendentes.php`, na melhor de 2 rodadas (sem base: anote como base). **PARE se:** p95 mais de 50 ms (meca de novo e avise) ou codigo diferente de `200` (a sessao nao e de gestao: abra uma de gestao e repita).

```bash
cd /var/www/chronodesk; URL=http://127.0.0.1/api/solicitacoes_pendentes.php bash scripts/medir-polling.sh
```

## 11. Rollback de codigo (so se preciso, com aprovacao do responsavel)

Quando: build ou `qa:bundle` que nao se resolve (16 e 17), `health` diferente de `200` (19), `qa-timezone --db` com falha (20), teste de fumaca com `PARE` ou linha nova no log (21), ou tela quebrada (22). Sem migration a desfazer.

- [ ] **26.** (11) Esperado: `codigo anterior no ar: cf73984`. **PARE se:** sem a mensagem.

```bash
cd /var/www/chronodesk; git checkout --detach "$(cat /var/backups/chronodesk/pre-deploy3-commit)" && echo "codigo anterior no ar: $(git rev-parse --short HEAD)"
```

- [ ] **27.** (11) Esperado: `app/ anterior de volta (o build retirado esta em ...)` (a copia do comando 13, sem `npm`; nada e apagado). **PARE se:** `PARE: a volta do app/ falhou` ou `PARE: copia ... ausente`: fique em manutencao e avise.

```bash
cd /var/www/chronodesk; A=/var/backups/chronodesk/app-antes-deploy3; X=/var/backups/chronodesk/app-deploy3-retirado-$(date +%Y%m%d-%H%M%S); if test -d "$A"; then { ! test -e app || mv app "$X"; } && cp -a "$A" app && diff -rq "$A" app && echo "app/ anterior de volta (o build retirado esta em $X)" || echo "PARE: a volta do app/ falhou"; else echo "PARE: copia $A ausente; nada mudou no app/"; fi; unset A X
```

- [ ] **28.** (11) Esperado: nenhuma saida. **PARE se:** erro na recarga. Depois: comandos 19 a 21 e a reabertura (22); a gestao recebe um aviso curto de que a atualizacao foi desfeita.

```bash
systemctl reload "php$(php -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;')-fpm"
```

## Depois de um ciclo estavel, com o responsavel

- [ ] Push do commit validado para o `codex/modernizacao-ui-backend` (como depois dos deploys 1 e 2; nunca `--force`).
- [ ] Limpeza com aprovacao em `/var/backups/chronodesk`: `deploy3-<data>` (dados pessoais), `app-antes-deploy3`, `pre-deploy3-commit` e `deploy3-commit` (listar antes so nome, modo, dono e tamanho).
