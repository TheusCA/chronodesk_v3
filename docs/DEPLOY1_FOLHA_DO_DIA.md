# Deploy 1 da Parte B: folha de execucao do dia

Gerada do roteiro "Deploy 1 da Parte B (roteiro unico ...)" do `DEPLOY_LINUX.md`: os mesmos comandos, na mesma ordem (a trava `scripts/qa-deploy-runbook.php` confere). O roteiro e a referencia: o detalhe de cada esperado, as opcoes e os riscos estao la. Mudou o roteiro, muda esta folha no mesmo commit.

**Sem valor real nesta folha**, nem impressa nem anotada nela: nada de senha, IP, hostname, nome ou login. Os marcadores `<...>` sao trocados so no terminal, na hora; os numeros vao para o desenho (9.9, 9.12 e 9.13), nunca para chat ou chamado. A senha do root do MySQL e sempre digitada no `read -rsp` (sem eco).

Data, janela e quem acompanha (iguais as do roteiro; a trava confere):
- **Data e janela do deploy**, fora do horario de operacao: A DEFINIR.
- **Quem acompanha** (alem de quem executa): o responsavel pela infraestrutura da VM.

**Regras de parada em toda a folha:** qualquer linha com `PARE`, ou `FALHOU` num ensaio onde nao era esperado: pare e envie so a contagem ou a mensagem. Verificacao da 019 com `inconsistente = 1`: pare, nao registre o corte, nao apague nem altere linha e avise o responsavel. Nada de `git pull`, e nenhum `git fetch` depois do comando 19. Onde o "PARE se" diz "saida diferente do esperado", pare e confira o roteiro antes de seguir.

## 0. Antes do dia, com o responsavel

Anote os numeros: os passos seguintes comparam.

- [ ] **1.** (0a) Esperado: `branch: codex/modernizacao-ui-backend`, `deploys 1 a 7 no codigo: OK`, `alteracoes locais: 0`. **PARE se:** linha `PARE`, outro branch ou alteracoes locais maiores que `0`.

```bash
cd /var/www/chronodesk; echo "branch: $(sudo git rev-parse --abbrev-ref HEAD)"; sudo git merge-base --is-ancestor 5d5c7c93db383c163b5e753f44375d7e5c34a117 HEAD && echo "deploys 1 a 7 no codigo: OK" || echo "PARE: o codigo no ar nao tem o deploy 7 da fila (5d5c7c9)"; echo "alteracoes locais: $(sudo git status --short | wc -l)"
```

- [ ] **2.** (0a) Esperado: `mysql` 8.0.29 ou mais novo; `migration_015 = 1`, `migration_016 = 1`, `migration_017 = 1`; `colunas_011_014 = 0`, `colunas_012 = 0`, `tabela_019 = 0`. **PARE se:** qualquer numero diferente (traga os numeros).

```bash
read -rsp 'Senha root do MySQL: ' P; echo; docker exec -i -e MYSQL_PWD="$P" Chrono_Desk_DB mysql -uroot --table sistema_pausas -e "SELECT VERSION() AS mysql, (SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'login_user_throttle') AS migration_015, (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'funcionarios' AND INDEX_NAME = 'uq_funcionarios_ad_login') AS migration_016, (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'portal_shift_attachments' AND COLUMN_NAME = 'team') AS migration_017, (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'funcionarios' AND COLUMN_NAME IN ('fora_mapa_escala', 'arquivado_em', 'arquivado_por')) AS colunas_011_014, (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'portal_absences' AND COLUMN_NAME IN ('category', 'period_type', 'cancelled_by')) AS colunas_012, (SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'portal_presence_cutoff') AS tabela_019;"; unset P
```

- [ ] **3.** (0b) Esperado: p50 e p95 do `status.php` (anote; 2 ou 3 rodadas, a mesma conta, sem `CONSULTAS`, `N=200`, aba fechada). **PARE se:** `unknown revision` ou `Not a valid object name`: avise o responsavel antes do dia (o passo 2 depende deste `fetch`).

```bash
cd /var/www/chronodesk; sudo git fetch origin feat/employee-form-rhf-zod && sudo git cat-file -e origin/feat/employee-form-rhf-zod:scripts/medir-polling.sh && bash <(sudo git show origin/feat/employee-form-rhf-zod:scripts/medir-polling.sh)
```

- [ ] **4.** (0b) Esperado: p50 e p95 do `dashboard.php` (anote; registre na 9.9). **PARE se:** nada (so anotar).

```bash
sudo git cat-file -e origin/feat/employee-form-rhf-zod:scripts/medir-polling.sh && URL=http://127.0.0.1/api/portal/dashboard.php bash <(sudo git show origin/feat/employee-form-rhf-zod:scripts/medir-polling.sh)
```

- [ ] **5.** (0c) Esperado: contagens anotadas; referencia `pas_compartilhados_hoje = 6`, `vinculos_de_inativos = 1`, `vinculos_lideranca = 2`. `[N_PAS]` = `pas_compartilhados_hoje`. **PARE se:** nada (so anotar).

```bash
read -rsp 'Senha root do MySQL: ' P; echo; docker exec -i -e MYSQL_PWD="$P" Chrono_Desk_DB mysql -uroot --table sistema_pausas -e "SET time_zone = 'America/Sao_Paulo'; SELECT (SELECT COUNT(*) FROM portal_pa_inventory WHERE active = 1) AS pas_inventario, (SELECT COUNT(*) FROM portal_pa_assignments WHERE active = 1) AS vinculos_ativos, (SELECT COUNT(*) FROM portal_pa_assignments WHERE active = 1 AND (valid_until IS NULL OR valid_until >= CURDATE())) AS vinculos_vigentes_ou_futuros, (SELECT COUNT(*) FROM (SELECT pa_number FROM portal_pa_assignments WHERE active = 1 AND valid_from <= CURDATE() AND (valid_until IS NULL OR valid_until >= CURDATE()) GROUP BY pa_number HAVING COUNT(*) > 1) t) AS pas_compartilhados_hoje, (SELECT COUNT(*) FROM portal_pa_assignments a JOIN funcionarios f ON f.id = a.employee_id WHERE a.active = 1 AND (a.valid_until IS NULL OR a.valid_until >= CURDATE()) AND f.ativo = 0) AS vinculos_de_inativos, (SELECT COUNT(*) FROM portal_pa_assignments WHERE active = 1 AND team = 'lideranca' AND (valid_until IS NULL OR valid_until >= CURDATE())) AS vinculos_lideranca;"; unset P
```

- [ ] **6.** (0c) Esperado: `sobreposicoes_mesmo_colaborador = 0`, `periodo_invertido = 0`, `vinculos_fora_do_inventario = 0`. **PARE se:** um dos tres maior que `0` (dado antigo: decidir antes do dia).

```bash
read -rsp 'Senha root do MySQL: ' P; echo; docker exec -i -e MYSQL_PWD="$P" Chrono_Desk_DB mysql -uroot --table sistema_pausas -e "SET time_zone = 'America/Sao_Paulo'; SELECT (SELECT COUNT(*) FROM portal_pa_assignments a JOIN portal_pa_assignments b ON b.employee_id = a.employee_id AND b.id > a.id AND b.active = 1 AND b.valid_from <= COALESCE(a.valid_until, '9999-12-31') AND COALESCE(b.valid_until, '9999-12-31') >= a.valid_from WHERE a.active = 1) AS sobreposicoes_mesmo_colaborador, (SELECT COUNT(*) FROM portal_pa_assignments WHERE active = 1 AND valid_until < valid_from) AS periodo_invertido, (SELECT COUNT(*) FROM portal_pa_assignments a WHERE a.active = 1 AND NOT EXISTS (SELECT 1 FROM portal_pa_inventory i WHERE i.active = 1 AND i.pa_number = a.pa_number)) AS vinculos_fora_do_inventario, (SELECT COUNT(*) FROM portal_pa_assignments a LEFT JOIN portal_schedule_rules r ON r.employee_id = a.employee_id AND r.effective_from <= CURDATE() AND (r.effective_until IS NULL OR r.effective_until >= CURDATE()) WHERE a.active = 1 AND a.valid_from <= CURDATE() AND (a.valid_until IS NULL OR a.valid_until >= CURDATE()) AND a.team <> 'lideranca' AND a.schedule_rule_type <> COALESCE(IF(r.rule_type = 'cycle', 'undefined', r.rule_type), 'undefined')) AS copia_tipo_divergente_hoje, (SELECT COUNT(*) FROM portal_pa_assignments WHERE active = 1 AND notes IS NOT NULL AND notes <> '') AS vinculos_com_observacao, (SELECT COUNT(*) FROM portal_pa_map WHERE record_status = 'active') AS legado_total, (SELECT COUNT(*) FROM portal_pa_map WHERE record_status = 'active' AND work_date >= CURDATE()) AS legado_futuro;"; unset P
```

- [ ] **7.** (0d) Esperado: referencia `ativos_n1 = 13`, `ativos_n2 = 9`, `ativos_lideranca = 3`; `ativos_outras_equipes`, `jornada_invertida` e `almoco_fora_da_jornada` em `0`. `[N_SEM_REGRA]` = `n1_n2_sem_regra_hoje`. Num dia util, no expediente. **PARE se:** um dos tres maior que `0` (corrigir o cadastro pela tela antes do dia).

