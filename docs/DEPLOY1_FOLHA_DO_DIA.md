# Deploy 1 da Parte B: folha de execucao do dia

Gerada do roteiro "Deploy 1 da Parte B (roteiro unico e consolidado ...)" do `DEPLOY_LINUX.md`: os mesmos comandos, na mesma ordem (a trava `scripts/qa-deploy-runbook.php` confere). O roteiro e a referencia: o detalhe de cada esperado, as opcoes e os riscos estao la; o que muda desde o `596db67`, em `docs/DEPLOY1_INVENTARIO.md`. Mudou o roteiro, muda esta folha no mesmo commit.

**Sem valor real nesta folha**, nem impressa nem anotada nela: nada de senha, IP, hostname, nome ou login. Os marcadores `<...>` sao trocados so no terminal, na hora; os numeros vao para o desenho (9.9, 9.12 e 9.13), nunca para chat ou chamado. A senha do root do MySQL e sempre digitada no `read -rsp` (sem eco).

Data, janela e quem acompanha (iguais as do roteiro; a trava confere):
- **Data e janela do deploy**, fora do horario de operacao: A DEFINIR (a de 11/11/2026, das 19:00 as 22:00, foi cancelada em 2026-11-09: o pre-requisito 0a deu PARE no servidor; tempo estimado e viabilidade de 13/11 no inventario, secao 9).
- **Quem acompanha** (alem de quem executa): o responsavel pela infraestrutura da VM.

**Regras de parada em toda a folha:** qualquer linha com `PARE`, ou `FALHOU` num ensaio onde nao era esperado: pare e envie so a contagem ou a mensagem. Verificacao da 019 com `inconsistente = 1`: pare, nao registre o corte, nao apague nem altere linha e avise o responsavel. Corte do BIZ-02 com `ABORTADO PELAS GUARDAS`: pare em manutencao e avise. Nada de `git pull`, e nenhum `git fetch` depois do comando 28. Onde o "PARE se" diz "saida diferente do esperado", pare e confira o roteiro antes de seguir.

## 0. Antes do dia, com o responsavel

O responsavel publica o `feat/employee-form-rhf-zod` antes do 0a. Anote os numeros: os passos seguintes comparam.

- [ ] **1.** (0a) Esperado: `branch: codex/modernizacao-ui-backend`, `HEAD no ar: 596db67 (OK)` (ou ancestral, anote o hash curto), `commits a aplicar` maior que `280`, `alteracoes locais: 1`, `alteracoes fora do .htaccess: 0`, `.htaccess` com `6` e `0`. **PARE se:** linha `PARE`, outro branch, alteracao fora do `.htaccess` ou outra contagem no `.htaccess` (traga os numeros).

```bash
cd /var/www/chronodesk; sudo git fetch origin feat/employee-form-rhf-zod; H=$(sudo git rev-parse HEAD); echo "branch: $(sudo git rev-parse --abbrev-ref HEAD)"; if [ "$H" = "596db67c67a9cd84e53b28aaef4c3316191fe87c" ]; then echo "HEAD no ar: 596db67 (OK)"; elif sudo git merge-base --is-ancestor "$H" origin/feat/employee-form-rhf-zod; then echo "HEAD no ar: ancestral do branch do deploy (OK; hash curto $(sudo git rev-parse --short HEAD))"; else echo "PARE: o HEAD do servidor nao e o 596db67 nem ancestral do branch do deploy"; fi; echo "commits a aplicar: $(sudo git rev-list --count "$H"..origin/feat/employee-form-rhf-zod)"; echo "alteracoes locais: $(sudo git status --porcelain | wc -l)"; echo "alteracoes fora do .htaccess: $(sudo git status --porcelain | grep -vc '^ M \.htaccess$')"; echo ".htaccess, linhas acrescentadas e removidas: $(sudo git diff --numstat -- .htaccess | cut -f1,2)"; unset H
```

- [ ] **2.** (0a) Esperado: `mysql` 8.0.29 ou mais novo; `migration_015 = 0`, `tabela_019 = 0`, `tabelas_manutencao = 0`, `tabelas = 33`. **PARE se:** qualquer numero diferente (traga os numeros).

```bash
read -rsp 'Senha root do MySQL: ' P; echo; docker exec -i -e MYSQL_PWD="$P" Chrono_Desk_DB mysql -uroot --table sistema_pausas -e "SELECT VERSION() AS mysql, (SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'login_user_throttle') AS migration_015, (SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'portal_presence_cutoff') AS tabela_019, (SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME LIKE 'manutencao\_%') AS tabelas_manutencao, (SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()) AS tabelas;"; unset P
```

- [ ] **3.** (0a) Esperado: `rotina de backup instalada: 0`; o diretorio de backup como estiver (anote); `arquivo de manutencao: ausente`. **PARE se:** `arquivo de manutencao: PRESENTE` (o portal ficaria em 503 no `merge`).

```bash
echo "rotina de backup instalada: $(systemctl list-unit-files chronodesk-backup.service 2>/dev/null | grep -c '^chronodesk-backup')"; echo "diretorio /var/backups/chronodesk: $(sudo test -d /var/backups/chronodesk && echo existe || echo ausente)"; echo "arquivo de manutencao: $(test -e /var/www/chronodesk.maintenance && echo PRESENTE || echo ausente)"
```

- [ ] **4.** (0b) Esperado: `access_role_005 = 1`, `ad_login_repetidos = 0`, `uq_ad_login = 0`, `perfil_016b = 0`, `anexo_team = 0`, `anexo_reference_month = 1`, `uq_regra_por_colaborador = 1`, `rule_type_010 = 1`, `rule_config_010 = 1`, `team_regras_n1_n2 = 1`, `team_excecoes_n1_n2 = 1`, `colunas_011_014 = 0`, `absence_type = 1`, `colunas_012 = 0`, `ausencias = 0`, `copias_de_rollback = 0`, `copias_de_junho = 4`, `nao_innodb = 0`, `minutos_fora_de_utc = 0`, `fusos_nomeados` maior que `0`. **PARE se:** qualquer numero diferente (cada coluna diz qual migration nao encontraria o "antes"; roteiro, 0b).

```bash
read -rsp 'Senha root do MySQL: ' P; echo; docker exec -i -e MYSQL_PWD="$P" Chrono_Desk_DB mysql -uroot --table sistema_pausas -e "SELECT (SELECT COLUMN_TYPE = 'enum(''tecnico'',''gestor'',''admin'',''somente_leitura'')' FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'funcionarios' AND COLUMN_NAME = 'access_role') AS access_role_005, (SELECT COUNT(*) FROM (SELECT ad_login FROM funcionarios WHERE ad_login IS NOT NULL GROUP BY ad_login HAVING COUNT(*) > 1) d) AS ad_login_repetidos, (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'funcionarios' AND INDEX_NAME = 'uq_funcionarios_ad_login') AS uq_ad_login, (SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'funcionarios_perfil_016b') AS perfil_016b, (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'portal_shift_attachments' AND COLUMN_NAME = 'team') AS anexo_team, (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'portal_shift_attachments' AND COLUMN_NAME = 'reference_month') AS anexo_reference_month, (SELECT COUNT(DISTINCT INDEX_NAME) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'portal_schedule_rules' AND INDEX_NAME = 'uq_schedule_rule_employee') AS uq_regra_por_colaborador, (SELECT LOCATE('fixed_weekdays', COLUMN_TYPE) > 0 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'portal_schedule_rules' AND COLUMN_NAME = 'rule_type') AS rule_type_010, (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'portal_schedule_rules' AND COLUMN_NAME = 'rule_config') AS rule_config_010, (SELECT COLUMN_TYPE = 'enum(''n1'',''n2'')' FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'portal_schedule_rules' AND COLUMN_NAME = 'team') AS team_regras_n1_n2, (SELECT COLUMN_TYPE = 'enum(''n1'',''n2'')' FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'portal_schedule_exceptions' AND COLUMN_NAME = 'team') AS team_excecoes_n1_n2, (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'funcionarios' AND COLUMN_NAME IN ('fora_mapa_escala', 'arquivado_em', 'arquivado_por')) AS colunas_011_014, (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'portal_absences' AND COLUMN_NAME = 'absence_type') AS absence_type, (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'portal_absences' AND COLUMN_NAME IN ('category', 'period_type', 'cancelled_by')) AS colunas_012, (SELECT COUNT(*) FROM portal_absences) AS ausencias, (SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME LIKE '%\_bkp\_0%') AS copias_de_rollback, (SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME REGEXP '_[0-9]{8}_[0-9]{6}$') AS copias_de_junho, (SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = 'BASE TABLE' AND (ENGINE IS NULL OR ENGINE <> 'InnoDB')) AS nao_innodb, ABS(TIMESTAMPDIFF(MINUTE, UTC_TIMESTAMP(), NOW())) AS minutos_fora_de_utc, (SELECT COUNT(*) FROM mysql.time_zone_name) AS fusos_nomeados;"; unset P
```

- [ ] **5.** (0c) Esperado: `audience` so com os valores padrao (roteiro, 0c); nenhum `synced` nem `sync_error`; nenhum lancamento de 24 h. **PARE se:** nao para; valor de `audience` fora do padrao ou lancamento de 24 h pendente: decidir antes do dia.

```bash
read -rsp 'Senha root do MySQL: ' P; echo; docker exec -i -e MYSQL_PWD="$P" Chrono_Desk_DB mysql -uroot --table sistema_pausas -e "SET time_zone = 'America/Sao_Paulo'; SELECT audience, COUNT(*) AS avisos FROM portal_announcements GROUP BY audience; SELECT 'horas_extras' AS tabela, status, COUNT(*) AS n FROM portal_overtime_entries GROUP BY status UNION ALL SELECT 'correcao_ponto', status, COUNT(*) FROM portal_time_adjustments GROUP BY status; SELECT status, COUNT(*) AS lancamentos_de_24h FROM portal_overtime_entries WHERE start_time = end_time GROUP BY status;"; unset P
```

- [ ] **6.** (0d) Esperado: p50 e p95 do `status.php` (anote; 2 ou 3 rodadas, a mesma conta, sem `CONSULTAS`, `N=200`, aba fechada). **PARE se:** `unknown revision` ou `Not a valid object name`: avise o responsavel antes do dia (o passo 2 depende deste `fetch`).

```bash
cd /var/www/chronodesk; sudo git fetch origin feat/employee-form-rhf-zod && sudo git cat-file -e origin/feat/employee-form-rhf-zod:scripts/medir-polling.sh && bash <(sudo git show origin/feat/employee-form-rhf-zod:scripts/medir-polling.sh)
```

- [ ] **7.** (0d) Esperado: p50 e p95 do `dashboard.php` (anote; registre na 9.9). **PARE se:** nada (so anotar).

```bash
sudo git cat-file -e origin/feat/employee-form-rhf-zod:scripts/medir-polling.sh && URL=http://127.0.0.1/api/portal/dashboard.php bash <(sudo git show origin/feat/employee-form-rhf-zod:scripts/medir-polling.sh)
```

- [ ] **8.** (0e) Esperado: contagens anotadas; referencia `pas_compartilhados_hoje = 6`, `vinculos_de_inativos = 1`, `vinculos_lideranca = 2`. `[N_PAS]` = `pas_compartilhados_hoje`. **PARE se:** nada (so anotar).

```bash
read -rsp 'Senha root do MySQL: ' P; echo; docker exec -i -e MYSQL_PWD="$P" Chrono_Desk_DB mysql -uroot --table sistema_pausas -e "SET time_zone = 'America/Sao_Paulo'; SELECT (SELECT COUNT(*) FROM portal_pa_inventory WHERE active = 1) AS pas_inventario, (SELECT COUNT(*) FROM portal_pa_assignments WHERE active = 1) AS vinculos_ativos, (SELECT COUNT(*) FROM portal_pa_assignments WHERE active = 1 AND (valid_until IS NULL OR valid_until >= CURDATE())) AS vinculos_vigentes_ou_futuros, (SELECT COUNT(*) FROM (SELECT pa_number FROM portal_pa_assignments WHERE active = 1 AND valid_from <= CURDATE() AND (valid_until IS NULL OR valid_until >= CURDATE()) GROUP BY pa_number HAVING COUNT(*) > 1) t) AS pas_compartilhados_hoje, (SELECT COUNT(*) FROM portal_pa_assignments a JOIN funcionarios f ON f.id = a.employee_id WHERE a.active = 1 AND (a.valid_until IS NULL OR a.valid_until >= CURDATE()) AND f.ativo = 0) AS vinculos_de_inativos, (SELECT COUNT(*) FROM portal_pa_assignments WHERE active = 1 AND team = 'lideranca' AND (valid_until IS NULL OR valid_until >= CURDATE())) AS vinculos_lideranca;"; unset P
```

- [ ] **9.** (0e) Esperado: `sobreposicoes_mesmo_colaborador = 0`, `periodo_invertido = 0`, `vinculos_fora_do_inventario = 0`. **PARE se:** um dos tres maior que `0` (dado antigo: decidir antes do dia).