```bash
read -rsp 'Senha root do MySQL: ' P; echo; docker exec -i -e MYSQL_PWD="$P" Chrono_Desk_DB mysql -uroot --table sistema_pausas -e "SET time_zone = 'America/Sao_Paulo'; SELECT (SELECT COUNT(*) FROM funcionarios WHERE ativo = 1 AND LOWER(TRIM(equipe)) = 'n1') AS ativos_n1, (SELECT COUNT(*) FROM funcionarios WHERE ativo = 1 AND LOWER(TRIM(equipe)) = 'n2') AS ativos_n2, (SELECT COUNT(*) FROM funcionarios WHERE ativo = 1 AND LOWER(TRIM(equipe)) IN ('lideranca', 'liderança', 'na', 'não se aplica', 'nao se aplica', 'nao_se_aplica', 'sem_equipe')) AS ativos_lideranca, (SELECT COUNT(*) FROM funcionarios WHERE ativo = 1 AND LOWER(TRIM(equipe)) NOT IN ('n1', 'n2', 'lideranca', 'liderança', 'na', 'não se aplica', 'nao se aplica', 'nao_se_aplica', 'sem_equipe')) AS ativos_outras_equipes, (SELECT COUNT(*) FROM funcionarios f WHERE f.ativo = 1 AND LOWER(TRIM(f.equipe)) IN ('n1', 'n2') AND NOT EXISTS (SELECT 1 FROM portal_schedule_rules r WHERE r.employee_id = f.id AND r.rule_type <> 'undefined' AND r.effective_from <= CURDATE() AND (r.effective_until IS NULL OR r.effective_until >= CURDATE()))) AS n1_n2_sem_regra_hoje, (SELECT COUNT(*) FROM funcionarios WHERE ativo = 1 AND jornada_entrada >= jornada_saida) AS jornada_invertida, (SELECT COUNT(*) FROM funcionarios WHERE ativo = 1 AND NOT (jornada_entrada <= almoco_inicio AND almoco_inicio < almoco_fim AND almoco_fim <= jornada_saida)) AS almoco_fora_da_jornada, (SELECT COUNT(*) FROM portal_absences WHERE status = 'approved' AND starts_on <= CURDATE() AND ends_on >= CURDATE()) AS ausencias_aprovadas_hoje, (SELECT COUNT(*) FROM portal_schedule_exceptions WHERE exception_date = CURDATE() AND exception_type IN ('day_off', 'absence', 'vacation', 'leave')) AS excecoes_de_ausencia_hoje;"; unset P
```

- [ ] **8.** (0d) Esperado: `rotulo_que_o_php_nao_reconhece = 0`. **PARE se:** maior que `0` (corrigir o cadastro pela tela antes do dia).

```bash
read -rsp 'Senha root do MySQL: ' P; echo; docker exec -i -e MYSQL_PWD="$P" Chrono_Desk_DB mysql -uroot --table sistema_pausas -e "SELECT COUNT(*) AS rotulo_que_o_php_nao_reconhece FROM funcionarios WHERE ativo = 1 AND (CAST(LOWER(TRIM(equipe)) AS BINARY) NOT IN ('n1', 'n2', 'lideranca', 'liderança', 'na', 'não se aplica', 'nao se aplica', 'nao_se_aplica', 'sem_equipe') OR LOCATE(CAST('Ç' AS BINARY), CAST(TRIM(equipe) AS BINARY)) > 0 OR LOCATE(CAST('Ã' AS BINARY), CAST(TRIM(equipe) AS BINARY)) > 0);"; unset P
```

- [ ] **9.** (0e) Esperado: `tabela_019 = 0`, `gatilhos_019 = 0`, `copia_rollback = 0`, `eventos_registro = 0`, `eventos_remocao = 0`, `inconsistente = 0`. **PARE se:** `inconsistente = 1` (regra 16a) ou saida diferente do esperado.

```bash
cd /var/www/chronodesk; read -rsp 'Senha root do MySQL: ' P; echo; export P; sudo git cat-file -e origin/feat/employee-form-rhf-zod:migrations/verificacao/20261013_019_presence_cutoff_check.sql && bash -o pipefail -c 'sudo git show origin/feat/employee-form-rhf-zod:migrations/verificacao/20261013_019_presence_cutoff_check.sql | docker exec -i -e MYSQL_PWD="$P" Chrono_Desk_DB mysql -uroot --table sistema_pausas'; unset P
```

- [ ] **10.** (0f) Esperado: `usuario_lido = 1`, `concessoes_drop = 0`, `concessoes_trigger = 0`. **PARE se:** `usuario_lido = 0`. Concessoes maiores que `0` nao bloqueiam: decisao do responsavel antes do dia (VULN-002).

```bash
U=$(sudo grep -E '^DB_USER=' /var/www/.env | cut -d= -f2- | tr -d "\"'"); read -rsp 'Senha root do MySQL: ' P; echo; docker exec -i -e MYSQL_PWD="$P" Chrono_Desk_DB mysql -uroot --table -e "SELECT '$U' <> '' AS usuario_lido, (SELECT COUNT(*) FROM (SELECT GRANTEE, PRIVILEGE_TYPE FROM information_schema.USER_PRIVILEGES UNION ALL SELECT GRANTEE, PRIVILEGE_TYPE FROM information_schema.SCHEMA_PRIVILEGES UNION ALL SELECT GRANTEE, PRIVILEGE_TYPE FROM information_schema.TABLE_PRIVILEGES) g WHERE g.GRANTEE LIKE CONCAT('''', '$U', '''@%') AND g.PRIVILEGE_TYPE = 'DROP') AS concessoes_drop, (SELECT COUNT(*) FROM (SELECT GRANTEE, PRIVILEGE_TYPE FROM information_schema.USER_PRIVILEGES UNION ALL SELECT GRANTEE, PRIVILEGE_TYPE FROM information_schema.SCHEMA_PRIVILEGES UNION ALL SELECT GRANTEE, PRIVILEGE_TYPE FROM information_schema.TABLE_PRIVILEGES) g WHERE g.GRANTEE LIKE CONCAT('''', '$U', '''@%') AND g.PRIVILEGE_TYPE = 'TRIGGER') AS concessoes_trigger;"; unset P U
```

- [ ] **11.** (0g) Esperado: antes, troque `<AAAA-MM-DD do deploy>`. `corte planejado: AAAA-MM-16` (anote; e o `[DATA_CORTE]`). **PARE se:** `PARE: data do corte invalida`.

```bash
D='<AAAA-MM-DD do deploy>'; if [ "$(date -d "$D" +%d)" -ge 16 ]; then C=$(date -d "$(date -d "$D" +%Y-%m-01) +1 month" +%Y-%m-16); else C=$(date -d "$D" +%Y-%m-16); fi; [[ "$C" =~ ^[0-9]{4}-[0-9]{2}-16$ ]] && echo "corte planejado: $C" || echo "PARE: data do corte invalida ($C)"
```

- [ ] **12.** (0g) Esperado: referencia `ativos_n1_n2 = 22`; anote `sem_regra_no_corte` e `sem_regra_na_competencia` (o ideal e `0`). **PARE se:** nao para aqui; maior que `0` no dia impede o registro do corte (comando 73).

```bash
read -rsp 'Senha root do MySQL: ' P; echo; docker exec -i -e MYSQL_PWD="$P" Chrono_Desk_DB mysql -uroot --table sistema_pausas -e "SET @corte := '$C'; WITH RECURSIVE dias (d) AS (SELECT CAST(@corte AS DATE) UNION ALL SELECT d + INTERVAL 1 DAY FROM dias WHERE d < @corte + INTERVAL 1 MONTH - INTERVAL 1 DAY) SELECT COUNT(*) AS ativos_n1_n2, COALESCE(SUM(NOT EXISTS (SELECT 1 FROM portal_schedule_rules r WHERE r.employee_id = f.id AND r.rule_type <> 'undefined' AND r.effective_from <= @corte AND (r.effective_until IS NULL OR r.effective_until >= @corte))), 0) AS sem_regra_no_corte, COALESCE(SUM(EXISTS (SELECT 1 FROM dias WHERE NOT EXISTS (SELECT 1 FROM portal_schedule_rules r WHERE r.employee_id = f.id AND r.rule_type <> 'undefined' AND r.effective_from <= dias.d AND (r.effective_until IS NULL OR r.effective_until >= dias.d)))), 0) AS sem_regra_na_competencia FROM funcionarios f WHERE f.ativo = 1 AND LOWER(f.equipe) IN ('n1', 'n2');"; unset P
```

- [ ] **13.** (0h) Esperado: `app_env=staging`, `secret_key_caracteres` 32 ou mais, `secret_key_texto_de_exemplo=0`. **PARE se:** saida diferente do esperado: no dia, pule o comando 23 (nao troque o `APP_ENV`).

```bash
sudo awk -F= '/^APP_ENV=/{print "app_env=" $2} /^SECRET_KEY=/{v=substr($0, 12); gsub(/["\047]/, "", v); print "secret_key_caracteres=" length(v); print "secret_key_texto_de_exemplo=" (tolower(v) ~ /gere_uma_chave|troque|changeme|change_me/)}' /var/www/.env
```

- [ ] **14.** (0i, estacao) Esperado: a versao (8.19 ou mais nova; antes, `gitleaks detect`) e `no leaks found`. **PARE se:** qualquer achado: nao siga (revogar, gerar outro, tirar do historico).