```bash
read -rsp 'Senha root do MySQL: ' P; echo; docker exec -i -e MYSQL_PWD="$P" Chrono_Desk_DB mysql -uroot --table sistema_pausas -e "SET time_zone = 'America/Sao_Paulo'; SELECT (SELECT COUNT(*) FROM portal_pa_assignments a JOIN portal_pa_assignments b ON b.employee_id = a.employee_id AND b.id > a.id AND b.active = 1 AND b.valid_from <= COALESCE(a.valid_until, '9999-12-31') AND COALESCE(b.valid_until, '9999-12-31') >= a.valid_from WHERE a.active = 1) AS sobreposicoes_mesmo_colaborador, (SELECT COUNT(*) FROM portal_pa_assignments WHERE active = 1 AND valid_until < valid_from) AS periodo_invertido, (SELECT COUNT(*) FROM portal_pa_assignments a WHERE a.active = 1 AND NOT EXISTS (SELECT 1 FROM portal_pa_inventory i WHERE i.active = 1 AND i.pa_number = a.pa_number)) AS vinculos_fora_do_inventario, (SELECT COUNT(*) FROM portal_pa_assignments a LEFT JOIN portal_schedule_rules r ON r.employee_id = a.employee_id AND r.effective_from <= CURDATE() AND (r.effective_until IS NULL OR r.effective_until >= CURDATE()) WHERE a.active = 1 AND a.valid_from <= CURDATE() AND (a.valid_until IS NULL OR a.valid_until >= CURDATE()) AND a.team <> 'lideranca' AND a.schedule_rule_type <> COALESCE(IF(r.rule_type = 'cycle', 'undefined', r.rule_type), 'undefined')) AS copia_tipo_divergente_hoje, (SELECT COUNT(*) FROM portal_pa_assignments WHERE active = 1 AND notes IS NOT NULL AND notes <> '') AS vinculos_com_observacao, (SELECT COUNT(*) FROM portal_pa_map WHERE record_status = 'active') AS legado_total, (SELECT COUNT(*) FROM portal_pa_map WHERE record_status = 'active' AND work_date >= CURDATE()) AS legado_futuro;"; unset P
```

- [ ] **10.** (0f) Esperado: referencia `ativos_n1 = 13`, `ativos_n2 = 9`, `ativos_lideranca = 3`; `ativos_outras_equipes`, `jornada_invertida` e `almoco_fora_da_jornada` em `0`. `[N_SEM_REGRA]` = `n1_n2_sem_regra_hoje`. Num dia util, no expediente. **PARE se:** um dos tres maior que `0` (corrigir o cadastro pela tela antes do dia).

```bash
read -rsp 'Senha root do MySQL: ' P; echo; docker exec -i -e MYSQL_PWD="$P" Chrono_Desk_DB mysql -uroot --table sistema_pausas -e "SET time_zone = 'America/Sao_Paulo'; SELECT (SELECT COUNT(*) FROM funcionarios WHERE ativo = 1 AND LOWER(TRIM(equipe)) = 'n1') AS ativos_n1, (SELECT COUNT(*) FROM funcionarios WHERE ativo = 1 AND LOWER(TRIM(equipe)) = 'n2') AS ativos_n2, (SELECT COUNT(*) FROM funcionarios WHERE ativo = 1 AND LOWER(TRIM(equipe)) IN ('lideranca', 'liderança', 'na', 'não se aplica', 'nao se aplica', 'nao_se_aplica', 'sem_equipe')) AS ativos_lideranca, (SELECT COUNT(*) FROM funcionarios WHERE ativo = 1 AND LOWER(TRIM(equipe)) NOT IN ('n1', 'n2', 'lideranca', 'liderança', 'na', 'não se aplica', 'nao se aplica', 'nao_se_aplica', 'sem_equipe')) AS ativos_outras_equipes, (SELECT COUNT(*) FROM funcionarios f WHERE f.ativo = 1 AND LOWER(TRIM(f.equipe)) IN ('n1', 'n2') AND NOT EXISTS (SELECT 1 FROM portal_schedule_rules r WHERE r.employee_id = f.id AND r.rule_type <> 'undefined' AND r.effective_from <= CURDATE() AND (r.effective_until IS NULL OR r.effective_until >= CURDATE()))) AS n1_n2_sem_regra_hoje, (SELECT COUNT(*) FROM funcionarios WHERE ativo = 1 AND jornada_entrada >= jornada_saida) AS jornada_invertida, (SELECT COUNT(*) FROM funcionarios WHERE ativo = 1 AND NOT (jornada_entrada <= almoco_inicio AND almoco_inicio < almoco_fim AND almoco_fim <= jornada_saida)) AS almoco_fora_da_jornada, (SELECT COUNT(*) FROM portal_absences WHERE status = 'approved' AND starts_on <= CURDATE() AND ends_on >= CURDATE()) AS ausencias_aprovadas_hoje, (SELECT COUNT(*) FROM portal_schedule_exceptions WHERE exception_date = CURDATE() AND exception_type IN ('day_off', 'absence', 'vacation', 'leave')) AS excecoes_de_ausencia_hoje;"; unset P
```

- [ ] **11.** (0f) Esperado: `rotulo_que_o_php_nao_reconhece = 0`. **PARE se:** maior que `0` (corrigir o cadastro pela tela antes do dia).

```bash
read -rsp 'Senha root do MySQL: ' P; echo; docker exec -i -e MYSQL_PWD="$P" Chrono_Desk_DB mysql -uroot --table sistema_pausas -e "SELECT COUNT(*) AS rotulo_que_o_php_nao_reconhece FROM funcionarios WHERE ativo = 1 AND (CAST(LOWER(TRIM(equipe)) AS BINARY) NOT IN ('n1', 'n2', 'lideranca', 'liderança', 'na', 'não se aplica', 'nao se aplica', 'nao_se_aplica', 'sem_equipe') OR LOCATE(CAST('Ç' AS BINARY), CAST(TRIM(equipe) AS BINARY)) > 0 OR LOCATE(CAST('Ã' AS BINARY), CAST(TRIM(equipe) AS BINARY)) > 0);"; unset P
```

- [ ] **12.** (0g) Esperado: `tabela_019 = 0`, `gatilhos_019 = 0`, `copia_rollback = 0`, `eventos_registro = 0`, `eventos_remocao = 0`, `inconsistente = 0`. **PARE se:** `inconsistente = 1` (regra 16a) ou saida diferente do esperado.

```bash
cd /var/www/chronodesk; read -rsp 'Senha root do MySQL: ' P; echo; export P; sudo git cat-file -e origin/feat/employee-form-rhf-zod:migrations/verificacao/20261013_019_presence_cutoff_check.sql && bash -o pipefail -c 'sudo git show origin/feat/employee-form-rhf-zod:migrations/verificacao/20261013_019_presence_cutoff_check.sql | docker exec -i -e MYSQL_PWD="$P" Chrono_Desk_DB mysql -uroot --table sistema_pausas'; unset P
```

- [ ] **13.** (0h) Esperado: `usuario_lido = 1`, `concessoes_drop = 0`, `concessoes_trigger = 0`. **PARE se:** `usuario_lido = 0`. Concessoes maiores que `0` nao bloqueiam: decisao do responsavel antes do dia (VULN-002).

```bash
U=$(sudo grep -E '^DB_USER=' /var/www/.env | cut -d= -f2- | tr -d "\"'"); read -rsp 'Senha root do MySQL: ' P; echo; docker exec -i -e MYSQL_PWD="$P" Chrono_Desk_DB mysql -uroot --table -e "SELECT '$U' <> '' AS usuario_lido, (SELECT COUNT(*) FROM (SELECT GRANTEE, PRIVILEGE_TYPE FROM information_schema.USER_PRIVILEGES UNION ALL SELECT GRANTEE, PRIVILEGE_TYPE FROM information_schema.SCHEMA_PRIVILEGES UNION ALL SELECT GRANTEE, PRIVILEGE_TYPE FROM information_schema.TABLE_PRIVILEGES) g WHERE g.GRANTEE LIKE CONCAT('''', '$U', '''@%') AND g.PRIVILEGE_TYPE = 'DROP') AS concessoes_drop, (SELECT COUNT(*) FROM (SELECT GRANTEE, PRIVILEGE_TYPE FROM information_schema.USER_PRIVILEGES UNION ALL SELECT GRANTEE, PRIVILEGE_TYPE FROM information_schema.SCHEMA_PRIVILEGES UNION ALL SELECT GRANTEE, PRIVILEGE_TYPE FROM information_schema.TABLE_PRIVILEGES) g WHERE g.GRANTEE LIKE CONCAT('''', '$U', '''@%') AND g.PRIVILEGE_TYPE = 'TRIGGER') AS concessoes_trigger;"; unset P U
```

- [ ] **14.** (0i) Esperado: antes, troque `<AAAA-MM-DD do deploy>` pela data do deploy (acima, em AAAA-MM-DD). `corte planejado: AAAA-MM-16` pela D2 (deploy ate o dia 15: dia 16 do mesmo mes; senao, do mes seguinte); anote, e o `[DATA_CORTE]`. **PARE se:** `PARE: data do corte invalida`.

```bash
D='<AAAA-MM-DD do deploy>'; if [ "$(date -d "$D" +%d)" -ge 16 ]; then C=$(date -d "$(date -d "$D" +%Y-%m-01) +1 month" +%Y-%m-16); else C=$(date -d "$D" +%Y-%m-16); fi; [[ "$C" =~ ^[0-9]{4}-[0-9]{2}-16$ ]] && echo "corte planejado: $C" || echo "PARE: data do corte invalida ($C)"
```

- [ ] **15.** (0i) Esperado: referencia `ativos_n1_n2 = 22`; anote `sem_regra_no_corte` e `sem_regra_na_competencia` (o ideal e `0`). **PARE se:** nao para aqui; maior que `0` no dia impede o registro do corte (comando 103).

```bash
read -rsp 'Senha root do MySQL: ' P; echo; docker exec -i -e MYSQL_PWD="$P" Chrono_Desk_DB mysql -uroot --table sistema_pausas -e "SET @corte := '$C'; WITH RECURSIVE dias (d) AS (SELECT CAST(@corte AS DATE) UNION ALL SELECT d + INTERVAL 1 DAY FROM dias WHERE d < @corte + INTERVAL 1 MONTH - INTERVAL 1 DAY) SELECT COUNT(*) AS ativos_n1_n2, COALESCE(SUM(NOT EXISTS (SELECT 1 FROM portal_schedule_rules r WHERE r.employee_id = f.id AND r.rule_type <> 'undefined' AND r.effective_from <= @corte AND (r.effective_until IS NULL OR r.effective_until >= @corte))), 0) AS sem_regra_no_corte, COALESCE(SUM(EXISTS (SELECT 1 FROM dias WHERE NOT EXISTS (SELECT 1 FROM portal_schedule_rules r WHERE r.employee_id = f.id AND r.rule_type <> 'undefined' AND r.effective_from <= dias.d AND (r.effective_until IS NULL OR r.effective_until >= dias.d)))), 0) AS sem_regra_na_competencia FROM funcionarios f WHERE f.ativo = 1 AND LOWER(f.equipe) IN ('n1', 'n2');"; unset P
```

- [ ] **16.** (0j) Esperado: `app_env=staging`, `secret_key_caracteres` 32 ou mais, `secret_key_texto_de_exemplo=0`. Confira a olho no `.env`: `AD_ADMIN_USERS` com o login do responsavel, `ENABLE_LOCAL_ADMIN` desligado, `HEALTH_ALLOWED_IPS` com a monitoracao, se houver. **PARE se:** saida diferente do esperado: no dia, pule o comando 34 (nao troque o `APP_ENV`).

```bash
sudo awk -F= '/^APP_ENV=/{print "app_env=" $2} /^SECRET_KEY=/{v=substr($0, 12); gsub(/["\047]/, "", v); print "secret_key_caracteres=" length(v); print "secret_key_texto_de_exemplo=" (tolower(v) ~ /gere_uma_chave|troque|changeme|change_me/)}' /var/www/.env
```

- [ ] **17.** (0k, estacao) Esperado: a versao (8.19 ou mais nova; antes, `gitleaks detect`) e `no leaks found`. **PARE se:** qualquer achado: nao siga (revogar, gerar outro, tirar do historico).

```powershell
git fetch origin; gitleaks version; gitleaks git --redact --log-opts="596db67c67a9cd84e53b28aaef4c3316191fe87c..origin/feat/employee-form-rhf-zod" .
```

- [ ] **18.** (0l) Esperado: so contagens por metodo, caminho e codigo (anote; registre na 9.12). **PARE se:** nada; uso maior que `0` nas paginas `POST`: avise no aviso a gestao.

```bash
sudo sh -c 'cat /var/log/apache2/chronodesk_access.log /var/log/apache2/chronodesk_access.log.1 2>/dev/null; zcat /var/log/apache2/chronodesk_access.log.*.gz 2>/dev/null' | awk '{print $6, $7, $9}' | sed -E 's/\?[^ ]*//' | grep -E '^"(GET|POST) /(admin|admin_login|admin_logout|login|logout|metricas|index)\.php |^"GET /static/js/admin(_users)?\.js ' | sort | uniq -c
```

- [ ] **19.** (0l) Esperado: `GET 302` no `/`; as demais, como hoje (anote). **PARE se:** nada (so anotar); o comando 96 repete com o esperado novo.

```bash
for p in / /index.php /login.php /admin_login.php /admin_logout.php /admin.php /metricas.php /logout.php; do echo "$p GET $(curl -k -s -o /dev/null -w '%{http_code}' "<URL>$p") POST $(curl -k -s -o /dev/null -w '%{http_code}' -X POST "<URL>$p")"; done; for j in admin.js admin_users.js; do echo "$j $(curl -k -s -o /dev/null -w '%{http_code}' "<URL>/static/js/$j")"; done
```

- [ ] **20.** (0m) Esperado: `banco_mb` (anote). **PARE se:** nada (so anotar).

```bash
read -rsp 'Senha root do MySQL: ' P; echo; docker exec -i -e MYSQL_PWD="$P" Chrono_Desk_DB mysql -uroot --table -e "SELECT ROUND(SUM(DATA_LENGTH + INDEX_LENGTH) / 1048576, 1) AS banco_mb FROM information_schema.TABLES WHERE TABLE_SCHEMA = 'sistema_pausas';"; unset P
```

- [ ] **21.** (0m) Esperado: os dois espacos livres com pelo menos tres vezes o `banco_mb`. **PARE se:** menos que isso (liberar espaco: infraestrutura); `df` ausente no conteiner: confira pelo host.

```bash
echo "livre em /var/backups (MB): $(df -BM --output=avail /var/backups | tail -n 1 | tr -d ' M')"; echo "livre no datadir do MySQL (MB): $(docker exec Chrono_Desk_DB df -BM --output=avail /var/lib/mysql | tail -n 1 | tr -d ' M')"
```

- [ ] **22.** (0n, copia) Esperado: `copia em ensaio_biz02: OK`. **PARE se:** `PARE: copia falhou`: rode o comando 25 e avise.

```bash
read -rsp 'Senha root do MySQL: ' P; echo; export P; docker exec -i -e MYSQL_PWD="$P" Chrono_Desk_DB mysql -uroot -e "CREATE DATABASE ensaio_biz02 CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;" && bash -o pipefail -c 'docker exec -i -e MYSQL_PWD="$P" Chrono_Desk_DB mysqldump -uroot --single-transaction --skip-triggers --no-tablespaces --set-gtid-purged=OFF sistema_pausas | docker exec -i -e MYSQL_PWD="$P" Chrono_Desk_DB mysql -uroot ensaio_biz02' && echo "copia em ensaio_biz02: OK" || echo "PARE: copia falhou (rode o ultimo comando deste passo)"; unset P
```

- [ ] **23.** (0n) Esperado: `tabelas`, `funcionarios`, `pausas` e `audit_log` iguais na producao e na copia (o `audit_log` pode ter uma linha a mais na producao). **PARE se:** outra diferenca: rode o comando 25 e avise.

```bash
read -rsp 'Senha root do MySQL: ' P; echo; docker exec -i -e MYSQL_PWD="$P" Chrono_Desk_DB mysql -uroot --table -e "SELECT 'tabelas' AS item, (SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = 'sistema_pausas') AS producao, (SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = 'ensaio_biz02') AS copia UNION ALL SELECT 'funcionarios', (SELECT COUNT(*) FROM sistema_pausas.funcionarios), (SELECT COUNT(*) FROM ensaio_biz02.funcionarios) UNION ALL SELECT 'pausas', (SELECT COUNT(*) FROM sistema_pausas.pausas), (SELECT COUNT(*) FROM ensaio_biz02.pausas) UNION ALL SELECT 'audit_log', (SELECT COUNT(*) FROM sistema_pausas.audit_log), (SELECT COUNT(*) FROM ensaio_biz02.audit_log);"; unset P
```

- [ ] **24.** (0n, BIZ-02) Esperado: `CORTE REGISTRADO` com `g1`, `g2` e `g3` em `0` (anote a contagem por coluna); script 2 antes com `corrigida = 0` e `decisao_antes_do_pedido = 0`; `CORRECAO EXECUTADA` e `nao_corrigidas = 0`; script 2 depois com `a_corrigir = 0`, `no_futuro = 0`, `decisao_antes_do_pedido = 0`, `utc_3h_adiante = 0`; `ROLLBACK EXECUTADO`; script 2 por fim igual ao antes. V8 e V9: so quantas linhas. **PARE se:** `ABORTADO PELAS GUARDAS`, `PARE: ... falhou` ou `decisao_antes_do_pedido` maior que `0` depois do script 3: rode o comando 25 e avise (analise antes do deploy).

```bash
cd /var/www/chronodesk; read -rsp 'Senha root do MySQL: ' P; echo; export P; for S in biz02_01_registrar_corte biz02_02_verificar biz02_03_corrigir biz02_02_verificar biz02_04_rollback biz02_02_verificar; do export S; echo "=== $S"; sudo git cat-file -e "origin/feat/employee-form-rhf-zod:migrations/correcao_dados/$S.sql" && bash -o pipefail -c 'sudo git show "origin/feat/employee-form-rhf-zod:migrations/correcao_dados/$S.sql" | docker exec -i -e MYSQL_PWD="$P" Chrono_Desk_DB mysql -uroot --table ensaio_biz02' || { echo "PARE: $S falhou"; break; }; done; unset P; unset S
```

- [ ] **25.** (0n, limpeza) Esperado: sempre, tambem depois de um `PARE`. `copia_restante = 0`. **PARE se:** `copia_restante` maior que `0`: avise (dados pessoais na copia).

```bash
read -rsp 'Senha root do MySQL: ' P; echo; docker exec -i -e MYSQL_PWD="$P" Chrono_Desk_DB mysql -uroot --table -e "DROP DATABASE IF EXISTS ensaio_biz02; SELECT COUNT(*) AS copia_restante FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = 'ensaio_biz02';"; unset P
```

- [ ] **26.** (0o, estacao) Esperado: `Validacao local concluida com sucesso`, roteiro de navegador com `falharam: 0` e o hash completo: anote como `<commit validado na estacao>`. **PARE se:** qualquer falha: nao ha deploy com esse commit.

```powershell
git fetch origin; git worktree add --detach ..\chronodesk-deploy1 origin/feat/employee-form-rhf-zod; Set-Location ..\chronodesk-deploy1\frontend; npm ci; Set-Location ..; $env:CHROME_PATH='C:\Program Files\Google\Chrome\Application\chrome.exe'; powershell -NoProfile -ExecutionPolicy Bypass -File scripts\test-local.ps1 -SkipHttp -Browser; git rev-parse HEAD
```

- [ ] **0p.** Aviso unico a gestao enviado (texto no roteiro, 0p), com `[N_PAS]` (comando 8), `[N_SEM_REGRA]` (comando 10), `[DATA_CORTE]` (comando 14) e DD/MM preenchidos no dia do envio. Nenhum marcador no texto enviado. Quem usa o CSV de horas extras em outra ferramenta tambem recebe.

## 1. No dia, antes de comecar

- [ ] **Passo 1.** Data e janela definidas no roteiro (com a data **A DEFINIR, nao comece**) e quem acompanha presente; 0a a 0p feitos, com os numeros anotados; o ensaio do BIZ-02 (comando 24) sem guarda disparada e com a copia apagada (comando 25); aviso enviado; `<commit validado na estacao>` em maos; ninguem gravando cadastro, escala, ausencia ou Mapa PA. Daqui em diante, um shell so, na ordem (as variaveis `E`, `Q`, `B` e `C` passam de um comando ao outro).

## 2. Branch, commit gravado, fast-forward e regras locais do `.htaccess`

- [ ] **27.** (2) Esperado: `branch OK: codex/modernizacao-ui-backend`. **PARE se:** `PARE: o clone esta em ...`.

```bash
cd /var/www/chronodesk; B=$(sudo git rev-parse --abbrev-ref HEAD); [ "$B" = "codex/modernizacao-ui-backend" ] && echo "branch OK: $B" || echo "PARE: o clone esta em $B, nao em codex/modernizacao-ui-backend"; unset B
```

- [ ] **28.** (2) Esperado: antes, troque `<commit validado na estacao>`. O hash gravado, igual ao do comando 26. **PARE se:** `PARE: origin/feat/employee-form-rhf-zod (...) nao e o commit validado na estacao`.

```bash
cd /var/www/chronodesk; sudo git fetch origin feat/employee-form-rhf-zod && K=$(sudo git rev-parse origin/feat/employee-form-rhf-zod) && if [ "$K" = "<commit validado na estacao>" ]; then echo "$K" | sudo tee /var/backups/chronodesk/deploy1-commit; else echo "PARE: origin/feat/employee-form-rhf-zod ($K) nao e o commit validado na estacao"; fi; unset K
```

- [ ] **29.** (2) Esperado: `deploy1-commit OK`. **PARE se:** `PARE: deploy1-commit ausente ou incompleto`.

```bash
cd /var/www/chronodesk; D1=$(sudo cat /var/backups/chronodesk/deploy1-commit); sudo git cat-file -e "$D1^{commit}" && sudo git cat-file -e "$D1:scripts/ensaio-migration.sh" && sudo git cat-file -e "$D1:migrations/20261013_019_registrar_corte.sql" && sudo git cat-file -e "$D1:migrations/correcao_dados/biz02_01_registrar_corte.sql" && echo "deploy1-commit OK" || echo "PARE: deploy1-commit ausente ou incompleto"; unset D1
```

- [ ] **30.** (2) Esperado: `fast-forward OK: N commits a aplicar` (o `N` do comando 1) e `migrations 013, 018 ou 020 no commit: 0`. **PARE se:** `PARE: o HEAD nao e ancestral ...` (nada alterado; opcoes a, b e c do passo 2 do roteiro, decisao do responsavel) ou contagem de migrations maior que `0`.

```bash
cd /var/www/chronodesk; D1=$(sudo cat /var/backups/chronodesk/deploy1-commit); sudo git merge-base --is-ancestor HEAD "$D1" && echo "fast-forward OK: $(sudo git rev-list --count HEAD.."$D1") commits a aplicar" || echo "PARE: o HEAD nao e ancestral do deploy1-commit (o merge --ff-only recusaria)"; echo "migrations 013, 018 ou 020 no commit: $(sudo git ls-tree -r --name-only "$D1" migrations/ | grep -cE '_(013|018|020)_')"; unset D1
```

- [ ] **31.** (2, `.htaccess`) Esperado: `regras locais no .htaccess do commit: 3` e, antes de `fim das linhas locais ...`, nenhuma linha ou so uma regra `^$` para `/app/`. **PARE se:** outra linha listada (o `merge` descartaria uma regra que o servidor usa).

```bash
cd /var/www/chronodesk; D1=$(sudo cat /var/backups/chronodesk/deploy1-commit); echo "regras locais no .htaccess do commit: $(sudo git show "$D1:.htaccess" | grep -cxF -e 'RewriteBase /' -e 'RewriteRule ^var/www/chronodesk(/.*)?$ /app/ [R=302,L]' -e 'RewriteRule ^chronodesk(/.*)?$ /app/ [R=302,L]')"; sudo git diff -U0 -- .htaccess | sed -n 's/^+\([^+].*\)$/\1/p' | sed 's/^[[:space:]]*//; s/[[:space:]]*$//' | grep -v -e '^$' -e '^#' | grep -vxF --file=<(sudo git show "$D1:.htaccess" | sed 's/^[[:space:]]*//; s/[[:space:]]*$//'); echo "fim das linhas locais que o commit nao tem"; unset D1
```

## 3. Ponto de rollback, copia do `.htaccess` local, `APP_ENV`, preflight e backup

- [ ] **32.** (3a) Esperado: o hash do `596db67` e `alteracoes locais: 1; fora do .htaccess: 0`. **PARE se:** alteracao fora do `.htaccess`.

```bash
cd /var/www/chronodesk; sudo install -d -o root -g root -m 700 /var/backups/chronodesk; sudo git rev-parse HEAD | sudo tee /var/backups/chronodesk/pre-deploy-commit; echo "alteracoes locais: $(sudo git status --porcelain | wc -l); fora do .htaccess: $(sudo git status --porcelain | grep -vc '^ M \.htaccess$')"
```

- [ ] **33.** (3a) Esperado: `copia do .htaccess local: ... (... root)` (ou `ja existe e e igual`). **PARE se:** `PARE: ... existe e e diferente` ou `PARE: copia do .htaccess falhou`.

```bash
cd /var/www/chronodesk; H=/var/backups/chronodesk/htaccess-local-antes-deploy1; if sudo test -e "$H"; then sudo cmp -s .htaccess "$H" && echo "copia do .htaccess local ja existe e e igual: $H" || echo "PARE: $H existe e e diferente do .htaccess atual"; else sudo cp -p .htaccess "$H" && sudo cmp -s .htaccess "$H" && echo "copia do .htaccess local: $H ($(sudo stat -c '%a %U' "$H"))" || echo "PARE: copia do .htaccess falhou"; fi; unset H
```

- [ ] **34.** (3b) Esperado: so com o comando 16 como esperado (NN7); senao, pule. `copia do .env: ... (600 root)` e `app_env_production: 1`. **PARE se:** `PARE: copia do .env falhou` ou `app_env_production: 0`.

```bash
K=/var/backups/chronodesk/env-antes-app-env-$(date +%Y%m%d-%H%M%S); if sudo install -m 600 /var/www/.env "$K"; then echo "copia do .env: $K ($(sudo stat -c '%a %U' "$K"))"; sudo sed -i 's/^APP_ENV=staging$/APP_ENV=production/' /var/www/.env; echo "app_env_production: $(sudo grep -c '^APP_ENV=production$' /var/www/.env)"; else echo "PARE: copia do .env falhou, nada alterado"; fi; unset K
```