```powershell
git fetch origin; gitleaks version; gitleaks git --redact --log-opts="5d5c7c93db383c163b5e753f44375d7e5c34a117..origin/feat/employee-form-rhf-zod" .
```

- [ ] **15.** (0j) Esperado: so contagens por metodo, caminho e codigo (anote; registre na 9.12). **PARE se:** nada; uso maior que `0` nas paginas `POST`: avise no aviso a gestao.

```bash
sudo sh -c 'cat /var/log/apache2/chronodesk_access.log /var/log/apache2/chronodesk_access.log.1 2>/dev/null; zcat /var/log/apache2/chronodesk_access.log.*.gz 2>/dev/null' | awk '{print $6, $7, $9}' | sed -E 's/\?[^ ]*//' | grep -E '^"(GET|POST) /(admin|admin_login|admin_logout|login|logout|metricas|index)\.php |^"GET /static/js/admin(_users)?\.js ' | sort | uniq -c
```

- [ ] **16.** (0j) Esperado: `GET 302` nas paginas que ja redirecionam; as demais, como hoje (anote). **PARE se:** nada (so anotar); o comando 66 repete com o esperado novo.

```bash
for p in / /index.php /login.php /admin_login.php /admin_logout.php /admin.php /metricas.php /logout.php; do echo "$p GET $(curl -k -s -o /dev/null -w '%{http_code}' "<URL>$p") POST $(curl -k -s -o /dev/null -w '%{http_code}' -X POST "<URL>$p")"; done; for j in admin.js admin_users.js; do echo "$j $(curl -k -s -o /dev/null -w '%{http_code}' "<URL>/static/js/$j")"; done
```

- [ ] **17.** (0k, estacao) Esperado: `Validacao local concluida com sucesso`, roteiro de navegador com `falharam: 0` e o hash completo: anote como `<commit validado na estacao>`. **PARE se:** qualquer falha: nao ha deploy com esse commit.

```powershell
git fetch origin; git worktree add --detach ..\chronodesk-deploy1 origin/feat/employee-form-rhf-zod; Set-Location ..\chronodesk-deploy1\frontend; npm ci; Set-Location ..; $env:CHROME_PATH='C:\Program Files\Google\Chrome\Application\chrome.exe'; powershell -NoProfile -ExecutionPolicy Bypass -File scripts\test-local.ps1 -SkipHttp -Browser; git rev-parse HEAD
```

- [ ] **0l.** Aviso unico a gestao enviado (texto no roteiro, 0l), com `[N_PAS]` (comando 5), `[N_SEM_REGRA]` (comando 7), `[DATA_CORTE]` (comando 11) e DD/MM preenchidos no dia do envio. Nenhum marcador no texto enviado.

## 1. No dia, antes de comecar

- [ ] **Passo 1.** Data e janela preenchidas acima (sem A DEFINIR) e quem acompanha presente; 0a a 0l feitos, com os numeros anotados; aviso enviado; `<commit validado na estacao>` em maos; ninguem gravando cadastro, escala, ausencia ou Mapa PA. Daqui em diante, um shell so, na ordem (as variaveis `E`, `Q` e `C` passam de um comando ao outro).

## 2. Branch, commit gravado e fast-forward

- [ ] **18.** (2) Esperado: `branch OK: codex/modernizacao-ui-backend`. **PARE se:** `PARE: o clone esta em ...`.

```bash
cd /var/www/chronodesk; B=$(sudo git rev-parse --abbrev-ref HEAD); [ "$B" = "codex/modernizacao-ui-backend" ] && echo "branch OK: $B" || echo "PARE: o clone esta em $B, nao em codex/modernizacao-ui-backend"
```

- [ ] **19.** (2) Esperado: antes, troque `<commit validado na estacao>`. O hash gravado, igual ao do comando 17. **PARE se:** `PARE: origin/feat/employee-form-rhf-zod (...) nao e o commit validado na estacao`.

```bash
cd /var/www/chronodesk; sudo git fetch origin feat/employee-form-rhf-zod && K=$(sudo git rev-parse origin/feat/employee-form-rhf-zod) && if [ "$K" = "<commit validado na estacao>" ]; then echo "$K" | sudo tee /var/backups/chronodesk/deploy1-commit; else echo "PARE: origin/feat/employee-form-rhf-zod ($K) nao e o commit validado na estacao"; fi; unset K
```

- [ ] **20.** (2) Esperado: `deploy1-commit OK`. **PARE se:** `PARE: deploy1-commit ausente ou incompleto`.

```bash
cd /var/www/chronodesk; D1=$(sudo cat /var/backups/chronodesk/deploy1-commit); sudo git cat-file -e "$D1^{commit}" && sudo git cat-file -e "$D1:scripts/ensaio-migration.sh" && sudo git cat-file -e "$D1:migrations/20261013_019_registrar_corte.sql" && echo "deploy1-commit OK" || echo "PARE: deploy1-commit ausente ou incompleto"; unset D1
```

- [ ] **21.** (2) Esperado: `fast-forward OK: N commits a aplicar` e `migrations 013, 018 ou 020 no commit: 0`. **PARE se:** `PARE: o HEAD nao e ancestral ...` (nada alterado; opcoes a, b e c do passo 2 do roteiro, decisao do responsavel) ou contagem de migrations maior que `0`.

```bash
cd /var/www/chronodesk; D1=$(sudo cat /var/backups/chronodesk/deploy1-commit); sudo git merge-base --is-ancestor HEAD "$D1" && echo "fast-forward OK: $(sudo git rev-list --count HEAD.."$D1") commits a aplicar" || echo "PARE: o HEAD nao e ancestral do deploy1-commit (o merge --ff-only recusaria)"; echo "migrations 013, 018 ou 020 no commit: $(sudo git ls-tree -r --name-only "$D1" migrations/ | grep -cE '_(013|018|020)_')"; unset D1
```

## 3. Ponto de rollback, `APP_ENV`, preflight e backup

- [ ] **22.** (3a) Esperado: o hash do codigo no ar e `alteracoes locais: 0`. **PARE se:** alteracoes locais maiores que `0` (item 1 de "Atualizacao de Versao").

```bash
cd /var/www/chronodesk; sudo git rev-parse HEAD | sudo tee /var/backups/chronodesk/pre-deploy-commit; echo "alteracoes locais: $(sudo git status --short | wc -l)"
```

- [ ] **23.** (3b) Esperado: so com o comando 13 como esperado (NN7); senao, pule. `copia do .env: ... (600 root)` e `app_env_production: 1`. **PARE se:** `PARE: copia do .env falhou` ou `app_env_production: 0`.

```bash
K=/var/backups/chronodesk/env-antes-app-env-$(date +%Y%m%d-%H%M%S); if sudo install -m 600 /var/www/.env "$K"; then echo "copia do .env: $K ($(sudo stat -c '%a %U' "$K"))"; sudo sed -i 's/^APP_ENV=staging$/APP_ENV=production/' /var/www/.env; echo "app_env_production: $(sudo grep -c '^APP_ENV=production$' /var/www/.env)"; else echo "PARE: copia do .env falhou, nada alterado"; fi; unset K
```

- [ ] **24.** (3c) Esperado: `RESULTADO=0` e `STATUS_PRESENCE_ENABLED: OK (ligado)`. **PARE se:** qualquer `FALHA`; se o comando 23 trocou o `APP_ENV`, volte com o `sed` inverso do passo 3c do roteiro.

```bash
cd /var/www/chronodesk; export D1=$(sudo cat /var/backups/chronodesk/deploy1-commit); sudo git cat-file -e "$D1:scripts/preflight-deploy.sh" && bash -o pipefail -c 'sudo git show "$D1:scripts/preflight-deploy.sh" | sudo sh -s -- /var/www/.env'; unset D1
```

- [ ] **25.** (3d) Esperado: `backup concluido` (anote o diretorio: ponto de restauracao do banco). **PARE se:** sem `backup concluido` (sem ponto de restauracao, as migrations nao rodam).

```bash
sudo systemctl start chronodesk-backup.service; journalctl -u chronodesk-backup -n 5 --no-pager
```

## 4. Migrations 011, 014 e 019 (e o ensaio da 012)

- [ ] **26.** (4a) Esperado: `extraido em /root/ensaio-deploy1.<sufixo>`. **PARE se:** `PARE: falha ao extrair o deploy1-commit`.

```bash
cd /var/www/chronodesk; export D1=$(sudo cat /var/backups/chronodesk/deploy1-commit); E=$(sudo mktemp -d /root/ensaio-deploy1.XXXXXX); export E; bash -o pipefail -c 'sudo git archive "$D1" migrations scripts/ensaio-migration.sh | sudo tar -x -C "$E"' && echo "extraido em $E" || echo "PARE: falha ao extrair o deploy1-commit"
```

- [ ] **27.** (4b, ensaio da 011) Esperado: as duas execucoes iguais; `colaboradores_com_mais_de_uma_aberta`, `sobreposicoes` e `inicio_repetido` em `0`; contagens e assinatura iguais as do estado inicial; o 1o rollback volta e o 2o para de proposito. **PARE se:** `FALHOU` ou saida diferente do esperado.

```bash
sudo env DADOS=portal_schedule_rules,portal_schedule_exceptions VERIFICA="$E/migrations/verificacao/20261005_011_schedule_history_check.sql" bash "$E/scripts/ensaio-migration.sh" d1_011 "$E/migrations/20261005_011_schedule_history.sql" "$E/migrations/rollback/20261005_011_schedule_history_down.sql"
```

- [ ] **28.** (4b) Esperado: `011 aplicada`. **PARE se:** `PARE: a 011 nao foi aplicada`.

```bash
cd /var/www/chronodesk; export D1=$(sudo cat /var/backups/chronodesk/deploy1-commit); read -rsp 'Senha root do MySQL: ' P; echo; export P; sudo git cat-file -e "$D1:migrations/20261005_011_schedule_history.sql" && bash -o pipefail -c 'sudo git show "$D1:migrations/20261005_011_schedule_history.sql" | docker exec -i -e MYSQL_PWD="$P" Chrono_Desk_DB mysql -uroot sistema_pausas' && echo "011 aplicada" || echo "PARE: a 011 nao foi aplicada"; unset P
```

- [ ] **29.** (4b) Esperado: as tres primeiras contagens em `0`. **PARE se:** saida diferente do esperado (desfazer so com o comando 90, com aprovacao).

```bash
read -rsp 'Senha root do MySQL: ' P; echo; docker exec -i -e MYSQL_PWD="$P" Chrono_Desk_DB mysql -uroot --table sistema_pausas < <(sudo cat "$E/migrations/verificacao/20261005_011_schedule_history_check.sql"); unset P
```

- [ ] **30.** (4c, Q-F4) Esperado: referencia `inativos = 5`, `inativos_com_pa = 1`, `inativos_com_regra = 0`, `excecoes_de_inativos = 0` (anote). **PARE se:** nao bloqueia a 014; numeros diferentes: envie ao responsavel antes do comando 67.

```bash
read -rsp 'Senha root do MySQL: ' P; echo; docker exec -i -e MYSQL_PWD="$P" Chrono_Desk_DB mysql -uroot --table sistema_pausas -e "SET time_zone = 'America/Sao_Paulo'; SELECT (SELECT COUNT(*) FROM funcionarios WHERE ativo = 0) AS inativos, (SELECT COUNT(DISTINCT a.employee_id) FROM portal_pa_assignments a JOIN funcionarios f ON f.id = a.employee_id AND f.ativo = 0 WHERE a.active = 1 AND (a.valid_until IS NULL OR a.valid_until >= CURDATE())) AS inativos_com_pa, (SELECT COUNT(DISTINCT r.employee_id) FROM portal_schedule_rules r JOIN funcionarios f ON f.id = r.employee_id AND f.ativo = 0 WHERE (r.effective_until IS NULL OR r.effective_until >= CURDATE()) AND (r.effective_until IS NULL OR r.effective_until >= r.effective_from)) AS inativos_com_regra, (SELECT COUNT(*) FROM portal_schedule_exceptions e JOIN funcionarios f ON f.id = e.employee_id AND f.ativo = 0 WHERE e.exception_date >= CURDATE()) AS excecoes_de_inativos;"; unset P
```

- [ ] **31.** (4d, ensaio da 014) Esperado: como no roteiro do F4, passo 3 (variante sem dados). **PARE se:** `FALHOU` ou saida diferente do esperado.

```bash
sudo env VERIFICA="$E/migrations/verificacao/20261013_014_employee_archive_check.sql" bash "$E/scripts/ensaio-migration.sh" d1_014 "$E/migrations/20261013_014_employee_archive.sql" "$E/migrations/rollback/20261013_014_employee_archive_down.sql"
```

- [ ] **32.** (4d, ensaio da 014) Esperado: como no roteiro do F4, passo 3 (variante com dados). **PARE se:** `FALHOU` ou saida diferente do esperado.

```bash
sudo env DADOS=portal_pa_assignments,portal_schedule_rules,portal_schedule_exceptions,portal_absences VERIFICA="$E/migrations/verificacao/20261013_014_employee_archive_check.sql" bash "$E/scripts/ensaio-migration.sh" d1_014_dados "$E/migrations/20261013_014_employee_archive.sql" "$E/migrations/rollback/20261013_014_employee_archive_down.sql"
```

- [ ] **33.** (4d, ensaio da 014) Esperado: como no roteiro do F4, passo 3 (variante do rollback; o 2o rollback para). **PARE se:** `FALHOU` ou saida diferente do esperado.

```bash
sudo env PREPARO="$E/migrations/20261013_014_employee_archive.sql" VERIFICA="$E/migrations/verificacao/20261013_014_employee_archive_check.sql" bash "$E/scripts/ensaio-migration.sh" d1_014_rollback "$E/migrations/rollback/20261013_014_ensaio_linhas_f4.sql" "$E/migrations/rollback/20261013_014_employee_archive_down.sql"
```

- [ ] **34.** (4d) Esperado: `014 aplicada`. **PARE se:** `PARE: a 014 nao foi aplicada`.

```bash
cd /var/www/chronodesk; export D1=$(sudo cat /var/backups/chronodesk/deploy1-commit); read -rsp 'Senha root do MySQL: ' P; echo; export P; sudo git cat-file -e "$D1:migrations/20261013_014_employee_archive.sql" && bash -o pipefail -c 'sudo git show "$D1:migrations/20261013_014_employee_archive.sql" | docker exec -i -e MYSQL_PWD="$P" Chrono_Desk_DB mysql -uroot sistema_pausas' && echo "014 aplicada" || echo "PARE: a 014 nao foi aplicada"; unset P
```

- [ ] **35.** (4d) Esperado: `colunas_014 = 2`, `check_014 = 1`, `copia_rollback = 0`, `arquivados_f4 = 0`, `inativos_sem_data` = `inativos` do comando 30, `ativos_com_data = 0`, `arquivados_sem_autor = 0`, efeitos pendentes iguais aos do comando 30. **PARE se:** saida diferente do esperado.

```bash
read -rsp 'Senha root do MySQL: ' P; echo; docker exec -i -e MYSQL_PWD="$P" Chrono_Desk_DB mysql -uroot --table sistema_pausas < <(sudo cat "$E/migrations/verificacao/20261013_014_employee_archive_check.sql"); unset P
```

- [ ] **36.** (4e, antes da 019) Esperado: como no comando 9 (`inconsistente = 0`). **PARE se:** `inconsistente = 1`: nao registre o corte, nao altere linha, avise o responsavel.

```bash
read -rsp 'Senha root do MySQL: ' P; echo; docker exec -i -e MYSQL_PWD="$P" Chrono_Desk_DB mysql -uroot --table sistema_pausas < <(sudo cat "$E/migrations/verificacao/20261013_019_presence_cutoff_check.sql"); unset P
```

- [ ] **37.** (4e, ensaio da 019) Esperado: como no roteiro do F5, passo 4. **PARE se:** `FALHOU` ou saida diferente do esperado.

```bash
sudo env VERIFICA="$E/migrations/verificacao/20261013_019_presence_cutoff_check.sql" bash "$E/scripts/ensaio-migration.sh" d1_019 "$E/migrations/20261013_019_presence_cutoff.sql" "$E/migrations/rollback/20261013_019_presence_cutoff_down.sql" audit_log
```

- [ ] **38.** (4e, ensaio da 019) Esperado: o registro de 2099 grava e o 2o para. **PARE se:** `FALHOU` ou saida diferente do esperado.

```bash
{ printf "SET @corte := '2099-12-16'; SET @autor := 'qa.ensaio';\n"; sudo cat "$E/migrations/20261013_019_registrar_corte.sql"; } | sudo tee "$E/registro_d1.sql" > /dev/null; sudo env PREPARO="$E/migrations/20261013_019_presence_cutoff.sql" VERIFICA="$E/migrations/verificacao/20261013_019_presence_cutoff_check.sql" bash "$E/scripts/ensaio-migration.sh" d1_019_registro "$E/registro_d1.sql" "$E/migrations/rollback/20261013_019_presence_cutoff_down.sql" audit_log
```

- [ ] **39.** (4e, ensaio da 019) Esperado: a data passada para nas duas execucoes. **PARE se:** `FALHOU` ou saida diferente do esperado.

```bash
{ printf "SET @corte := '2020-01-16'; SET @autor := 'qa.ensaio';\n"; sudo cat "$E/migrations/20261013_019_registrar_corte.sql"; } | sudo tee "$E/registro_d1_passado.sql" > /dev/null; sudo env PREPARO="$E/migrations/20261013_019_presence_cutoff.sql" VERIFICA="$E/migrations/verificacao/20261013_019_presence_cutoff_check.sql" bash "$E/scripts/ensaio-migration.sh" d1_019_passado "$E/registro_d1_passado.sql" "$E/migrations/rollback/20261013_019_presence_cutoff_down.sql" audit_log
```

- [ ] **40.** (4e) Esperado: `019 aplicada`. **PARE se:** `PARE: a 019 nao foi aplicada`.

```bash
cd /var/www/chronodesk; export D1=$(sudo cat /var/backups/chronodesk/deploy1-commit); read -rsp 'Senha root do MySQL: ' P; echo; export P; sudo git cat-file -e "$D1:migrations/20261013_019_presence_cutoff.sql" && bash -o pipefail -c 'sudo git show "$D1:migrations/20261013_019_presence_cutoff.sql" | docker exec -i -e MYSQL_PWD="$P" Chrono_Desk_DB mysql -uroot sistema_pausas' && echo "019 aplicada" || echo "PARE: a 019 nao foi aplicada"; unset P
```