- [ ] **35.** (3c) Esperado: `RESULTADO=0`, com `AD_ADMIN_USERS: OK`, `ENABLE_LOCAL_ADMIN: OK`, `STATUS_PRESENCE_ENABLED: OK (ligado)` e `FORCE_HTTPS: OK` (`AUTH_SOURCE: AVISO` nao reprova). **PARE se:** qualquer `FALHA`; se o comando 34 trocou o `APP_ENV`, volte com o `sed` inverso do passo 3c do roteiro.

```bash
cd /var/www/chronodesk; export D1=$(sudo cat /var/backups/chronodesk/deploy1-commit); sudo git cat-file -e "$D1:scripts/preflight-deploy.sh" && bash -o pipefail -c 'sudo git show "$D1:scripts/preflight-deploy.sh" | sudo sh -s -- /var/www/.env'; unset D1
```

- [ ] **36.** (3d) Esperado: `backup concluido: /var/backups/chronodesk/deploy1-<data> (<tamanho>)` (anote o diretorio: ponto de restauracao do banco). **PARE se:** `PARE: backup falhou` (sem ponto de restauracao, as migrations nao rodam).

```bash
B=/var/backups/chronodesk/deploy1-$(date +%Y%m%d-%H%M%S); export B; read -rsp 'Senha root do MySQL: ' P; echo; export P; sudo install -d -o root -g root -m 700 "$B" && bash -o pipefail -c 'docker exec -i -e MYSQL_PWD="$P" Chrono_Desk_DB mysqldump -uroot --single-transaction --routines --triggers --no-tablespaces --set-gtid-purged=OFF sistema_pausas | gzip | sudo tee "$B/database.sql.gz" > /dev/null' && sudo gzip -t "$B/database.sql.gz" && sudo tar -czf "$B/estado.tar.gz" -C /var/www/chronodesk --ignore-failed-read estado.json pausas.csv config_sistema.json funcionarios.json && sudo sh -c 'cd "$1" && sha256sum database.sql.gz estado.tar.gz > SHA256SUMS' _ "$B" && echo "backup concluido: $B ($(sudo du -sh "$B" | cut -f1))" || echo "PARE: backup falhou (sem ponto de restauracao, as migrations nao rodam)"; unset P
```

## 4. Migrations antes do codigo: 015, 016, 017, 011, 014 e 019 (e os ensaios da 016b e da 012)

- [ ] **37.** (4a) Esperado: `extraido em /root/ensaio-deploy1.<sufixo>`. **PARE se:** `PARE: falha ao extrair o deploy1-commit`.

```bash
cd /var/www/chronodesk; export D1=$(sudo cat /var/backups/chronodesk/deploy1-commit); E=$(sudo mktemp -d /root/ensaio-deploy1.XXXXXX); export E; bash -o pipefail -c 'sudo git archive "$D1" migrations scripts/ensaio-migration.sh | sudo tar -x -C "$E"' && echo "extraido em $E" || echo "PARE: falha ao extrair o deploy1-commit"
```

- [ ] **38.** (4b, ensaio da 015) Esperado: a tabela aparece nas duas execucoes e some nos dois rollbacks; `funcionarios` sem mudanca. **PARE se:** `FALHOU` ou saida diferente do esperado.

```bash
sudo bash "$E/scripts/ensaio-migration.sh" d1_015 "$E/migrations/20261001_015_login_user_throttle.sql" "$E/migrations/rollback/20261001_015_login_user_throttle_down.sql"
```

- [ ] **39.** (4b) Esperado: `015 aplicada`. **PARE se:** `PARE: a 015 nao foi aplicada`.

```bash
cd /var/www/chronodesk; export D1=$(sudo cat /var/backups/chronodesk/deploy1-commit); read -rsp 'Senha root do MySQL: ' P; echo; export P; sudo git cat-file -e "$D1:migrations/20261001_015_login_user_throttle.sql" && bash -o pipefail -c 'sudo git show "$D1:migrations/20261001_015_login_user_throttle.sql" | docker exec -i -e MYSQL_PWD="$P" Chrono_Desk_DB mysql -uroot sistema_pausas' && echo "015 aplicada" || echo "PARE: a 015 nao foi aplicada"; unset P
```

- [ ] **40.** (4b) Esperado: `migration_015 = 1`, `linhas_015 = 0`. **PARE se:** saida diferente do esperado.

```bash
read -rsp 'Senha root do MySQL: ' P; echo; docker exec -i -e MYSQL_PWD="$P" Chrono_Desk_DB mysql -uroot --table sistema_pausas -e "SELECT (SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'login_user_throttle') AS migration_015, (SELECT COUNT(*) FROM login_user_throttle) AS linhas_015;"; unset P
```

- [ ] **41.** (4c, ensaio da 016) Esperado: as duas execucoes iguais (`lideranca` no ENUM, indice unico); o rollback volta ao checksum e as contagens iniciais, o 2o tambem. **PARE se:** `migration_016_abortada_ad_login_duplicado`, `FALHOU` ou saida diferente do esperado.

```bash
sudo bash "$E/scripts/ensaio-migration.sh" d1_016 "$E/migrations/20261002_016_lideranca_profile.sql" "$E/migrations/rollback/20261002_016_lideranca_profile_down.sql"
```

- [ ] **42.** (4c, ensaio da 016b) Esperado: depois da 1a execucao, os `admin` da Lideranca como `lideranca` e o `admin` inativo fora dela como `tecnico`, um evento de cada (anote as contagens por equipe, perfil e situacao: o comando 86 compara); a 2a nao muda nada; o rollback volta. **PARE se:** `FALHOU` ou saida diferente do esperado.

```bash
sudo env PREPARO="$E/migrations/20261002_016_lideranca_profile.sql" bash "$E/scripts/ensaio-migration.sh" d1_016b "$E/migrations/20261002_016b_lideranca_profile_data.sql" "$E/migrations/rollback/20261002_016b_lideranca_profile_data_down.sql" audit_log
```

- [ ] **43.** (4c) Esperado: `016 aplicada`. **PARE se:** `PARE: a 016 nao foi aplicada` (com `migration_016_abortada_ad_login_duplicado`, nada foi alterado).

```bash
cd /var/www/chronodesk; export D1=$(sudo cat /var/backups/chronodesk/deploy1-commit); read -rsp 'Senha root do MySQL: ' P; echo; export P; sudo git cat-file -e "$D1:migrations/20261002_016_lideranca_profile.sql" && bash -o pipefail -c 'sudo git show "$D1:migrations/20261002_016_lideranca_profile.sql" | docker exec -i -e MYSQL_PWD="$P" Chrono_Desk_DB mysql -uroot sistema_pausas' && echo "016 aplicada" || echo "PARE: a 016 nao foi aplicada"; unset P
```

- [ ] **44.** (4c) Esperado: `uq_ad_login = 1`, `access_role_com_lideranca = 1`, `cadastros_lideranca = 0`. **PARE se:** saida diferente do esperado.

```bash
read -rsp 'Senha root do MySQL: ' P; echo; docker exec -i -e MYSQL_PWD="$P" Chrono_Desk_DB mysql -uroot --table sistema_pausas -e "SELECT (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'funcionarios' AND INDEX_NAME = 'uq_funcionarios_ad_login') AS uq_ad_login, (SELECT LOCATE('''lideranca''', COLUMN_TYPE) > 0 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'funcionarios' AND COLUMN_NAME = 'access_role') AS access_role_com_lideranca, (SELECT COUNT(*) FROM funcionarios WHERE access_role = 'lideranca') AS cadastros_lideranca;"; unset P
```

- [ ] **45.** (4d, ensaio da 017) Esperado: a coluna `team` e o indice aparecem nas duas execucoes e somem nos dois rollbacks. **PARE se:** `FALHOU` ou saida diferente do esperado.

```bash
sudo bash "$E/scripts/ensaio-migration.sh" d1_017 "$E/migrations/20261002_017_shift_attachment_team.sql" "$E/migrations/rollback/20261002_017_shift_attachment_team_down.sql" portal_shift_attachments
```

- [ ] **46.** (4d) Esperado: `017 aplicada`. **PARE se:** `PARE: a 017 nao foi aplicada`.

```bash
cd /var/www/chronodesk; export D1=$(sudo cat /var/backups/chronodesk/deploy1-commit); read -rsp 'Senha root do MySQL: ' P; echo; export P; sudo git cat-file -e "$D1:migrations/20261002_017_shift_attachment_team.sql" && bash -o pipefail -c 'sudo git show "$D1:migrations/20261002_017_shift_attachment_team.sql" | docker exec -i -e MYSQL_PWD="$P" Chrono_Desk_DB mysql -uroot sistema_pausas' && echo "017 aplicada" || echo "PARE: a 017 nao foi aplicada"; unset P
```

- [ ] **47.** (4d) Esperado: `migration_017 = 1`, `indice_017 = 1`, `anexos_com_equipe = 0`. **PARE se:** saida diferente do esperado.

```bash
read -rsp 'Senha root do MySQL: ' P; echo; docker exec -i -e MYSQL_PWD="$P" Chrono_Desk_DB mysql -uroot --table sistema_pausas -e "SELECT (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'portal_shift_attachments' AND COLUMN_NAME = 'team') AS migration_017, (SELECT COUNT(DISTINCT INDEX_NAME) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'portal_shift_attachments' AND INDEX_NAME = 'idx_shift_attachment_team') AS indice_017, (SELECT COUNT(*) FROM portal_shift_attachments WHERE team IS NOT NULL) AS anexos_com_equipe;"; unset P
```

- [ ] **48.** (4e, ensaio da 011) Esperado: as duas execucoes iguais; `colaboradores_com_mais_de_uma_aberta`, `sobreposicoes` e `inicio_repetido` em `0`; contagens e assinatura iguais as do estado inicial; o 1o rollback volta e o 2o para de proposito. **PARE se:** `FALHOU` ou saida diferente do esperado.

```bash
sudo env DADOS=portal_schedule_rules,portal_schedule_exceptions VERIFICA="$E/migrations/verificacao/20261005_011_schedule_history_check.sql" bash "$E/scripts/ensaio-migration.sh" d1_011 "$E/migrations/20261005_011_schedule_history.sql" "$E/migrations/rollback/20261005_011_schedule_history_down.sql"
```

- [ ] **49.** (4e) Esperado: `011 aplicada`. **PARE se:** `PARE: a 011 nao foi aplicada`.

```bash
cd /var/www/chronodesk; export D1=$(sudo cat /var/backups/chronodesk/deploy1-commit); read -rsp 'Senha root do MySQL: ' P; echo; export P; sudo git cat-file -e "$D1:migrations/20261005_011_schedule_history.sql" && bash -o pipefail -c 'sudo git show "$D1:migrations/20261005_011_schedule_history.sql" | docker exec -i -e MYSQL_PWD="$P" Chrono_Desk_DB mysql -uroot sistema_pausas' && echo "011 aplicada" || echo "PARE: a 011 nao foi aplicada"; unset P
```

- [ ] **50.** (4e) Esperado: as tres primeiras contagens em `0`. **PARE se:** saida diferente do esperado (desfazer so com o comando 122, com aprovacao).

```bash
read -rsp 'Senha root do MySQL: ' P; echo; docker exec -i -e MYSQL_PWD="$P" Chrono_Desk_DB mysql -uroot --table sistema_pausas < <(sudo cat "$E/migrations/verificacao/20261005_011_schedule_history_check.sql"); unset P
```

- [ ] **51.** (4f, Q-F4) Esperado: referencia `inativos = 5`, `inativos_com_pa = 1`, `inativos_com_regra = 0`, `excecoes_de_inativos = 0` (anote). **PARE se:** nao bloqueia a 014; numeros diferentes: envie ao responsavel antes do comando 97.

```bash
read -rsp 'Senha root do MySQL: ' P; echo; docker exec -i -e MYSQL_PWD="$P" Chrono_Desk_DB mysql -uroot --table sistema_pausas -e "SET time_zone = 'America/Sao_Paulo'; SELECT (SELECT COUNT(*) FROM funcionarios WHERE ativo = 0) AS inativos, (SELECT COUNT(DISTINCT a.employee_id) FROM portal_pa_assignments a JOIN funcionarios f ON f.id = a.employee_id AND f.ativo = 0 WHERE a.active = 1 AND (a.valid_until IS NULL OR a.valid_until >= CURDATE())) AS inativos_com_pa, (SELECT COUNT(DISTINCT r.employee_id) FROM portal_schedule_rules r JOIN funcionarios f ON f.id = r.employee_id AND f.ativo = 0 WHERE (r.effective_until IS NULL OR r.effective_until >= CURDATE()) AND (r.effective_until IS NULL OR r.effective_until >= r.effective_from)) AS inativos_com_regra, (SELECT COUNT(*) FROM portal_schedule_exceptions e JOIN funcionarios f ON f.id = e.employee_id AND f.ativo = 0 WHERE e.exception_date >= CURDATE()) AS excecoes_de_inativos;"; unset P
```

- [ ] **52.** (4g, ensaio da 014) Esperado: como no roteiro do F4, passo 3 (variante sem dados). **PARE se:** `FALHOU` ou saida diferente do esperado.

```bash
sudo env VERIFICA="$E/migrations/verificacao/20261013_014_employee_archive_check.sql" bash "$E/scripts/ensaio-migration.sh" d1_014 "$E/migrations/20261013_014_employee_archive.sql" "$E/migrations/rollback/20261013_014_employee_archive_down.sql"
```

- [ ] **53.** (4g, ensaio da 014) Esperado: como no roteiro do F4, passo 3 (variante com dados). **PARE se:** `FALHOU` ou saida diferente do esperado.

```bash
sudo env DADOS=portal_pa_assignments,portal_schedule_rules,portal_schedule_exceptions,portal_absences VERIFICA="$E/migrations/verificacao/20261013_014_employee_archive_check.sql" bash "$E/scripts/ensaio-migration.sh" d1_014_dados "$E/migrations/20261013_014_employee_archive.sql" "$E/migrations/rollback/20261013_014_employee_archive_down.sql"
```

- [ ] **54.** (4g, ensaio da 014) Esperado: como no roteiro do F4, passo 3 (variante do rollback; o 2o rollback para). **PARE se:** `FALHOU` ou saida diferente do esperado.

```bash
sudo env PREPARO="$E/migrations/20261013_014_employee_archive.sql" VERIFICA="$E/migrations/verificacao/20261013_014_employee_archive_check.sql" bash "$E/scripts/ensaio-migration.sh" d1_014_rollback "$E/migrations/rollback/20261013_014_ensaio_linhas_f4.sql" "$E/migrations/rollback/20261013_014_employee_archive_down.sql"
```

- [ ] **55.** (4g) Esperado: `014 aplicada`. **PARE se:** `PARE: a 014 nao foi aplicada`.

```bash
cd /var/www/chronodesk; export D1=$(sudo cat /var/backups/chronodesk/deploy1-commit); read -rsp 'Senha root do MySQL: ' P; echo; export P; sudo git cat-file -e "$D1:migrations/20261013_014_employee_archive.sql" && bash -o pipefail -c 'sudo git show "$D1:migrations/20261013_014_employee_archive.sql" | docker exec -i -e MYSQL_PWD="$P" Chrono_Desk_DB mysql -uroot sistema_pausas' && echo "014 aplicada" || echo "PARE: a 014 nao foi aplicada"; unset P
```

- [ ] **56.** (4g) Esperado: `colunas_014 = 2`, `check_014 = 1`, `copia_rollback = 0`, `arquivados_f4 = 0`, `inativos_sem_data` = `inativos` do comando 51, `ativos_com_data = 0`, `arquivados_sem_autor = 0`, efeitos pendentes iguais aos do comando 51. **PARE se:** saida diferente do esperado.

```bash
read -rsp 'Senha root do MySQL: ' P; echo; docker exec -i -e MYSQL_PWD="$P" Chrono_Desk_DB mysql -uroot --table sistema_pausas < <(sudo cat "$E/migrations/verificacao/20261013_014_employee_archive_check.sql"); unset P
```

- [ ] **57.** (4h, antes da 019) Esperado: como no comando 12 (`inconsistente = 0`). **PARE se:** `inconsistente = 1`: nao registre o corte, nao altere linha, avise o responsavel.

```bash
read -rsp 'Senha root do MySQL: ' P; echo; docker exec -i -e MYSQL_PWD="$P" Chrono_Desk_DB mysql -uroot --table sistema_pausas < <(sudo cat "$E/migrations/verificacao/20261013_019_presence_cutoff_check.sql"); unset P
```

- [ ] **58.** (4h, ensaio da 019) Esperado: como no roteiro do F5, passo 4. **PARE se:** `FALHOU` ou saida diferente do esperado.

```bash
sudo env VERIFICA="$E/migrations/verificacao/20261013_019_presence_cutoff_check.sql" bash "$E/scripts/ensaio-migration.sh" d1_019 "$E/migrations/20261013_019_presence_cutoff.sql" "$E/migrations/rollback/20261013_019_presence_cutoff_down.sql" audit_log
```

- [ ] **59.** (4h, ensaio da 019) Esperado: o registro de 2099 grava e o 2o para. **PARE se:** `FALHOU` ou saida diferente do esperado.

```bash
{ printf "SET @corte := '2099-12-16'; SET @autor := 'qa.ensaio';\n"; sudo cat "$E/migrations/20261013_019_registrar_corte.sql"; } | sudo tee "$E/registro_d1.sql" > /dev/null; sudo env PREPARO="$E/migrations/20261013_019_presence_cutoff.sql" VERIFICA="$E/migrations/verificacao/20261013_019_presence_cutoff_check.sql" bash "$E/scripts/ensaio-migration.sh" d1_019_registro "$E/registro_d1.sql" "$E/migrations/rollback/20261013_019_presence_cutoff_down.sql" audit_log
```

- [ ] **60.** (4h, ensaio da 019) Esperado: a data passada para nas duas execucoes. **PARE se:** `FALHOU` ou saida diferente do esperado.

```bash
{ printf "SET @corte := '2020-01-16'; SET @autor := 'qa.ensaio';\n"; sudo cat "$E/migrations/20261013_019_registrar_corte.sql"; } | sudo tee "$E/registro_d1_passado.sql" > /dev/null; sudo env PREPARO="$E/migrations/20261013_019_presence_cutoff.sql" VERIFICA="$E/migrations/verificacao/20261013_019_presence_cutoff_check.sql" bash "$E/scripts/ensaio-migration.sh" d1_019_passado "$E/registro_d1_passado.sql" "$E/migrations/rollback/20261013_019_presence_cutoff_down.sql" audit_log
```

- [ ] **61.** (4h) Esperado: `019 aplicada`. **PARE se:** `PARE: a 019 nao foi aplicada`.

```bash
cd /var/www/chronodesk; export D1=$(sudo cat /var/backups/chronodesk/deploy1-commit); read -rsp 'Senha root do MySQL: ' P; echo; export P; sudo git cat-file -e "$D1:migrations/20261013_019_presence_cutoff.sql" && bash -o pipefail -c 'sudo git show "$D1:migrations/20261013_019_presence_cutoff.sql" | docker exec -i -e MYSQL_PWD="$P" Chrono_Desk_DB mysql -uroot sistema_pausas' && echo "019 aplicada" || echo "PARE: a 019 nao foi aplicada"; unset P
```

- [ ] **62.** (4h) Esperado: `tabela_019 = 1`, `checks_019 = 2`, `gatilhos_019 = 2`, `copia_rollback = 0`, `procedimentos_soltos = 0`, `cortes = 0`, `sem_autor = 0`, `eventos_registro = 0`, `eventos_remocao = 0`, `inconsistente = 0`. **PARE se:** saida diferente do esperado.

```bash
read -rsp 'Senha root do MySQL: ' P; echo; docker exec -i -e MYSQL_PWD="$P" Chrono_Desk_DB mysql -uroot --table sistema_pausas < <(sudo cat "$E/migrations/verificacao/20261013_019_presence_cutoff_check.sql"); unset P
```

- [ ] **63.** (4i, ensaio da 012) Esperado: a tabela vazia migra (roteiro do F3, passo 3). **PARE se:** `FALHOU` ou saida diferente do esperado.

```bash
sudo env DADOS=portal_absences VERIFICA="$E/migrations/verificacao/20261007_012_absences_check.sql" bash "$E/scripts/ensaio-migration.sh" d1_012 "$E/migrations/20261007_012_absences.sql" "$E/migrations/rollback/20261007_012_absences_down.sql"
```

- [ ] **64.** (4i, ensaio da 012) Esperado: a linha antiga faz a 012 parar sem alterar nada. **PARE se:** `FALHOU` ou saida diferente do esperado.

```bash
sudo env DADOS=portal_absences PREPARO="$E/migrations/rollback/20261007_012_ensaio_linha_antiga.sql" VERIFICA="$E/migrations/verificacao/20261007_012_absences_check.sql" bash "$E/scripts/ensaio-migration.sh" d1_012_parada "$E/migrations/20261007_012_absences.sql" "$E/migrations/rollback/20261007_012_absences_down.sql"
```

- [ ] **65.** (4i, ensaio da 012) Esperado: o rollback com linhas do F3 volta e o 2o para. **PARE se:** `FALHOU` ou saida diferente do esperado.

```bash
sudo env DADOS=portal_absences PREPARO="$E/migrations/20261007_012_absences.sql" VERIFICA="$E/migrations/verificacao/20261007_012_absences_check.sql" bash "$E/scripts/ensaio-migration.sh" d1_012_rollback "$E/migrations/rollback/20261007_012_ensaio_linhas_f3.sql" "$E/migrations/rollback/20261007_012_absences_down.sql"
```

- [ ] **66.** (4i, Q4) Esperado: contagens por tipo (anote; o comando 95 compara) e `ausencias = 0`. **PARE se:** `ausencias` maior que `0` (a 012 pararia): envie a contagem.

```bash
read -rsp 'Senha root do MySQL: ' P; echo; docker exec -i -e MYSQL_PWD="$P" Chrono_Desk_DB mysql -uroot --table sistema_pausas -e "SET time_zone = 'America/Sao_Paulo'; SELECT exception_type, COUNT(*) AS n, SUM(exception_date >= CURDATE()) AS futuras FROM portal_schedule_exceptions GROUP BY exception_type; SELECT COUNT(*) AS ausencias FROM portal_absences;"; unset P
```

## 5. Ensaio do codigo do commit gravado

- [ ] **67.** (5) Esperado: `/tmp/.env ausente: OK`. **PARE se:** `PARE: existe /tmp/.env`.

```bash
[ ! -e /tmp/.env ] && echo "/tmp/.env ausente: OK" || echo "PARE: existe /tmp/.env; o ensaio leria esse arquivo"
```

- [ ] **68.** (5) Esperado: `extraido em /tmp/ensaio-deploy1-qa.<sufixo>`. **PARE se:** `PARE: falha ao extrair o deploy1-commit`.

```bash
cd /var/www/chronodesk; export D1=$(sudo cat /var/backups/chronodesk/deploy1-commit); Q=$(sudo mktemp -d /tmp/ensaio-deploy1-qa.XXXXXX); export Q; bash -o pipefail -c 'sudo git archive "$D1" | sudo tar -x -C "$Q"' && sudo chown -R www-data: "$Q" && echo "extraido em $Q" || echo "PARE: falha ao extrair o deploy1-commit"
```

- [ ] **69.** (5) Esperado: nenhuma linha antes de `fim do php -l`. **PARE se:** qualquer linha antes de `fim do php -l`.

```bash
sudo find "$Q" -name '*.php' -not -path '*/node_modules/*' -exec sudo -u www-data php -l {} \; | grep -v '^No syntax errors' ; echo "fim do php -l"
```

- [ ] **70.** (5) Esperado: os 16 QAs com `OK`. **PARE se:** algum `FALHOU`: antes do comando 71, envie so o nome do QA e a ultima linha dele (comando no passo 5 do roteiro).

```bash
[ ! -e /tmp/.env ] && for q in qa-smoke qa-auth qa-timezone qa-overtime qa-schedule-history qa-schedule-legacy-output qa-presence-engine qa-absences qa-calendar-text qa-archive qa-presence-cutoff qa-pa-conflicts qa-operational-view qa-employee-lunch qa-security qa-deploy-runbook; do printf '%s: ' "$q"; sudo -u www-data php "$Q/scripts/$q.php" >/dev/null 2>&1 && echo OK || echo FALHOU; done
```

- [ ] **71.** (5) Esperado: `ensaio removido`. **PARE se:** `PARE: ... fora do padrao, nada apagado`.

```bash
case "$Q" in /tmp/ensaio-deploy1-qa.*) sudo rm -r "$Q" && echo "ensaio removido";; *) echo "PARE: $Q fora do padrao, nada apagado";; esac; unset Q
```

## 6. Q-F6, Q-F7, base, manutencao, 012 com o `merge`, corte do BIZ-02, 016b, build, recarga, validacao e reabertura

- [ ] **72.** (6a, Q-F6) Esperado: `vinculos_de_inativos = 1` e `vinculos_fora_do_mapa = 0`; os demais como no comando 8. `pas_compartilhados_hoje` maior que o do aviso: avise a gestao (nao bloqueia). **PARE se:** saida diferente do esperado (nada do codigo mudou).

```bash
read -rsp 'Senha root do MySQL: ' P; echo; docker exec -i -e MYSQL_PWD="$P" Chrono_Desk_DB mysql -uroot --table sistema_pausas -e "SET time_zone = 'America/Sao_Paulo'; SELECT (SELECT COUNT(*) FROM portal_pa_inventory WHERE active = 1) AS pas_inventario, (SELECT COUNT(*) FROM portal_pa_assignments WHERE active = 1) AS vinculos_ativos, (SELECT COUNT(*) FROM portal_pa_assignments WHERE active = 1 AND (valid_until IS NULL OR valid_until >= CURDATE())) AS vinculos_vigentes_ou_futuros, (SELECT COUNT(*) FROM (SELECT pa_number FROM portal_pa_assignments WHERE active = 1 AND valid_from <= CURDATE() AND (valid_until IS NULL OR valid_until >= CURDATE()) GROUP BY pa_number HAVING COUNT(*) > 1) t) AS pas_compartilhados_hoje, (SELECT COUNT(*) FROM portal_pa_assignments a JOIN funcionarios f ON f.id = a.employee_id WHERE a.active = 1 AND (a.valid_until IS NULL OR a.valid_until >= CURDATE()) AND f.ativo = 0) AS vinculos_de_inativos, (SELECT COUNT(*) FROM portal_pa_assignments a JOIN funcionarios f ON f.id = a.employee_id WHERE a.active = 1 AND (a.valid_until IS NULL OR a.valid_until >= CURDATE()) AND f.fora_mapa_escala = 1) AS vinculos_fora_do_mapa, (SELECT COUNT(*) FROM portal_pa_assignments WHERE active = 1 AND team = 'lideranca' AND (valid_until IS NULL OR valid_until >= CURDATE())) AS vinculos_lideranca;"; unset P
```