- [ ] **41.** (4e) Esperado: `tabela_019 = 1`, `checks_019 = 2`, `gatilhos_019 = 2`, `copia_rollback = 0`, `procedimentos_soltos = 0`, `cortes = 0`, `sem_autor = 0`, `eventos_registro = 0`, `eventos_remocao = 0`, `inconsistente = 0`. **PARE se:** saida diferente do esperado.

```bash
read -rsp 'Senha root do MySQL: ' P; echo; docker exec -i -e MYSQL_PWD="$P" Chrono_Desk_DB mysql -uroot --table sistema_pausas < <(sudo cat "$E/migrations/verificacao/20261013_019_presence_cutoff_check.sql"); unset P
```

- [ ] **42.** (4f, ensaio da 012) Esperado: a tabela vazia migra (roteiro do F3, passo 3). **PARE se:** `FALHOU` ou saida diferente do esperado.

```bash
sudo env DADOS=portal_absences VERIFICA="$E/migrations/verificacao/20261007_012_absences_check.sql" bash "$E/scripts/ensaio-migration.sh" d1_012 "$E/migrations/20261007_012_absences.sql" "$E/migrations/rollback/20261007_012_absences_down.sql"
```

- [ ] **43.** (4f, ensaio da 012) Esperado: a linha antiga faz a 012 parar sem alterar nada. **PARE se:** `FALHOU` ou saida diferente do esperado.

```bash
sudo env DADOS=portal_absences PREPARO="$E/migrations/rollback/20261007_012_ensaio_linha_antiga.sql" VERIFICA="$E/migrations/verificacao/20261007_012_absences_check.sql" bash "$E/scripts/ensaio-migration.sh" d1_012_parada "$E/migrations/20261007_012_absences.sql" "$E/migrations/rollback/20261007_012_absences_down.sql"
```

- [ ] **44.** (4f, ensaio da 012) Esperado: o rollback com linhas do F3 volta e o 2o para. **PARE se:** `FALHOU` ou saida diferente do esperado.

```bash
sudo env DADOS=portal_absences PREPARO="$E/migrations/20261007_012_absences.sql" VERIFICA="$E/migrations/verificacao/20261007_012_absences_check.sql" bash "$E/scripts/ensaio-migration.sh" d1_012_rollback "$E/migrations/rollback/20261007_012_ensaio_linhas_f3.sql" "$E/migrations/rollback/20261007_012_absences_down.sql"
```

- [ ] **45.** (4f, Q4) Esperado: contagens por tipo (anote; o comando 65 compara) e `ausencias = 0`. **PARE se:** `ausencias` maior que `0` (a 012 pararia): envie a contagem.

```bash
read -rsp 'Senha root do MySQL: ' P; echo; docker exec -i -e MYSQL_PWD="$P" Chrono_Desk_DB mysql -uroot --table sistema_pausas -e "SET time_zone = 'America/Sao_Paulo'; SELECT exception_type, COUNT(*) AS n, SUM(exception_date >= CURDATE()) AS futuras FROM portal_schedule_exceptions GROUP BY exception_type; SELECT COUNT(*) AS ausencias FROM portal_absences;"; unset P
```

## 5. Ensaio do codigo do commit gravado

- [ ] **46.** (5) Esperado: `/tmp/.env ausente: OK`. **PARE se:** `PARE: existe /tmp/.env`.

```bash
[ ! -e /tmp/.env ] && echo "/tmp/.env ausente: OK" || echo "PARE: existe /tmp/.env; o ensaio leria esse arquivo"
```

- [ ] **47.** (5) Esperado: `extraido em /tmp/ensaio-deploy1-qa.<sufixo>`. **PARE se:** `PARE: falha ao extrair o deploy1-commit`.

```bash
cd /var/www/chronodesk; export D1=$(sudo cat /var/backups/chronodesk/deploy1-commit); Q=$(sudo mktemp -d /tmp/ensaio-deploy1-qa.XXXXXX); export Q; bash -o pipefail -c 'sudo git archive "$D1" | sudo tar -x -C "$Q"' && sudo chown -R www-data: "$Q" && echo "extraido em $Q" || echo "PARE: falha ao extrair o deploy1-commit"
```

- [ ] **48.** (5) Esperado: nenhuma linha antes de `fim do php -l`. **PARE se:** qualquer linha antes de `fim do php -l`.

```bash
sudo find "$Q" -name '*.php' -not -path '*/node_modules/*' -exec sudo -u www-data php -l {} \; | grep -v '^No syntax errors' ; echo "fim do php -l"
```

- [ ] **49.** (5) Esperado: os 16 QAs com `OK`. **PARE se:** algum `FALHOU`: antes do comando 50, envie so o nome do QA e a ultima linha dele (comando no passo 5 do roteiro).

```bash
[ ! -e /tmp/.env ] && for q in qa-smoke qa-auth qa-timezone qa-overtime qa-schedule-history qa-schedule-legacy-output qa-presence-engine qa-absences qa-calendar-text qa-archive qa-presence-cutoff qa-pa-conflicts qa-operational-view qa-employee-lunch qa-security qa-deploy-runbook; do printf '%s: ' "$q"; sudo -u www-data php "$Q/scripts/$q.php" >/dev/null 2>&1 && echo OK || echo FALHOU; done
```

- [ ] **50.** (5) Esperado: `ensaio removido`. **PARE se:** `PARE: ... fora do padrao, nada apagado`.

```bash
case "$Q" in /tmp/ensaio-deploy1-qa.*) sudo rm -r "$Q" && echo "ensaio removido";; *) echo "PARE: $Q fora do padrao, nada apagado";; esac; unset Q
```

## 6. Q-F6, Q-F7, base, 012 com o `merge`, build e recarga

- [ ] **51.** (6a, Q-F6) Esperado: `vinculos_de_inativos = 1` e `vinculos_fora_do_mapa = 0`; os demais como no comando 5. `pas_compartilhados_hoje` maior que o do aviso: avise a gestao (nao bloqueia). **PARE se:** saida diferente do esperado (nada do codigo mudou).

```bash
read -rsp 'Senha root do MySQL: ' P; echo; docker exec -i -e MYSQL_PWD="$P" Chrono_Desk_DB mysql -uroot --table sistema_pausas -e "SET time_zone = 'America/Sao_Paulo'; SELECT (SELECT COUNT(*) FROM portal_pa_inventory WHERE active = 1) AS pas_inventario, (SELECT COUNT(*) FROM portal_pa_assignments WHERE active = 1) AS vinculos_ativos, (SELECT COUNT(*) FROM portal_pa_assignments WHERE active = 1 AND (valid_until IS NULL OR valid_until >= CURDATE())) AS vinculos_vigentes_ou_futuros, (SELECT COUNT(*) FROM (SELECT pa_number FROM portal_pa_assignments WHERE active = 1 AND valid_from <= CURDATE() AND (valid_until IS NULL OR valid_until >= CURDATE()) GROUP BY pa_number HAVING COUNT(*) > 1) t) AS pas_compartilhados_hoje, (SELECT COUNT(*) FROM portal_pa_assignments a JOIN funcionarios f ON f.id = a.employee_id WHERE a.active = 1 AND (a.valid_until IS NULL OR a.valid_until >= CURDATE()) AND f.ativo = 0) AS vinculos_de_inativos, (SELECT COUNT(*) FROM portal_pa_assignments a JOIN funcionarios f ON f.id = a.employee_id WHERE a.active = 1 AND (a.valid_until IS NULL OR a.valid_until >= CURDATE()) AND f.fora_mapa_escala = 1) AS vinculos_fora_do_mapa, (SELECT COUNT(*) FROM portal_pa_assignments WHERE active = 1 AND team = 'lideranca' AND (valid_until IS NULL OR valid_until >= CURDATE())) AS vinculos_lideranca;"; unset P
```

- [ ] **52.** (6a, Q-F6) Esperado: como no comando 6. **PARE se:** saida diferente do esperado.

```bash
read -rsp 'Senha root do MySQL: ' P; echo; docker exec -i -e MYSQL_PWD="$P" Chrono_Desk_DB mysql -uroot --table sistema_pausas -e "SET time_zone = 'America/Sao_Paulo'; SELECT (SELECT COUNT(*) FROM portal_pa_assignments a JOIN portal_pa_assignments b ON b.employee_id = a.employee_id AND b.id > a.id AND b.active = 1 AND b.valid_from <= COALESCE(a.valid_until, '9999-12-31') AND COALESCE(b.valid_until, '9999-12-31') >= a.valid_from WHERE a.active = 1) AS sobreposicoes_mesmo_colaborador, (SELECT COUNT(*) FROM portal_pa_assignments WHERE active = 1 AND valid_until < valid_from) AS periodo_invertido, (SELECT COUNT(*) FROM portal_pa_assignments a WHERE a.active = 1 AND NOT EXISTS (SELECT 1 FROM portal_pa_inventory i WHERE i.active = 1 AND i.pa_number = a.pa_number)) AS vinculos_fora_do_inventario, (SELECT COUNT(*) FROM portal_pa_assignments a LEFT JOIN portal_schedule_rules r ON r.employee_id = a.employee_id AND r.effective_from <= CURDATE() AND (r.effective_until IS NULL OR r.effective_until >= CURDATE()) WHERE a.active = 1 AND a.valid_from <= CURDATE() AND (a.valid_until IS NULL OR a.valid_until >= CURDATE()) AND a.team <> 'lideranca' AND a.schedule_rule_type <> COALESCE(IF(r.rule_type = 'cycle', 'undefined', r.rule_type), 'undefined')) AS copia_tipo_divergente_hoje, (SELECT COUNT(*) FROM portal_pa_assignments WHERE active = 1 AND notes IS NOT NULL AND notes <> '') AS vinculos_com_observacao, (SELECT COUNT(*) FROM portal_pa_map WHERE record_status = 'active') AS legado_total, (SELECT COUNT(*) FROM portal_pa_map WHERE record_status = 'active' AND work_date >= CURDATE()) AS legado_futuro;"; unset P
```