- [ ] **73.** (6a, Q-F6) Esperado: como no comando 9. **PARE se:** saida diferente do esperado.

```bash
read -rsp 'Senha root do MySQL: ' P; echo; docker exec -i -e MYSQL_PWD="$P" Chrono_Desk_DB mysql -uroot --table sistema_pausas -e "SET time_zone = 'America/Sao_Paulo'; SELECT (SELECT COUNT(*) FROM portal_pa_assignments a JOIN portal_pa_assignments b ON b.employee_id = a.employee_id AND b.id > a.id AND b.active = 1 AND b.valid_from <= COALESCE(a.valid_until, '9999-12-31') AND COALESCE(b.valid_until, '9999-12-31') >= a.valid_from WHERE a.active = 1) AS sobreposicoes_mesmo_colaborador, (SELECT COUNT(*) FROM portal_pa_assignments WHERE active = 1 AND valid_until < valid_from) AS periodo_invertido, (SELECT COUNT(*) FROM portal_pa_assignments a WHERE a.active = 1 AND NOT EXISTS (SELECT 1 FROM portal_pa_inventory i WHERE i.active = 1 AND i.pa_number = a.pa_number)) AS vinculos_fora_do_inventario, (SELECT COUNT(*) FROM portal_pa_assignments a LEFT JOIN portal_schedule_rules r ON r.employee_id = a.employee_id AND r.effective_from <= CURDATE() AND (r.effective_until IS NULL OR r.effective_until >= CURDATE()) WHERE a.active = 1 AND a.valid_from <= CURDATE() AND (a.valid_until IS NULL OR a.valid_until >= CURDATE()) AND a.team <> 'lideranca' AND a.schedule_rule_type <> COALESCE(IF(r.rule_type = 'cycle', 'undefined', r.rule_type), 'undefined')) AS copia_tipo_divergente_hoje, (SELECT COUNT(*) FROM portal_pa_assignments WHERE active = 1 AND notes IS NOT NULL AND notes <> '') AS vinculos_com_observacao, (SELECT COUNT(*) FROM portal_pa_map WHERE record_status = 'active') AS legado_total, (SELECT COUNT(*) FROM portal_pa_map WHERE record_status = 'active' AND work_date >= CURDATE()) AS legado_futuro;"; unset P
```

- [ ] **74.** (6a, Q-F7) Esperado: `ativos_outras_equipes`, `jornada_invertida` e `almoco_fora_da_jornada` em `0`. `n1_n2_sem_regra_hoje` maior que o do aviso: avise a gestao (nao bloqueia). **PARE se:** um dos tres maior que `0`.

```bash
read -rsp 'Senha root do MySQL: ' P; echo; docker exec -i -e MYSQL_PWD="$P" Chrono_Desk_DB mysql -uroot --table sistema_pausas -e "SET time_zone = 'America/Sao_Paulo'; SELECT (SELECT COUNT(*) FROM funcionarios WHERE ativo = 1 AND LOWER(TRIM(equipe)) = 'n1') AS ativos_n1, (SELECT COUNT(*) FROM funcionarios WHERE ativo = 1 AND LOWER(TRIM(equipe)) = 'n2') AS ativos_n2, (SELECT COUNT(*) FROM funcionarios WHERE ativo = 1 AND LOWER(TRIM(equipe)) IN ('lideranca', 'liderança', 'na', 'não se aplica', 'nao se aplica', 'nao_se_aplica', 'sem_equipe')) AS ativos_lideranca, (SELECT COUNT(*) FROM funcionarios WHERE ativo = 1 AND LOWER(TRIM(equipe)) NOT IN ('n1', 'n2', 'lideranca', 'liderança', 'na', 'não se aplica', 'nao se aplica', 'nao_se_aplica', 'sem_equipe')) AS ativos_outras_equipes, (SELECT COUNT(*) FROM funcionarios WHERE ativo = 1 AND fora_mapa_escala = 1) AS fora_do_mapa, (SELECT COUNT(*) FROM funcionarios f WHERE f.ativo = 1 AND f.fora_mapa_escala = 0 AND LOWER(TRIM(f.equipe)) IN ('n1', 'n2') AND NOT EXISTS (SELECT 1 FROM portal_schedule_rules r WHERE r.employee_id = f.id AND r.rule_type <> 'undefined' AND r.effective_from <= CURDATE() AND (r.effective_until IS NULL OR r.effective_until >= CURDATE()))) AS n1_n2_sem_regra_hoje, (SELECT COUNT(*) FROM funcionarios WHERE ativo = 1 AND jornada_entrada >= jornada_saida) AS jornada_invertida, (SELECT COUNT(*) FROM funcionarios WHERE ativo = 1 AND NOT (jornada_entrada <= almoco_inicio AND almoco_inicio < almoco_fim AND almoco_fim <= jornada_saida)) AS almoco_fora_da_jornada, (SELECT COUNT(*) FROM portal_absences WHERE status = 'approved' AND starts_on <= CURDATE() AND ends_on >= CURDATE()) AS ausencias_aprovadas_hoje, (SELECT COUNT(*) FROM portal_schedule_exceptions WHERE exception_date = CURDATE() AND exception_type IN ('day_off', 'absence', 'vacation', 'leave')) AS excecoes_de_ausencia_hoje;"; unset P
```

- [ ] **75.** (6a, rotulo) Esperado: `rotulo_que_o_php_nao_reconhece = 0`. **PARE se:** maior que `0`.

```bash
read -rsp 'Senha root do MySQL: ' P; echo; docker exec -i -e MYSQL_PWD="$P" Chrono_Desk_DB mysql -uroot --table sistema_pausas -e "SELECT COUNT(*) AS rotulo_que_o_php_nao_reconhece FROM funcionarios WHERE ativo = 1 AND (CAST(LOWER(TRIM(equipe)) AS BINARY) NOT IN ('n1', 'n2', 'lideranca', 'liderança', 'na', 'não se aplica', 'nao se aplica', 'nao_se_aplica', 'sem_equipe') OR LOCATE(CAST('Ç' AS BINARY), CAST(TRIM(equipe) AS BINARY)) > 0 OR LOCATE(CAST('Ã' AS BINARY), CAST(TRIM(equipe) AS BINARY)) > 0);"; unset P
```

- [ ] **76.** (6a, base) Esperado: p50 e p95 do `status.php` (anote: base do comando 99). **PARE se:** nada (so anotar).

```bash
cd /var/www/chronodesk; D1=$(sudo cat /var/backups/chronodesk/deploy1-commit); sudo git cat-file -e "$D1:scripts/medir-polling.sh" && bash <(sudo git show "$D1:scripts/medir-polling.sh")
```

- [ ] **77.** (6a, base) Esperado: p50 e p95 do `dashboard.php` (anote: base do comando 100). **PARE se:** nada (so anotar).

```bash
sudo git cat-file -e "$D1:scripts/medir-polling.sh" && URL=http://127.0.0.1/api/portal/dashboard.php bash <(sudo git show "$D1:scripts/medir-polling.sh"); unset D1
```

- [ ] **78.** (6b, manutencao) Esperado: `arquivo de manutencao: presente` (so passa a valer no `merge`). **PARE se:** `AUSENTE`.

```bash
sudo touch /var/www/chronodesk.maintenance; echo "arquivo de manutencao: $(test -e /var/www/chronodesk.maintenance && echo presente || echo AUSENTE)"
```

- [ ] **79.** (6b) Esperado: a 012 aplicada, o `.htaccess` local descartado e o `merge` em fast-forward, sem erro. **PARE se:** `migration_012 abortada` ou erro do `cat-file`/`git show` (nada mais rodou: tire o arquivo de manutencao e envie a contagem de `portal_absences`); a 012 terminou e o `merge` falhou: copia do `.htaccess` de volta e "Se a verificacao falhar" do roteiro (sem `fetch`, sem `pull`).

```bash
cd /var/www/chronodesk; export D1=$(sudo cat /var/backups/chronodesk/deploy1-commit); read -rsp 'Senha root do MySQL: ' P; echo; export P; sudo git cat-file -e "$D1:migrations/20261007_012_absences.sql" && bash -o pipefail -c 'sudo git show "$D1:migrations/20261007_012_absences.sql" | docker exec -i -e MYSQL_PWD="$P" Chrono_Desk_DB mysql -uroot sistema_pausas' && sudo git checkout -- .htaccess && sudo git merge --ff-only "$D1"; unset P
```

- [ ] **80.** (6b) Esperado: `HEAD = deploy1-commit` e `alteracoes locais: 0`. **PARE se:** `PARE: HEAD diferente do deploy1-commit`.

```bash
[ "$(sudo git rev-parse HEAD)" = "$(sudo cat /var/backups/chronodesk/deploy1-commit)" ] && echo "HEAD = deploy1-commit" || echo "PARE: HEAD diferente do deploy1-commit"; echo "alteracoes locais: $(sudo git status --porcelain | wc -l)"
```

- [ ] **81.** (6b) Esperado: `portal visto de fora (manutencao): 503` (sem `503`: confira de uma estacao que aparece "ChronoDesk temporariamente indisponivel."). **PARE se:** a estacao ve a tela do portal (a manutencao nao vale).

```bash
curl -s -o /dev/null -w 'portal visto de fora (manutencao): %{http_code}\n' "<URL>/"
```

- [ ] **82.** (6c, corte do BIZ-02) Esperado: `CORTE REGISTRADO`, `g1_linhas_formato_novo = 0`, `g2_saltos_auditoria = 0`, `g3_minutos_fora_de_utc = 0`, contagem por coluna proxima da do comando 24. **PARE se:** `ABORTADO PELAS GUARDAS`: fique em manutencao, sem 016b nem recarga, e avise o responsavel.

```bash
cd /var/www/chronodesk; read -rsp 'Senha root do MySQL: ' P; echo; docker exec -i -e MYSQL_PWD="$P" Chrono_Desk_DB mysql -uroot --table sistema_pausas < migrations/correcao_dados/biz02_01_registrar_corte.sql; unset P
```

- [ ] **83.** (6c) Esperado: o `.htaccess`; em `migrations/`, os arquivos da 011, 012, 014, 015, 016, 016b, 017 e 019, do ciclo do F2B e de `correcao_dados/`; o `scripts/backup-chronodesk.sh` novo. **PARE se:** saida diferente do esperado.

```bash
sudo git diff --stat "$(sudo cat /var/backups/chronodesk/pre-deploy-commit)" HEAD -- migrations/ scripts/backup-chronodesk.sh .htaccess
```

- [ ] **84.** (6c, verificacao da 012) Esperado: `colunas_012 = 10`, `absence_type = 0`, `status_tipo = enum('approved','cancelled')`, `fk = 1`, `indice = 1`, `checks = 2`, `copia_rollback = 0`, `linhas = 0`; na 2a consulta, tudo `0`. **PARE se:** `orfas`, `periodo_invalido` ou `sobreposicoes_aprovadas` maior que `0` (nao apague nem altere linha; incidente) ou saida diferente do esperado.

```bash
read -rsp 'Senha root do MySQL: ' P; echo; docker exec -i -e MYSQL_PWD="$P" Chrono_Desk_DB mysql -uroot --table sistema_pausas < migrations/verificacao/20261007_012_absences_check.sql; unset P
```

- [ ] **85.** (6d) Esperado: `016b aplicada`. **PARE se:** `PARE: a 016b nao foi aplicada`.

```bash
cd /var/www/chronodesk; export D1=$(sudo cat /var/backups/chronodesk/deploy1-commit); read -rsp 'Senha root do MySQL: ' P; echo; export P; sudo git cat-file -e "$D1:migrations/20261002_016b_lideranca_profile_data.sql" && bash -o pipefail -c 'sudo git show "$D1:migrations/20261002_016b_lideranca_profile_data.sql" | docker exec -i -e MYSQL_PWD="$P" Chrono_Desk_DB mysql -uroot sistema_pausas' && echo "016b aplicada" || echo "PARE: a 016b nao foi aplicada"; unset P
```

- [ ] **86.** (6d) Esperado: contagens por equipe, perfil e situacao iguais as do comando 42; um evento de cada tipo; `funcionarios_perfil_016b` com as linhas de cada passo. **PARE se:** saida diferente do esperado (reversao: degrau 2, comando 114).

```bash
read -rsp 'Senha root do MySQL: ' P; echo; docker exec -i -e MYSQL_PWD="$P" Chrono_Desk_DB mysql -uroot --table sistema_pausas -e "SELECT equipe, access_role, ativo, COUNT(*) AS cadastros FROM funcionarios GROUP BY equipe, access_role, ativo ORDER BY equipe, access_role, ativo; SELECT action, COUNT(*) AS eventos FROM audit_log WHERE action IN ('LIDERANCA_PERFIL_MIGRADO', 'ADMIN_INATIVO_REBAIXADO') GROUP BY action; SELECT passo, COUNT(*) AS registrados FROM funcionarios_perfil_016b GROUP BY passo;"; unset P
```