- [ ] **53.** (6a, Q-F7) Esperado: `ativos_outras_equipes`, `jornada_invertida` e `almoco_fora_da_jornada` em `0`. `n1_n2_sem_regra_hoje` maior que o do aviso: avise a gestao (nao bloqueia). **PARE se:** um dos tres maior que `0`.

```bash
read -rsp 'Senha root do MySQL: ' P; echo; docker exec -i -e MYSQL_PWD="$P" Chrono_Desk_DB mysql -uroot --table sistema_pausas -e "SET time_zone = 'America/Sao_Paulo'; SELECT (SELECT COUNT(*) FROM funcionarios WHERE ativo = 1 AND LOWER(TRIM(equipe)) = 'n1') AS ativos_n1, (SELECT COUNT(*) FROM funcionarios WHERE ativo = 1 AND LOWER(TRIM(equipe)) = 'n2') AS ativos_n2, (SELECT COUNT(*) FROM funcionarios WHERE ativo = 1 AND LOWER(TRIM(equipe)) IN ('lideranca', 'liderança', 'na', 'não se aplica', 'nao se aplica', 'nao_se_aplica', 'sem_equipe')) AS ativos_lideranca, (SELECT COUNT(*) FROM funcionarios WHERE ativo = 1 AND LOWER(TRIM(equipe)) NOT IN ('n1', 'n2', 'lideranca', 'liderança', 'na', 'não se aplica', 'nao se aplica', 'nao_se_aplica', 'sem_equipe')) AS ativos_outras_equipes, (SELECT COUNT(*) FROM funcionarios WHERE ativo = 1 AND fora_mapa_escala = 1) AS fora_do_mapa, (SELECT COUNT(*) FROM funcionarios f WHERE f.ativo = 1 AND f.fora_mapa_escala = 0 AND LOWER(TRIM(f.equipe)) IN ('n1', 'n2') AND NOT EXISTS (SELECT 1 FROM portal_schedule_rules r WHERE r.employee_id = f.id AND r.rule_type <> 'undefined' AND r.effective_from <= CURDATE() AND (r.effective_until IS NULL OR r.effective_until >= CURDATE()))) AS n1_n2_sem_regra_hoje, (SELECT COUNT(*) FROM funcionarios WHERE ativo = 1 AND jornada_entrada >= jornada_saida) AS jornada_invertida, (SELECT COUNT(*) FROM funcionarios WHERE ativo = 1 AND NOT (jornada_entrada <= almoco_inicio AND almoco_inicio < almoco_fim AND almoco_fim <= jornada_saida)) AS almoco_fora_da_jornada, (SELECT COUNT(*) FROM portal_absences WHERE status = 'approved' AND starts_on <= CURDATE() AND ends_on >= CURDATE()) AS ausencias_aprovadas_hoje, (SELECT COUNT(*) FROM portal_schedule_exceptions WHERE exception_date = CURDATE() AND exception_type IN ('day_off', 'absence', 'vacation', 'leave')) AS excecoes_de_ausencia_hoje;"; unset P
```

- [ ] **54.** (6a, rotulo) Esperado: `rotulo_que_o_php_nao_reconhece = 0`. **PARE se:** maior que `0`.

```bash
read -rsp 'Senha root do MySQL: ' P; echo; docker exec -i -e MYSQL_PWD="$P" Chrono_Desk_DB mysql -uroot --table sistema_pausas -e "SELECT COUNT(*) AS rotulo_que_o_php_nao_reconhece FROM funcionarios WHERE ativo = 1 AND (CAST(LOWER(TRIM(equipe)) AS BINARY) NOT IN ('n1', 'n2', 'lideranca', 'liderança', 'na', 'não se aplica', 'nao se aplica', 'nao_se_aplica', 'sem_equipe') OR LOCATE(CAST('Ç' AS BINARY), CAST(TRIM(equipe) AS BINARY)) > 0 OR LOCATE(CAST('Ã' AS BINARY), CAST(TRIM(equipe) AS BINARY)) > 0);"; unset P
```

- [ ] **55.** (6a, base) Esperado: p50 e p95 do `status.php` (anote: base do comando 69). **PARE se:** nada (so anotar).

```bash
cd /var/www/chronodesk; D1=$(sudo cat /var/backups/chronodesk/deploy1-commit); sudo git cat-file -e "$D1:scripts/medir-polling.sh" && bash <(sudo git show "$D1:scripts/medir-polling.sh")
```

- [ ] **56.** (6a, base) Esperado: p50 e p95 do `dashboard.php` (anote: base do comando 70). **PARE se:** nada (so anotar).

```bash
sudo git cat-file -e "$D1:scripts/medir-polling.sh" && URL=http://127.0.0.1/api/portal/dashboard.php bash <(sudo git show "$D1:scripts/medir-polling.sh"); unset D1
```

- [ ] **57.** (6b) Esperado: a 012 aplicada e o `merge` em fast-forward, sem erro. **PARE se:** `migration_012 abortada` ou erro do `cat-file`/`git show` (o `merge` nao rodou: envie a contagem de `portal_absences`); a 012 terminou e o `merge` falhou: "Se a verificacao falhar" do roteiro (sem `fetch`, sem `pull`).

```bash
cd /var/www/chronodesk; export D1=$(sudo cat /var/backups/chronodesk/deploy1-commit); read -rsp 'Senha root do MySQL: ' P; echo; export P; sudo git cat-file -e "$D1:migrations/20261007_012_absences.sql" && bash -o pipefail -c 'sudo git show "$D1:migrations/20261007_012_absences.sql" | docker exec -i -e MYSQL_PWD="$P" Chrono_Desk_DB mysql -uroot sistema_pausas' && sudo git merge --ff-only "$D1"; unset P
```

- [ ] **58.** (6b) Esperado: `HEAD = deploy1-commit`. **PARE se:** `PARE: HEAD diferente do deploy1-commit`.

```bash
[ "$(sudo git rev-parse HEAD)" = "$(sudo cat /var/backups/chronodesk/deploy1-commit)" ] && echo "HEAD = deploy1-commit" || echo "PARE: HEAD diferente do deploy1-commit"
```

- [ ] **59.** (6b) Esperado: o `.htaccess`; em `migrations/`, so os arquivos da 011, da 012, do ciclo do F2B (rollback e verificacao), da 014 e da 019; nada em `scripts/backup-chronodesk.sh`. **PARE se:** saida diferente do esperado.

```bash
sudo git diff --stat "$(sudo cat /var/backups/chronodesk/pre-deploy-commit)" HEAD -- migrations/ scripts/backup-chronodesk.sh .htaccess
```

- [ ] **60.** (6b, verificacao da 012) Esperado: `colunas_012 = 10`, `absence_type = 0`, `status_tipo = enum('approved','cancelled')`, `fk = 1`, `indice = 1`, `checks = 2`, `copia_rollback = 0`, `linhas = 0`; na 2a consulta, tudo `0`. **PARE se:** `orfas`, `periodo_invalido` ou `sobreposicoes_aprovadas` maior que `0` (nao apague nem altere linha; incidente) ou saida diferente do esperado.

```bash
read -rsp 'Senha root do MySQL: ' P; echo; docker exec -i -e MYSQL_PWD="$P" Chrono_Desk_DB mysql -uroot --table sistema_pausas < migrations/verificacao/20261007_012_absences_check.sql; unset P
```

- [ ] **61.** (6c) Esperado: build sem erro. **PARE se:** falha no build: degrau 2 (comandos 82 a 86), com aprovacao.

```bash
cd /var/www/chronodesk/frontend && sudo npm ci && sudo npm run build
```

- [ ] **62.** (6c) Esperado: nenhuma saida. **PARE se:** erro na recarga.

```bash
sudo systemctl reload "php$(php -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;')-fpm"
```

## 7. Verificacao pos-deploy

- [ ] **63.** (7a) Esperado: `200`. **PARE se:** diferente de `200`: degrau 2, com aprovacao.

```bash
curl -s -o /dev/null -w '%{http_code}\n' -H 'Host: <ServerName do vhost>' http://127.0.0.1/api/health.php
```

- [ ] **64.** (7a) Esperado: as quatro verificacoes como nos comandos 29, 60, 35 e 41 (019 com `inconsistente = 0`). **PARE se:** `inconsistente = 1` (regra 16a) ou saida diferente do esperado.