- [ ] **87.** (6e) Esperado: build sem erro. **PARE se:** falha no build: degrau 2 (comandos 112 a 118), com aprovacao.

```bash
cd /var/www/chronodesk/frontend && sudo npm ci && sudo npm run build
```

- [ ] **88.** (6e) Esperado: nenhuma saida. **PARE se:** erro na recarga.

```bash
sudo systemctl reload "php$(php -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;')-fpm"
```

- [ ] **89.** (6f, em manutencao) Esperado: `health: 200`. **PARE se:** diferente de `200`: degrau 2, com aprovacao.

```bash
curl -s -o /dev/null -w 'health: %{http_code}\n' -H 'Host: <ServerName do vhost>' http://127.0.0.1/api/health.php
```

- [ ] **90.** (6f) Esperado: `sessao MySQL em America/Sao_Paulo` e `QA timezone (banco real) OK`. **PARE se:** falha: sem permissao de `SET timestamp`, use a secao 8.3 do BIZ-02 (root); fuso errado: degrau 2, com aprovacao.

```bash
cd /var/www/chronodesk; sudo -u www-data php scripts/qa-timezone.php --db
```

- [ ] **91.** (6g, reabertura) Esperado: so com os comandos 89 e 90 como esperado. `manutencao encerrada` e `portal visto de fora: 302`. Numa tela, uma data de atualizacao recente bate com o relogio. **PARE se:** `PARE: arquivo de manutencao ainda presente`.

```bash
[ -e /var/www/chronodesk.maintenance ] && sudo rm -- /var/www/chronodesk.maintenance; [ -e /var/www/chronodesk.maintenance ] && echo "PARE: arquivo de manutencao ainda presente" || echo "manutencao encerrada"; curl -s -o /dev/null -w 'portal visto de fora: %{http_code}\n' "<URL>/"
```

## 7. Verificacao pos-deploy

- [ ] **92.** (7a) Esperado: `200`. **PARE se:** diferente de `200`: degrau 2, com aprovacao.

```bash
curl -s -o /dev/null -w '%{http_code}\n' -H 'Host: <ServerName do vhost>' http://127.0.0.1/api/health.php
```

- [ ] **93.** (7a) Esperado: as quatro verificacoes como nos comandos 50, 84, 56 e 62 (019 com `inconsistente = 0`). **PARE se:** `inconsistente = 1` (regra 16a) ou saida diferente do esperado.

```bash
cd /var/www/chronodesk; for v in 20261005_011_schedule_history_check 20261007_012_absences_check 20261013_014_employee_archive_check 20261013_019_presence_cutoff_check; do echo "== $v"; read -rsp 'Senha root do MySQL: ' P; echo; docker exec -i -e MYSQL_PWD="$P" Chrono_Desk_DB mysql -uroot --table sistema_pausas < "migrations/verificacao/$v.sql"; unset P; done
```

- [ ] **94.** (7a) Esperado: `migration_015 = 1`, `migration_016 = 1`, `migration_017 = 1`, `registrados_016b` como no comando 86, `corte_biz02 = 1`. **PARE se:** saida diferente do esperado.

```bash
read -rsp 'Senha root do MySQL: ' P; echo; docker exec -i -e MYSQL_PWD="$P" Chrono_Desk_DB mysql -uroot --table sistema_pausas -e "SELECT (SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'login_user_throttle') AS migration_015, (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'funcionarios' AND INDEX_NAME = 'uq_funcionarios_ad_login') AS migration_016, (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'portal_shift_attachments' AND COLUMN_NAME = 'team') AS migration_017, (SELECT COUNT(*) FROM funcionarios_perfil_016b) AS registrados_016b, (SELECT COUNT(*) FROM manutencao_marcadores WHERE chave = 'biz02_corte') AS corte_biz02, (SELECT COUNT(*) FROM login_user_throttle) AS logins_com_falha;"; unset P
```

- [ ] **95.** (7a, Q4 e eventos) Esperado: as mesmas contagens do comando 66; desde o corte, so os eventos das acoes de hoje (os da 016b e os dos logins de teste). **PARE se:** `LOGIN_THROTTLE_UNAVAILABLE` (todo login daria 503) ou saida diferente do esperado.

```bash
read -rsp 'Senha root do MySQL: ' P; echo; docker exec -i -e MYSQL_PWD="$P" Chrono_Desk_DB mysql -uroot --table sistema_pausas -e "SET time_zone = 'America/Sao_Paulo'; SELECT exception_type, COUNT(*) AS n, SUM(exception_date >= CURDATE()) AS futuras FROM portal_schedule_exceptions GROUP BY exception_type; SELECT action, COUNT(*) AS n FROM audit_log WHERE id > (SELECT COALESCE(MAX(linha_id), 0) FROM manutencao_biz02_datetime WHERE tabela = 'audit_log') GROUP BY action ORDER BY action;"; unset P
```

- [ ] **7b.** Checagem HTTP do `scripts/test-linux.sh` (agora na arvore), sem `MAINTENANCE_TEST`.

- [ ] **96.** (7c) Esperado: `GET 302` e `POST 410` em todas as paginas, menos `/logout.php` (`GET 410` e `POST 410`); `403` nos dois JS. **PARE se:** `GET 200` numa pagina legada (`AllowOverride`; o rollback e o `.htaccess` anterior).

```bash
for p in / /index.php /login.php /admin_login.php /admin_logout.php /admin.php /metricas.php /logout.php; do echo "$p GET $(curl -k -s -o /dev/null -w '%{http_code}' "<URL>$p") POST $(curl -k -s -o /dev/null -w '%{http_code}' -X POST "<URL>$p")"; done; for j in admin.js admin_users.js; do echo "$j $(curl -k -s -o /dev/null -w '%{http_code}' "<URL>/static/js/$j")"; done
```

- [ ] **7d.** Repita os comandos 72 e 73: os mesmos numeros (diferencas batem com os `PA_MAP_*` do comando 95).
- [ ] **7e.** Repita os comandos 74 e 75: os mesmos numeros.

- [ ] **97.** (7f, D5) Esperado: so com os numeros do comando 51 iguais a referencia. So o ID interno; depois, como admin, Funcionarios > "Arquivados" > "Arquivar (regularizar)" na linha com esse ID: "Cadastro inativo regularizado e arquivado. 1 vinculo de PA encerrado." (ou "removido"). **PARE se:** numeros do comando 51 diferentes da referencia: avise antes de arquivar.

```bash
read -rsp 'Senha root do MySQL: ' P; echo; docker exec -i -e MYSQL_PWD="$P" Chrono_Desk_DB mysql -uroot --table sistema_pausas -e "SET time_zone = 'America/Sao_Paulo'; SELECT DISTINCT a.employee_id AS id_cadastro FROM portal_pa_assignments a JOIN funcionarios f ON f.id = a.employee_id AND f.ativo = 0 WHERE a.active = 1 AND (a.valid_until IS NULL OR a.valid_until >= CURDATE());"; unset P
```

- [ ] **98.** (7f) Esperado: `inativos_com_pa = 0`, `arquivados_f4 = 1`, `inativos_sem_data = 4`, `ativos_com_data = 0`, `arquivados_sem_autor = 0`. **PARE se:** `ativos_com_data` ou `arquivados_sem_autor` maior que `0` (incidente) ou saida diferente do esperado.

```bash
read -rsp 'Senha root do MySQL: ' P; echo; docker exec -i -e MYSQL_PWD="$P" Chrono_Desk_DB mysql -uroot --table sistema_pausas < /var/www/chronodesk/migrations/verificacao/20261013_014_employee_archive_check.sql; unset P
```

- [ ] **7g.** Repita o comando 72: `vinculos_de_inativos = 0` e `vinculos_fora_do_mapa = 0`. **PARE se:** diferente.
- [ ] **7h.** O responsavel marca pela tela os cadastros fora do Mapa (so admin) e repete o comando 74 (`fora_do_mapa` cresce na mesma conta).
- [ ] **7i.** Navegador, por perfil (login de cada perfil e revalidacao com usuario de teste; admin, com funcionario sem login AD; Lideranca, com anexo de escala para N1; gestor, com horas extras e CSV; tecnico; 1280 px e 390 px): lista do roteiro, 7i. Depois, o comando 95 de novo (`ADMIN_ROLE_SEM_ALLOWLIST`, `SESSION_REVOKED`). Anote so numeros.

- [ ] **99.** (7j) Esperado: p50 e p95 do `status.php` (anote na 9.9). **PARE se:** p95 mais de 50 ms **e** mais de 20% acima do comando 76: degrau 1 (comandos 106 a 109) e medir de novo.

```bash
cd /var/www/chronodesk; bash scripts/medir-polling.sh
```

- [ ] **100.** (7j) Esperado: p50 e p95 do `dashboard.php` (anote na 9.9). **PARE se:** p95 mais de 50 ms **e** mais de 20% acima do comando 77: degrau 1 e medir de novo.

```bash
cd /var/www/chronodesk; URL=http://127.0.0.1/api/portal/dashboard.php bash scripts/medir-polling.sh
```

- [ ] **101.** (7k) Esperado: `corte planejado` igual ao do comando 14 (diferente: o deploy passou do dia 16; a data nova vale, avise a gestao). **PARE se:** `PARE: data do corte invalida`.

```bash
read -rsp 'Senha root do MySQL: ' P; echo; C=$(docker exec -i -e MYSQL_PWD="$P" Chrono_Desk_DB mysql -uroot -N sistema_pausas -e "SET time_zone = 'America/Sao_Paulo'; SELECT IF(DAYOFMONTH(CURDATE()) >= 16, DATE_FORMAT(CURDATE() + INTERVAL 1 MONTH, '%Y-%m-16'), DATE_FORMAT(CURDATE(), '%Y-%m-16'));"); unset P; [[ "$C" =~ ^[0-9]{4}-[0-9]{2}-16$ ]] && echo "corte planejado: $C" || echo "PARE: data do corte invalida ($C)"
```

- [ ] **102.** (7k) Esperado: `sem_regra_no_corte = 0` e `sem_regra_na_competencia = 0`. **PARE se:** qualquer um maior que `0`: o comando 103 nao roda (o deploy fica no ar sem corte).

```bash
read -rsp 'Senha root do MySQL: ' P; echo; docker exec -i -e MYSQL_PWD="$P" Chrono_Desk_DB mysql -uroot --table sistema_pausas -e "SET @corte := '$C'; WITH RECURSIVE dias (d) AS (SELECT CAST(@corte AS DATE) UNION ALL SELECT d + INTERVAL 1 DAY FROM dias WHERE d < @corte + INTERVAL 1 MONTH - INTERVAL 1 DAY) SELECT COUNT(*) AS ativos_n1_n2, COALESCE(SUM(NOT EXISTS (SELECT 1 FROM portal_schedule_rules r WHERE r.employee_id = f.id AND r.rule_type <> 'undefined' AND r.effective_from <= @corte AND (r.effective_until IS NULL OR r.effective_until >= @corte))), 0) AS sem_regra_no_corte, COALESCE(SUM(EXISTS (SELECT 1 FROM dias WHERE NOT EXISTS (SELECT 1 FROM portal_schedule_rules r WHERE r.employee_id = f.id AND r.rule_type <> 'undefined' AND r.effective_from <= dias.d AND (r.effective_until IS NULL OR r.effective_until >= dias.d)))), 0) AS sem_regra_na_competencia FROM funcionarios f WHERE f.ativo = 1 AND f.fora_mapa_escala = 0 AND LOWER(f.equipe) IN ('n1', 'n2');"; unset P
```

- [ ] **103.** (7l) Esperado: so com o comando 102 em `0`, `inconsistente = 0` e o `C` do comando 101 na mesma sessao; antes, troque `<login do operador>`. `corte a registrar: <C>` e nenhuma outra mensagem. **PARE se:** `registro_019 abortado: ...` (nada gravado): confira o `C`, `DROP PROCEDURE IF EXISTS registrar_corte_019` e envie a mensagem.

```bash
cd /var/www/chronodesk; echo "corte a registrar: $C"; read -rsp 'Senha root do MySQL: ' P; echo; { printf "SET @corte := '%s'; SET @autor := '%s';\n" "$C" '<login do operador>'; cat migrations/20261013_019_registrar_corte.sql; } | docker exec -i -e MYSQL_PWD="$P" Chrono_Desk_DB mysql -uroot sistema_pausas; unset P
```

- [ ] **104.** (7l) Esperado: `cortes = 1`, `corte` = o `C`, `corte_dia_16 = 1`, `corte_no_futuro = 1`, `sem_autor = 0`, `eventos_registro = 1`, `eventos_remocao = 0`, `procedimentos_soltos = 0`, `inconsistente = 0`. **PARE se:** `inconsistente = 1` (regra 16a) ou saida diferente do esperado.

```bash
read -rsp 'Senha root do MySQL: ' P; echo; docker exec -i -e MYSQL_PWD="$P" Chrono_Desk_DB mysql -uroot --table sistema_pausas < migrations/verificacao/20261013_019_presence_cutoff_check.sql; unset P
```

- [ ] **105.** (7m) Esperado: `ensaio das migrations removido`. **PARE se:** `PARE: ... fora do padrao, nada apagado`.