```bash
cd /var/www/chronodesk; for v in 20261005_011_schedule_history_check 20261007_012_absences_check 20261013_014_employee_archive_check 20261013_019_presence_cutoff_check; do echo "== $v"; read -rsp 'Senha root do MySQL: ' P; echo; docker exec -i -e MYSQL_PWD="$P" Chrono_Desk_DB mysql -uroot --table sistema_pausas < "migrations/verificacao/$v.sql"; unset P; done
```

- [ ] **65.** (7a, Q4) Esperado: as mesmas contagens do comando 45; no `audit_log`, so os eventos das acoes de hoje (nenhum, se ninguem gravou). **PARE se:** saida diferente do esperado.

```bash
read -rsp 'Senha root do MySQL: ' P; echo; docker exec -i -e MYSQL_PWD="$P" Chrono_Desk_DB mysql -uroot --table sistema_pausas -e "SET time_zone = 'America/Sao_Paulo'; SELECT exception_type, COUNT(*) AS n, SUM(exception_date >= CURDATE()) AS futuras FROM portal_schedule_exceptions GROUP BY exception_type; SELECT action, COUNT(*) AS n FROM audit_log WHERE timestamp >= CURDATE() AND (action LIKE 'ABSENCE\_%' OR action LIKE 'FUNCIONARIO\_%' OR action LIKE 'PA\_MAP\_%' OR action LIKE 'PRESENCE\_CUTOFF\_%') GROUP BY action;"; unset P
```

- [ ] **7b.** Checagem HTTP do `scripts/test-linux.sh` (item 6 de "Atualizacao de Versao"; confere tambem as paginas legadas).

- [ ] **66.** (7c) Esperado: `GET 302` e `POST 410` em todas as paginas, menos `/logout.php` (`GET 410` e `POST 410`); `403` nos dois JS. **PARE se:** `GET 200` numa pagina legada (`AllowOverride`; o rollback e o `.htaccess` anterior).

```bash
for p in / /index.php /login.php /admin_login.php /admin_logout.php /admin.php /metricas.php /logout.php; do echo "$p GET $(curl -k -s -o /dev/null -w '%{http_code}' "<URL>$p") POST $(curl -k -s -o /dev/null -w '%{http_code}' -X POST "<URL>$p")"; done; for j in admin.js admin_users.js; do echo "$j $(curl -k -s -o /dev/null -w '%{http_code}' "<URL>/static/js/$j")"; done
```

- [ ] **7d.** Repita os comandos 51 e 52: os mesmos numeros (diferencas batem com os `PA_MAP_*` do comando 65).
- [ ] **7e.** Repita os comandos 53 e 54: os mesmos numeros.

- [ ] **67.** (7f, D5) Esperado: so com os numeros do comando 30 iguais a referencia. So o ID interno; depois, como admin, Funcionarios > "Arquivados" > "Arquivar (regularizar)" na linha com esse ID: "Cadastro inativo regularizado e arquivado. 1 vinculo de PA encerrado." (ou "removido"). **PARE se:** numeros do comando 30 diferentes da referencia: avise antes de arquivar.

```bash
read -rsp 'Senha root do MySQL: ' P; echo; docker exec -i -e MYSQL_PWD="$P" Chrono_Desk_DB mysql -uroot --table sistema_pausas -e "SET time_zone = 'America/Sao_Paulo'; SELECT DISTINCT a.employee_id AS id_cadastro FROM portal_pa_assignments a JOIN funcionarios f ON f.id = a.employee_id AND f.ativo = 0 WHERE a.active = 1 AND (a.valid_until IS NULL OR a.valid_until >= CURDATE());"; unset P
```

- [ ] **68.** (7f) Esperado: `inativos_com_pa = 0`, `arquivados_f4 = 1`, `inativos_sem_data = 4`, `ativos_com_data = 0`, `arquivados_sem_autor = 0`. **PARE se:** `ativos_com_data` ou `arquivados_sem_autor` maior que `0` (incidente) ou saida diferente do esperado.

```bash
read -rsp 'Senha root do MySQL: ' P; echo; docker exec -i -e MYSQL_PWD="$P" Chrono_Desk_DB mysql -uroot --table sistema_pausas < /var/www/chronodesk/migrations/verificacao/20261013_014_employee_archive_check.sql; unset P
```

- [ ] **7g.** Repita o comando 51: `vinculos_de_inativos = 0` e `vinculos_fora_do_mapa = 0`. **PARE se:** diferente.
- [ ] **7h.** O responsavel marca pela tela os cadastros fora do Mapa (so admin) e repete o comando 53 (`fora_do_mapa` cresce na mesma conta).
- [ ] **7i.** Navegador, por perfil (admin, Lideranca, gestor, tecnico; 1280 px e 390 px): lista do roteiro, 7i. Anote so numeros.

- [ ] **69.** (7j) Esperado: p50 e p95 do `status.php` (anote na 9.9). **PARE se:** p95 mais de 50 ms **e** mais de 20% acima do comando 55: degrau 1 (comandos 76 a 79) e medir de novo.

```bash
cd /var/www/chronodesk; bash scripts/medir-polling.sh
```

- [ ] **70.** (7j) Esperado: p50 e p95 do `dashboard.php` (anote na 9.9). **PARE se:** p95 mais de 50 ms **e** mais de 20% acima do comando 56: degrau 1 e medir de novo.

```bash
cd /var/www/chronodesk; URL=http://127.0.0.1/api/portal/dashboard.php bash scripts/medir-polling.sh
```

- [ ] **71.** (7k) Esperado: `corte planejado` igual ao do comando 11 (diferente: o deploy passou do dia 16; a data nova vale, avise a gestao). **PARE se:** `PARE: data do corte invalida`.

```bash
read -rsp 'Senha root do MySQL: ' P; echo; C=$(docker exec -i -e MYSQL_PWD="$P" Chrono_Desk_DB mysql -uroot -N sistema_pausas -e "SET time_zone = 'America/Sao_Paulo'; SELECT IF(DAYOFMONTH(CURDATE()) >= 16, DATE_FORMAT(CURDATE() + INTERVAL 1 MONTH, '%Y-%m-16'), DATE_FORMAT(CURDATE(), '%Y-%m-16'));"); unset P; [[ "$C" =~ ^[0-9]{4}-[0-9]{2}-16$ ]] && echo "corte planejado: $C" || echo "PARE: data do corte invalida ($C)"
```

- [ ] **72.** (7k) Esperado: `sem_regra_no_corte = 0` e `sem_regra_na_competencia = 0`. **PARE se:** qualquer um maior que `0`: o comando 73 nao roda (o deploy fica no ar sem corte).

```bash
read -rsp 'Senha root do MySQL: ' P; echo; docker exec -i -e MYSQL_PWD="$P" Chrono_Desk_DB mysql -uroot --table sistema_pausas -e "SET @corte := '$C'; WITH RECURSIVE dias (d) AS (SELECT CAST(@corte AS DATE) UNION ALL SELECT d + INTERVAL 1 DAY FROM dias WHERE d < @corte + INTERVAL 1 MONTH - INTERVAL 1 DAY) SELECT COUNT(*) AS ativos_n1_n2, COALESCE(SUM(NOT EXISTS (SELECT 1 FROM portal_schedule_rules r WHERE r.employee_id = f.id AND r.rule_type <> 'undefined' AND r.effective_from <= @corte AND (r.effective_until IS NULL OR r.effective_until >= @corte))), 0) AS sem_regra_no_corte, COALESCE(SUM(EXISTS (SELECT 1 FROM dias WHERE NOT EXISTS (SELECT 1 FROM portal_schedule_rules r WHERE r.employee_id = f.id AND r.rule_type <> 'undefined' AND r.effective_from <= dias.d AND (r.effective_until IS NULL OR r.effective_until >= dias.d)))), 0) AS sem_regra_na_competencia FROM funcionarios f WHERE f.ativo = 1 AND f.fora_mapa_escala = 0 AND LOWER(f.equipe) IN ('n1', 'n2');"; unset P
```

- [ ] **73.** (7l) Esperado: so com o comando 72 em `0`, `inconsistente = 0` e o `C` do comando 71 na mesma sessao; antes, troque `<login do operador>`. `corte a registrar: <C>` e nenhuma outra mensagem. **PARE se:** `registro_019 abortado: ...` (nada gravado): confira o `C`, `DROP PROCEDURE IF EXISTS registrar_corte_019` e envie a mensagem.

```bash
cd /var/www/chronodesk; echo "corte a registrar: $C"; read -rsp 'Senha root do MySQL: ' P; echo; { printf "SET @corte := '%s'; SET @autor := '%s';\n" "$C" '<login do operador>'; cat migrations/20261013_019_registrar_corte.sql; } | docker exec -i -e MYSQL_PWD="$P" Chrono_Desk_DB mysql -uroot sistema_pausas; unset P
```

- [ ] **74.** (7l) Esperado: `cortes = 1`, `corte` = o `C`, `corte_dia_16 = 1`, `corte_no_futuro = 1`, `sem_autor = 0`, `eventos_registro = 1`, `eventos_remocao = 0`, `procedimentos_soltos = 0`, `inconsistente = 0`. **PARE se:** `inconsistente = 1` (regra 16a) ou saida diferente do esperado.

```bash
read -rsp 'Senha root do MySQL: ' P; echo; docker exec -i -e MYSQL_PWD="$P" Chrono_Desk_DB mysql -uroot --table sistema_pausas < migrations/verificacao/20261013_019_presence_cutoff_check.sql; unset P
```