```bash
case "$E" in /root/ensaio-deploy1.*) sudo rm -r "$E" && echo "ensaio das migrations removido";; *) echo "PARE: $E fora do padrao, nada apagado";; esac; unset E D1
```

## 8. Desligamento rapido e rollback em degraus (so se preciso)

Do degrau mais baixo para o mais alto; os degraus 2 e 3 so com aprovacao previa do responsavel. Riscos de cada degrau: roteiro, passo 8.

- [ ] **106.** (8a, degrau 1) Esperado: nada acima de `fim (esperado: nada acima)`. **PARE se:** alguma linha (a variavel no pool do PHP-FPM venceria o `.env`).

```bash
sudo grep -rn 'STATUS_PRESENCE_ENABLED' /etc/php/*/fpm/ ; echo "fim (esperado: nada acima)"
```

- [ ] **107.** (8a) Esperado: `copia do .env: ... (600 root)`. **PARE se:** `PARE: copia do .env falhou, nada alterado`.

```bash
K=/var/backups/chronodesk/env-antes-interruptor-$(date +%Y%m%d-%H%M%S); if sudo install -m 600 /var/www/.env "$K"; then echo "copia do .env: $K ($(sudo stat -c '%a %U' "$K"))"; if sudo grep -q '^STATUS_PRESENCE_ENABLED=' /var/www/.env; then sudo sed -i 's/^STATUS_PRESENCE_ENABLED=.*/STATUS_PRESENCE_ENABLED=false/' /var/www/.env; else echo 'STATUS_PRESENCE_ENABLED=false' | sudo tee -a /var/www/.env >/dev/null; fi; else echo "PARE: copia do .env falhou, nada alterado"; fi; unset K
```

- [ ] **108.** (8a) Esperado: `STATUS_PRESENCE_ENABLED: OK (desligado)`. **PARE se:** saida diferente do esperado.

```bash
cd /var/www/chronodesk && sudo sh scripts/preflight-deploy.sh /var/www/.env | grep STATUS_PRESENCE_ENABLED
```

- [ ] **109.** (8a) Esperado: nenhuma saida. Para religar: os comandos 107 a 109 com `true` (roteiro, 8a). **PARE se:** erro na recarga.

```bash
sudo systemctl reload "php$(php -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;')-fpm"
```

- [ ] **110.** (8a, limpeza das copias) Esperado: so com aprovacao do responsavel. As copias, modo `600`, dono `root` (so nome, modo e dono). **PARE se:** saida diferente do esperado.

```bash
sudo find /var/backups/chronodesk -maxdepth 1 -type f \( -name 'env-antes-interruptor-*' -o -name 'env-antes-app-env-*' \) -printf '%f %m %u\n'
```

- [ ] **111.** (8a, limpeza das copias) Esperado: as mesmas copias ao apagar; o comando 110 de novo nao mostra nada. **PARE se:** saida diferente do esperado.

```bash
sudo find /var/backups/chronodesk -maxdepth 1 -type f \( -name 'env-antes-interruptor-*' -o -name 'env-antes-app-env-*' \) -print -delete
```

- [ ] **112.** (8b, degrau 2) Esperado: so com aprovacao previa do responsavel. `regras_cycle` (anote; maior que `0`: rode o 113 antes do 115). **PARE se:** saida diferente do esperado.

```bash
cd /var/www/chronodesk; export D1=$(sudo cat /var/backups/chronodesk/deploy1-commit); read -rsp 'Senha root do MySQL: ' P; echo; export P; sudo git cat-file -e "$D1:migrations/verificacao/20261007_f2b_cycle_check.sql" && bash -o pipefail -c 'sudo git show "$D1:migrations/verificacao/20261007_f2b_cycle_check.sql" | docker exec -i -e MYSQL_PWD="$P" Chrono_Desk_DB mysql -uroot --table sistema_pausas'; unset P
```

- [ ] **113.** (8b) Esperado: so com `regras_cycle` maior que `0`, com o codigo novo no ar. `ciclo trocado por undefined`. **PARE se:** sem a mensagem.

```bash
cd /var/www/chronodesk; export D1=$(sudo cat /var/backups/chronodesk/deploy1-commit); read -rsp 'Senha root do MySQL: ' P; echo; export P; sudo git cat-file -e "$D1:migrations/rollback/20261007_f2b_cycle_down.sql" && bash -o pipefail -c 'sudo git show "$D1:migrations/rollback/20261007_f2b_cycle_down.sql" | docker exec -i -e MYSQL_PWD="$P" Chrono_Desk_DB mysql -uroot sistema_pausas' && echo "ciclo trocado por undefined"; unset P
```

- [ ] **114.** (8b) Esperado: com o codigo novo no ar. `perfis da 016b revertidos`. **PARE se:** sem a mensagem.

```bash
cd /var/www/chronodesk; export D1=$(sudo cat /var/backups/chronodesk/deploy1-commit); read -rsp 'Senha root do MySQL: ' P; echo; export P; sudo git cat-file -e "$D1:migrations/rollback/20261002_016b_lideranca_profile_data_down.sql" && bash -o pipefail -c 'sudo git show "$D1:migrations/rollback/20261002_016b_lideranca_profile_data_down.sql" | docker exec -i -e MYSQL_PWD="$P" Chrono_Desk_DB mysql -uroot sistema_pausas' && echo "perfis da 016b revertidos"; unset P
```

- [ ] **115.** (8b) Esperado: `codigo anterior no ar: 596db67; .htaccess local de volta: 6 0`. **PARE se:** sem a mensagem, ou outra contagem do `.htaccess`.

```bash
cd /var/www/chronodesk; sudo git checkout --detach "$(sudo cat /var/backups/chronodesk/pre-deploy-commit)" && sudo cp -p /var/backups/chronodesk/htaccess-local-antes-deploy1 .htaccess && echo "codigo anterior no ar: $(sudo git rev-parse --short HEAD); .htaccess local de volta: $(sudo git diff --numstat -- .htaccess | cut -f1,2)"
```

- [ ] **116.** (8b) Esperado: build sem erro. **PARE se:** falha no build: avise o responsavel.

```bash
cd /var/www/chronodesk/frontend && sudo npm ci && sudo npm run build
```

- [ ] **117.** (8b) Esperado: nenhuma saida. Arquivo de manutencao ainda presente: tire-o como no comando 91. **PARE se:** erro na recarga.

```bash
sudo systemctl reload "php$(php -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;')-fpm"
```

- [ ] **118.** (8b, BIZ-02) Esperado: `marcadores_biz02 = 0`. **PARE se:** saida diferente do esperado.

```bash
read -rsp 'Senha root do MySQL: ' P; echo; docker exec -i -e MYSQL_PWD="$P" Chrono_Desk_DB mysql -uroot --table sistema_pausas -e "DROP TABLE IF EXISTS manutencao_biz02_datetime; DELETE FROM manutencao_marcadores WHERE chave LIKE 'biz02%'; SELECT COUNT(*) AS marcadores_biz02 FROM manutencao_marcadores WHERE chave LIKE 'biz02%';"; unset P
```

- [ ] **119.** (8c, degrau 3) Esperado: so com aprovacao e depois do degrau 2. `rollback da 019 aplicado`. **PARE se:** sem a mensagem (nao rodou; copia `*_bkp_019` ja existente: renomear e repetir).

```bash
cd /var/www/chronodesk; export D1=$(sudo cat /var/backups/chronodesk/deploy1-commit); read -rsp 'Senha root do MySQL: ' P; echo; export P; sudo git cat-file -e "$D1:migrations/rollback/20261013_019_presence_cutoff_down.sql" && bash -o pipefail -c 'sudo git show "$D1:migrations/rollback/20261013_019_presence_cutoff_down.sql" | docker exec -i -e MYSQL_PWD="$P" Chrono_Desk_DB mysql -uroot sistema_pausas' && echo "rollback da 019 aplicado"; unset P
```

- [ ] **120.** (8c) Esperado: `rollback da 014 aplicado`. **PARE se:** sem a mensagem (copia `*_bkp_014` ja existente: renomear e repetir).

```bash
cd /var/www/chronodesk; export D1=$(sudo cat /var/backups/chronodesk/deploy1-commit); read -rsp 'Senha root do MySQL: ' P; echo; export P; sudo git cat-file -e "$D1:migrations/rollback/20261013_014_employee_archive_down.sql" && bash -o pipefail -c 'sudo git show "$D1:migrations/rollback/20261013_014_employee_archive_down.sql" | docker exec -i -e MYSQL_PWD="$P" Chrono_Desk_DB mysql -uroot sistema_pausas' && echo "rollback da 014 aplicado"; unset P
```

- [ ] **121.** (8c) Esperado: so com `linhas = 0` na verificacao da 012. `rollback da 012 aplicado`. **PARE se:** sem a mensagem (copia `*_bkp_012` ja existente: renomear e repetir).

```bash
cd /var/www/chronodesk; export D1=$(sudo cat /var/backups/chronodesk/deploy1-commit); read -rsp 'Senha root do MySQL: ' P; echo; export P; sudo git cat-file -e "$D1:migrations/rollback/20261007_012_absences_down.sql" && bash -o pipefail -c 'sudo git show "$D1:migrations/rollback/20261007_012_absences_down.sql" | docker exec -i -e MYSQL_PWD="$P" Chrono_Desk_DB mysql -uroot sistema_pausas' && echo "rollback da 012 aplicado"; unset P
```

- [ ] **122.** (8c) Esperado: `rollback da 011 aplicado`. **PARE se:** sem a mensagem (copia `*_bkp_011` ja existente: renomear e repetir).

```bash
cd /var/www/chronodesk; export D1=$(sudo cat /var/backups/chronodesk/deploy1-commit); read -rsp 'Senha root do MySQL: ' P; echo; export P; sudo git cat-file -e "$D1:migrations/rollback/20261005_011_schedule_history_down.sql" && bash -o pipefail -c 'sudo git show "$D1:migrations/rollback/20261005_011_schedule_history_down.sql" | docker exec -i -e MYSQL_PWD="$P" Chrono_Desk_DB mysql -uroot sistema_pausas' && echo "rollback da 011 aplicado"; unset P
```

- [ ] **123.** (8c) Esperado: `rollback da 017 aplicado`. **PARE se:** sem a mensagem.

```bash
cd /var/www/chronodesk; export D1=$(sudo cat /var/backups/chronodesk/deploy1-commit); read -rsp 'Senha root do MySQL: ' P; echo; export P; sudo git cat-file -e "$D1:migrations/rollback/20261002_017_shift_attachment_team_down.sql" && bash -o pipefail -c 'sudo git show "$D1:migrations/rollback/20261002_017_shift_attachment_team_down.sql" | docker exec -i -e MYSQL_PWD="$P" Chrono_Desk_DB mysql -uroot sistema_pausas' && echo "rollback da 017 aplicado"; unset P
```

- [ ] **124.** (8c) Esperado: `rollback da 016 aplicado`. **PARE se:** sem a mensagem (`rollback_016_abortado_perfil_lideranca_em_uso`: nada alterado; ha cadastro `lideranca`).

```bash
cd /var/www/chronodesk; export D1=$(sudo cat /var/backups/chronodesk/deploy1-commit); read -rsp 'Senha root do MySQL: ' P; echo; export P; sudo git cat-file -e "$D1:migrations/rollback/20261002_016_lideranca_profile_down.sql" && bash -o pipefail -c 'sudo git show "$D1:migrations/rollback/20261002_016_lideranca_profile_down.sql" | docker exec -i -e MYSQL_PWD="$P" Chrono_Desk_DB mysql -uroot sistema_pausas' && echo "rollback da 016 aplicado"; unset P
```

- [ ] **125.** (8c) Esperado: `rollback da 015 aplicado`. **PARE se:** sem a mensagem.

```bash
cd /var/www/chronodesk; export D1=$(sudo cat /var/backups/chronodesk/deploy1-commit); read -rsp 'Senha root do MySQL: ' P; echo; export P; sudo git cat-file -e "$D1:migrations/rollback/20261001_015_login_user_throttle_down.sql" && bash -o pipefail -c 'sudo git show "$D1:migrations/rollback/20261001_015_login_user_throttle_down.sql" | docker exec -i -e MYSQL_PWD="$P" Chrono_Desk_DB mysql -uroot sistema_pausas' && echo "rollback da 015 aplicado"; unset P
```

## 9. Depois de um ciclo estavel, com o responsavel

- [ ] **126.** (9a, estacao) Esperado: push do responsavel, depois de um ciclo estavel; antes, troque `<commit validado na estacao>`. A linha `<antigo>..<novo>` com `-> codex/modernizacao-ui-backend`. **PARE se:** `rejected` ou `non-fast-forward`: nunca `--force`; o responsavel decide.

```powershell
git push origin <commit validado na estacao>:codex/modernizacao-ui-backend
```

- [ ] **9b.** Contagem dos logs de novo (comando 18): so `302`, `410` e `403` desde o deploy. A remocao das paginas legadas e do responsavel, no repositorio, num commit proprio (roteiro, 9b).
- [ ] **9c.** Rotina de backup instalada, teste de restauracao e so entao a correcao historica do BIZ-02 (roteiro, 9c).
- [ ] **9d.** Pendencia LGPD das copias de junho: decisao do responsavel (inventario, secao 8).
- [ ] **9e.** Limpeza com aprovacao: backup do dia, copia do `.htaccess` local, copias do `.env`, `funcionarios_perfil_016b` (roteiro, 9e).