- [ ] **75.** (7m) Esperado: `ensaio das migrations removido`. **PARE se:** `PARE: ... fora do padrao, nada apagado`.

```bash
case "$E" in /root/ensaio-deploy1.*) sudo rm -r "$E" && echo "ensaio das migrations removido";; *) echo "PARE: $E fora do padrao, nada apagado";; esac; unset E D1
```

## 8. Desligamento rapido e rollback em degraus (so se preciso)

Do degrau mais baixo para o mais alto; os degraus 2 e 3 so com aprovacao previa do responsavel. Riscos de cada degrau: roteiro, passo 8.

- [ ] **76.** (8a, degrau 1) Esperado: nada acima de `fim (esperado: nada acima)`. **PARE se:** alguma linha (a variavel no pool do PHP-FPM venceria o `.env`).

```bash
sudo grep -rn 'STATUS_PRESENCE_ENABLED' /etc/php/*/fpm/ ; echo "fim (esperado: nada acima)"
```

- [ ] **77.** (8a) Esperado: `copia do .env: ... (600 root)`. **PARE se:** `PARE: copia do .env falhou, nada alterado`.

```bash
K=/var/backups/chronodesk/env-antes-interruptor-$(date +%Y%m%d-%H%M%S); if sudo install -m 600 /var/www/.env "$K"; then echo "copia do .env: $K ($(sudo stat -c '%a %U' "$K"))"; if sudo grep -q '^STATUS_PRESENCE_ENABLED=' /var/www/.env; then sudo sed -i 's/^STATUS_PRESENCE_ENABLED=.*/STATUS_PRESENCE_ENABLED=false/' /var/www/.env; else echo 'STATUS_PRESENCE_ENABLED=false' | sudo tee -a /var/www/.env >/dev/null; fi; else echo "PARE: copia do .env falhou, nada alterado"; fi; unset K
```

- [ ] **78.** (8a) Esperado: `STATUS_PRESENCE_ENABLED: OK (desligado)`. **PARE se:** saida diferente do esperado.

```bash
cd /var/www/chronodesk && sudo sh scripts/preflight-deploy.sh /var/www/.env | grep STATUS_PRESENCE_ENABLED
```

- [ ] **79.** (8a) Esperado: nenhuma saida. Para religar: os comandos 77 a 79 com `true` (roteiro, 8a). **PARE se:** erro na recarga.

```bash
sudo systemctl reload "php$(php -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;')-fpm"
```

- [ ] **80.** (8a, limpeza das copias) Esperado: so com aprovacao do responsavel. As copias, modo `600`, dono `root` (so nome, modo e dono). **PARE se:** saida diferente do esperado.

```bash
sudo find /var/backups/chronodesk -maxdepth 1 -type f \( -name 'env-antes-interruptor-*' -o -name 'env-antes-app-env-*' \) -printf '%f %m %u\n'
```

- [ ] **81.** (8a, limpeza das copias) Esperado: as mesmas copias ao apagar; o comando 80 de novo nao mostra nada. **PARE se:** saida diferente do esperado.

```bash
sudo find /var/backups/chronodesk -maxdepth 1 -type f \( -name 'env-antes-interruptor-*' -o -name 'env-antes-app-env-*' \) -print -delete
```

- [ ] **82.** (8b, degrau 2) Esperado: so com aprovacao previa do responsavel. `regras_cycle` (anote; maior que `0`: rode o 83 antes do 84). **PARE se:** saida diferente do esperado.

```bash
cd /var/www/chronodesk; export D1=$(sudo cat /var/backups/chronodesk/deploy1-commit); read -rsp 'Senha root do MySQL: ' P; echo; export P; sudo git cat-file -e "$D1:migrations/verificacao/20261007_f2b_cycle_check.sql" && bash -o pipefail -c 'sudo git show "$D1:migrations/verificacao/20261007_f2b_cycle_check.sql" | docker exec -i -e MYSQL_PWD="$P" Chrono_Desk_DB mysql -uroot --table sistema_pausas'; unset P
```

- [ ] **83.** (8b) Esperado: so com `regras_cycle` maior que `0`. `ciclo trocado por undefined`. **PARE se:** sem a mensagem.

```bash
cd /var/www/chronodesk; export D1=$(sudo cat /var/backups/chronodesk/deploy1-commit); read -rsp 'Senha root do MySQL: ' P; echo; export P; sudo git cat-file -e "$D1:migrations/rollback/20261007_f2b_cycle_down.sql" && bash -o pipefail -c 'sudo git show "$D1:migrations/rollback/20261007_f2b_cycle_down.sql" | docker exec -i -e MYSQL_PWD="$P" Chrono_Desk_DB mysql -uroot sistema_pausas' && echo "ciclo trocado por undefined"; unset P
```

- [ ] **84.** (8b) Esperado: `codigo anterior no ar: <hash curto>`. **PARE se:** sem a mensagem.

```bash
cd /var/www/chronodesk; sudo git checkout --detach "$(sudo cat /var/backups/chronodesk/pre-deploy-commit)" && echo "codigo anterior no ar: $(sudo git rev-parse --short HEAD)"
```

- [ ] **85.** (8b) Esperado: build sem erro. **PARE se:** falha no build: avise o responsavel.

```bash
cd /var/www/chronodesk/frontend && sudo npm ci && sudo npm run build
```

- [ ] **86.** (8b) Esperado: nenhuma saida. **PARE se:** erro na recarga.

```bash
sudo systemctl reload "php$(php -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;')-fpm"
```

- [ ] **87.** (8c, degrau 3) Esperado: so com aprovacao e depois do degrau 2. `rollback da 019 aplicado`. **PARE se:** sem a mensagem (nao rodou; copia `*_bkp_019` ja existente: renomear e repetir).

```bash
cd /var/www/chronodesk; export D1=$(sudo cat /var/backups/chronodesk/deploy1-commit); read -rsp 'Senha root do MySQL: ' P; echo; export P; sudo git cat-file -e "$D1:migrations/rollback/20261013_019_presence_cutoff_down.sql" && bash -o pipefail -c 'sudo git show "$D1:migrations/rollback/20261013_019_presence_cutoff_down.sql" | docker exec -i -e MYSQL_PWD="$P" Chrono_Desk_DB mysql -uroot sistema_pausas' && echo "rollback da 019 aplicado"; unset P
```

- [ ] **88.** (8c) Esperado: `rollback da 014 aplicado`. **PARE se:** sem a mensagem (copia `*_bkp_014` ja existente: renomear e repetir).

```bash
cd /var/www/chronodesk; export D1=$(sudo cat /var/backups/chronodesk/deploy1-commit); read -rsp 'Senha root do MySQL: ' P; echo; export P; sudo git cat-file -e "$D1:migrations/rollback/20261013_014_employee_archive_down.sql" && bash -o pipefail -c 'sudo git show "$D1:migrations/rollback/20261013_014_employee_archive_down.sql" | docker exec -i -e MYSQL_PWD="$P" Chrono_Desk_DB mysql -uroot sistema_pausas' && echo "rollback da 014 aplicado"; unset P
```

- [ ] **89.** (8c) Esperado: so com `linhas = 0` na verificacao da 012. `rollback da 012 aplicado`. **PARE se:** sem a mensagem (copia `*_bkp_012` ja existente: renomear e repetir).

```bash
cd /var/www/chronodesk; export D1=$(sudo cat /var/backups/chronodesk/deploy1-commit); read -rsp 'Senha root do MySQL: ' P; echo; export P; sudo git cat-file -e "$D1:migrations/rollback/20261007_012_absences_down.sql" && bash -o pipefail -c 'sudo git show "$D1:migrations/rollback/20261007_012_absences_down.sql" | docker exec -i -e MYSQL_PWD="$P" Chrono_Desk_DB mysql -uroot sistema_pausas' && echo "rollback da 012 aplicado"; unset P
```

- [ ] **90.** (8c) Esperado: `rollback da 011 aplicado`. **PARE se:** sem a mensagem (copia `*_bkp_011` ja existente: renomear e repetir).

```bash
cd /var/www/chronodesk; export D1=$(sudo cat /var/backups/chronodesk/deploy1-commit); read -rsp 'Senha root do MySQL: ' P; echo; export P; sudo git cat-file -e "$D1:migrations/rollback/20261005_011_schedule_history_down.sql" && bash -o pipefail -c 'sudo git show "$D1:migrations/rollback/20261005_011_schedule_history_down.sql" | docker exec -i -e MYSQL_PWD="$P" Chrono_Desk_DB mysql -uroot sistema_pausas' && echo "rollback da 011 aplicado"; unset P
```

## 9. Depois de um ciclo estavel, com o responsavel

- [ ] **91.** (9a, estacao) Esperado: push do responsavel, depois de um ciclo estavel; antes, troque `<commit validado na estacao>`. A linha `<antigo>..<novo>` com `-> codex/modernizacao-ui-backend`. **PARE se:** `rejected` ou `non-fast-forward`: nunca `--force`; o responsavel decide.

```powershell
git push origin <commit validado na estacao>:codex/modernizacao-ui-backend
```

- [ ] **9b.** Contagem dos logs de novo (comando 15): so `302`, `410` e `403` desde o deploy. A remocao das paginas legadas e do responsavel, no repositorio, num commit proprio (roteiro, 9b).
