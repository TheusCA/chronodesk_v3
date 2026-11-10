# Deploy 1 da Parte B: folha de execucao do dia

Gerada do roteiro "Deploy 1 da Parte B (roteiro unico e consolidado ...)" do `DEPLOY_LINUX.md`: os mesmos comandos, na mesma ordem (a trava `scripts/qa-deploy-runbook.php` confere). O roteiro e a referencia: o detalhe de cada esperado, as opcoes e os riscos estao la; o que muda desde o `596db67`, em `docs/DEPLOY1_INVENTARIO.md`. Mudou o roteiro, muda esta folha no mesmo commit.

**Shell root, sem `sudo`.** O `/etc/sudoers` da VM tem `Defaults noexec` (nao muda): com `sudo` na frente, `awk`, `node`, `gzip` e o PHP filho nao rodam. Na vespera e no dia, abra o terminal da VM com `sudo -i` e rode **todos** os comandos desta folha nele, como estao (nenhum leva `sudo`; o que roda como `www-data` usa `runuser`). Terminal reaberto: `sudo -i` e a conferencia do ambiente de novo.

**Sem valor real nesta folha**, nem impressa nem anotada nela: nada de senha, IP, hostname, nome ou login. Os marcadores `<...>` sao trocados so no terminal, na hora; os numeros vao para o desenho (9.9, 9.12 e 9.13), nunca para chat ou chamado. A senha do root do MySQL e sempre digitada no `read -rsp` (sem eco).

Data, janela, vespera, indisponibilidade e quem acompanha (iguais as do roteiro; a trava confere):
- **Data e janela do deploy**, fora do horario de operacao: 11/11/2026, das 19:00 as 23:00 (horario de Brasilia); corte do F5 pela D2: 16/11/2026.
- **Vespera** (pre-requisitos do passo 0, ensaio do BIZ-02 e ensaios das migrations): 10/11/2026.
- **Indisponibilidade prevista** (manutencao, passos 6b a 6g; inventario, secao 9): cerca de 30 minutos, entre 20:00 e 21:00, com o turno da noite em operacao.
- **Quem acompanha** (alem de quem executa): o responsavel pela infraestrutura da VM.

**Regras de parada em toda a folha:** qualquer linha com `PARE`, ou `FALHOU` num ensaio onde nao era esperado: pare e envie so a contagem ou a mensagem. Verificacao da 019 com `inconsistente = 1`: pare, nao registre o corte, nao apague nem altere linha e avise o responsavel. Corte do BIZ-02 com `ABORTADO PELAS GUARDAS`: pare em manutencao e avise. Nada de `git pull`, e nenhum `git fetch` depois do comando 50. Onde o "PARE se" diz "saida diferente do esperado", pare e confira o roteiro antes de seguir.

## 0. Antes do dia, com o responsavel

O responsavel publica o `feat/employee-form-rhf-zod` antes do 0a. Anote os numeros: os passos seguintes comparam.

- [ ] **1.** (00, ambiente) Esperado: antes, `sudo -i` no terminal da VM. Oito linhas `OK` (`id -u = 0`, `bash -c`, `tar` com `gzip`, `find -exec`, `runuser -u www-data -- php -v`, PHP filho como `www-data`, `npm -v`, `git` no clone) e `ambiente: OK`. **PARE se:** qualquer `BLOQUEADO` ou `PARE: ambiente com BLOQUEADO`: nada roda na vespera ate a infraestrutura resolver (o sudoers nao muda).

```bash
R=OK; [ "$(id -u)" = 0 ] && echo "OK id -u = 0" || { echo "BLOQUEADO id -u (o shell nao e root)"; R=PARE; }; bash -c 'exit 0' && echo "OK bash -c" || { echo "BLOQUEADO bash -c"; R=PARE; }; tar -czf - -C /etc hostname 2>/dev/null | tar -tzf - >/dev/null 2>&1 && echo "OK tar com gzip" || { echo "BLOQUEADO tar com gzip"; R=PARE; }; [ "$(find /etc/hostname -maxdepth 0 -exec echo ok \; 2>/dev/null)" = ok ] && echo "OK find -exec" || { echo "BLOQUEADO find -exec"; R=PARE; }; runuser -u www-data -- php -v >/dev/null 2>&1 && echo "OK runuser -u www-data -- php -v" || { echo "BLOQUEADO runuser -u www-data -- php -v"; R=PARE; }; [ "$(runuser -u www-data -- php -r '$p = proc_open([PHP_BINARY, "-v"], [1 => ["pipe", "w"]], $x); if (!is_resource($p)) { exit(1); } stream_get_contents($x[1]); echo proc_close($p) === 0 ? "ok" : "falha";' 2>/dev/null)" = ok ] && echo "OK php filho como www-data (proc_open, como no qa-security)" || { echo "BLOQUEADO php filho como www-data"; R=PARE; }; npm -v >/dev/null 2>&1 && echo "OK npm -v" || { echo "BLOQUEADO npm -v"; R=PARE; }; git -C /var/www/chronodesk rev-parse --git-dir >/dev/null 2>&1 && echo "OK git no clone" || { echo "BLOQUEADO git no clone"; R=PARE; }; [ "$R" = OK ] && echo "ambiente: OK" || echo "PARE: ambiente com BLOQUEADO"; unset R
```

- [ ] **2.** (0a) Esperado: `branch: codex/modernizacao-ui-backend`, `HEAD no ar: 596db67 (OK)` (ou ancestral, anote o hash curto), `commits a aplicar` maior que `280`, `alteracoes locais: 1`, `alteracoes fora do .htaccess: 0`, `.htaccess` com `6` e `0`. **PARE se:** linha `PARE`, outro branch, alteracao fora do `.htaccess` ou outra contagem no `.htaccess` (traga os numeros).

```bash
cd /var/www/chronodesk; git fetch origin feat/employee-form-rhf-zod; H=$(git rev-parse HEAD); echo "branch: $(git rev-parse --abbrev-ref HEAD)"; if [ "$H" = "596db67c67a9cd84e53b28aaef4c3316191fe87c" ]; then echo "HEAD no ar: 596db67 (OK)"; elif git merge-base --is-ancestor "$H" origin/feat/employee-form-rhf-zod; then echo "HEAD no ar: ancestral do branch do deploy (OK; hash curto $(git rev-parse --short HEAD))"; else echo "PARE: o HEAD do servidor nao e o 596db67 nem ancestral do branch do deploy"; fi; echo "commits a aplicar: $(git rev-list --count "$H"..origin/feat/employee-form-rhf-zod)"; echo "alteracoes locais: $(git status --porcelain | wc -l)"; echo "alteracoes fora do .htaccess: $(git status --porcelain | grep -vc '^ M \.htaccess$')"; echo ".htaccess, linhas acrescentadas e removidas: $(git diff --numstat -- .htaccess | cut -f1,2)"; unset H
```

- [ ] **3.** (0a) Esperado: `mysql` 8.0.29 ou mais novo; `migration_015 = 0`, `tabela_019 = 0`, `tabelas_manutencao = 0`, `tabelas = 33`. **PARE se:** qualquer numero diferente (traga os numeros).

```bash
read -rsp 'Senha root do MySQL: ' P; echo; docker exec -i -e MYSQL_PWD="$P" Chrono_Desk_DB mysql -uroot --table sistema_pausas -e "SELECT VERSION() AS mysql, (SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'login_user_throttle') AS migration_015, (SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'portal_presence_cutoff') AS tabela_019, (SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME LIKE 'manutencao\_%') AS tabelas_manutencao, (SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()) AS tabelas;"; unset P
```

- [ ] **4.** (0a) Esperado: `rotina de backup instalada: 0`; o diretorio de backup como estiver (anote); `arquivo de manutencao: ausente`. **PARE se:** `arquivo de manutencao: PRESENTE` (o portal ficaria em 503 no `merge`).

```bash
echo "rotina de backup instalada: $(systemctl list-unit-files chronodesk-backup.service 2>/dev/null | grep -c '^chronodesk-backup')"; echo "diretorio /var/backups/chronodesk: $(test -d /var/backups/chronodesk && echo existe || echo ausente)"; echo "arquivo de manutencao: $(test -e /var/www/chronodesk.maintenance && echo PRESENTE || echo ausente)"
```

- [ ] **5.** (0b) Esperado: `access_role_005 = 1`, `ad_login_repetidos = 0`, `uq_ad_login = 0`, `perfil_016b = 0`, `anexo_team = 0`, `anexo_reference_month = 1`, `uq_regra_por_colaborador = 1`, `rule_type_010 = 1`, `rule_config_010 = 1`, `team_regras_n1_n2 = 1`, `team_excecoes_n1_n2 = 1`, `colunas_011_014 = 0`, `absence_type = 1`, `colunas_012 = 0`, `ausencias = 0`, `copias_de_rollback = 0`, `copias_de_junho = 4`, `nao_innodb = 0`, `minutos_fora_de_utc = 0`, `fusos_nomeados` maior que `0`. **PARE se:** qualquer numero diferente (cada coluna diz qual migration nao encontraria o "antes"; roteiro, 0b).

```bash
read -rsp 'Senha root do MySQL: ' P; echo; docker exec -i -e MYSQL_PWD="$P" Chrono_Desk_DB mysql -uroot --table sistema_pausas -e "SELECT (SELECT COLUMN_TYPE = 'enum(''tecnico'',''gestor'',''admin'',''somente_leitura'')' FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'funcionarios' AND COLUMN_NAME = 'access_role') AS access_role_005, (SELECT COUNT(*) FROM (SELECT ad_login FROM funcionarios WHERE ad_login IS NOT NULL GROUP BY ad_login HAVING COUNT(*) > 1) d) AS ad_login_repetidos, (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'funcionarios' AND INDEX_NAME = 'uq_funcionarios_ad_login') AS uq_ad_login, (SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'funcionarios_perfil_016b') AS perfil_016b, (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'portal_shift_attachments' AND COLUMN_NAME = 'team') AS anexo_team, (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'portal_shift_attachments' AND COLUMN_NAME = 'reference_month') AS anexo_reference_month, (SELECT COUNT(DISTINCT INDEX_NAME) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'portal_schedule_rules' AND INDEX_NAME = 'uq_schedule_rule_employee') AS uq_regra_por_colaborador, (SELECT LOCATE('fixed_weekdays', COLUMN_TYPE) > 0 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'portal_schedule_rules' AND COLUMN_NAME = 'rule_type') AS rule_type_010, (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'portal_schedule_rules' AND COLUMN_NAME = 'rule_config') AS rule_config_010, (SELECT COLUMN_TYPE = 'enum(''n1'',''n2'')' FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'portal_schedule_rules' AND COLUMN_NAME = 'team') AS team_regras_n1_n2, (SELECT COLUMN_TYPE = 'enum(''n1'',''n2'')' FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'portal_schedule_exceptions' AND COLUMN_NAME = 'team') AS team_excecoes_n1_n2, (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'funcionarios' AND COLUMN_NAME IN ('fora_mapa_escala', 'arquivado_em', 'arquivado_por')) AS colunas_011_014, (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'portal_absences' AND COLUMN_NAME = 'absence_type') AS absence_type, (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'portal_absences' AND COLUMN_NAME IN ('category', 'period_type', 'cancelled_by')) AS colunas_012, (SELECT COUNT(*) FROM portal_absences) AS ausencias, (SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME LIKE '%\_bkp\_0%') AS copias_de_rollback, (SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME REGEXP '_[0-9]{8}_[0-9]{6}$') AS copias_de_junho, (SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = 'BASE TABLE' AND (ENGINE IS NULL OR ENGINE <> 'InnoDB')) AS nao_innodb, ABS(TIMESTAMPDIFF(MINUTE, UTC_TIMESTAMP(), NOW())) AS minutos_fora_de_utc, (SELECT COUNT(*) FROM mysql.time_zone_name) AS fusos_nomeados;"; unset P
```

- [ ] **6.** (0c) Esperado: `audience` so com os valores padrao (roteiro, 0c); nenhum `synced` nem `sync_error`; nenhum lancamento de 24 h. **PARE se:** nao para; valor de `audience` fora do padrao ou lancamento de 24 h pendente: decidir antes do dia.

```bash
read -rsp 'Senha root do MySQL: ' P; echo; docker exec -i -e MYSQL_PWD="$P" Chrono_Desk_DB mysql -uroot --table sistema_pausas -e "SET time_zone = 'America/Sao_Paulo'; SELECT audience, COUNT(*) AS avisos FROM portal_announcements GROUP BY audience; SELECT 'horas_extras' AS tabela, status, COUNT(*) AS n FROM portal_overtime_entries GROUP BY status UNION ALL SELECT 'correcao_ponto', status, COUNT(*) FROM portal_time_adjustments GROUP BY status; SELECT status, COUNT(*) AS lancamentos_de_24h FROM portal_overtime_entries WHERE start_time = end_time GROUP BY status;"; unset P
```

- [ ] **7.** (0d) Esperado: p50 e p95 do `status.php` (anote; 2 ou 3 rodadas, a mesma conta, sem `CONSULTAS`, `N=200`, aba fechada). **PARE se:** `unknown revision` ou `Not a valid object name`: avise o responsavel antes do dia (o passo 2 depende deste `fetch`).

```bash
cd /var/www/chronodesk; git fetch origin feat/employee-form-rhf-zod && git cat-file -e origin/feat/employee-form-rhf-zod:scripts/medir-polling.sh && bash <(git show origin/feat/employee-form-rhf-zod:scripts/medir-polling.sh)
```

- [ ] **8.** (0d) Esperado: p50 e p95 do `dashboard.php` (anote; registre na 9.9). **PARE se:** nada (so anotar).

```bash
git cat-file -e origin/feat/employee-form-rhf-zod:scripts/medir-polling.sh && URL=http://127.0.0.1/api/portal/dashboard.php bash <(git show origin/feat/employee-form-rhf-zod:scripts/medir-polling.sh)
```

- [ ] **9.** (0e) Esperado: contagens anotadas; referencia `pas_compartilhados_hoje = 6`, `vinculos_de_inativos = 1`, `vinculos_lideranca = 2`. `[N_PAS]` = `pas_compartilhados_hoje`. **PARE se:** nada (so anotar).

```bash
read -rsp 'Senha root do MySQL: ' P; echo; docker exec -i -e MYSQL_PWD="$P" Chrono_Desk_DB mysql -uroot --table sistema_pausas -e "SET time_zone = 'America/Sao_Paulo'; SELECT (SELECT COUNT(*) FROM portal_pa_inventory WHERE active = 1) AS pas_inventario, (SELECT COUNT(*) FROM portal_pa_assignments WHERE active = 1) AS vinculos_ativos, (SELECT COUNT(*) FROM portal_pa_assignments WHERE active = 1 AND (valid_until IS NULL OR valid_until >= CURDATE())) AS vinculos_vigentes_ou_futuros, (SELECT COUNT(*) FROM (SELECT pa_number FROM portal_pa_assignments WHERE active = 1 AND valid_from <= CURDATE() AND (valid_until IS NULL OR valid_until >= CURDATE()) GROUP BY pa_number HAVING COUNT(*) > 1) t) AS pas_compartilhados_hoje, (SELECT COUNT(*) FROM portal_pa_assignments a JOIN funcionarios f ON f.id = a.employee_id WHERE a.active = 1 AND (a.valid_until IS NULL OR a.valid_until >= CURDATE()) AND f.ativo = 0) AS vinculos_de_inativos, (SELECT COUNT(*) FROM portal_pa_assignments WHERE active = 1 AND team = 'lideranca' AND (valid_until IS NULL OR valid_until >= CURDATE())) AS vinculos_lideranca;"; unset P
```

- [ ] **10.** (0e) Esperado: `sobreposicoes_mesmo_colaborador = 0`, `periodo_invertido = 0`, `vinculos_fora_do_inventario = 0`. **PARE se:** um dos tres maior que `0` (dado antigo: decidir antes do dia).

```bash
read -rsp 'Senha root do MySQL: ' P; echo; docker exec -i -e MYSQL_PWD="$P" Chrono_Desk_DB mysql -uroot --table sistema_pausas -e "SET time_zone = 'America/Sao_Paulo'; SELECT (SELECT COUNT(*) FROM portal_pa_assignments a JOIN portal_pa_assignments b ON b.employee_id = a.employee_id AND b.id > a.id AND b.active = 1 AND b.valid_from <= COALESCE(a.valid_until, '9999-12-31') AND COALESCE(b.valid_until, '9999-12-31') >= a.valid_from WHERE a.active = 1) AS sobreposicoes_mesmo_colaborador, (SELECT COUNT(*) FROM portal_pa_assignments WHERE active = 1 AND valid_until < valid_from) AS periodo_invertido, (SELECT COUNT(*) FROM portal_pa_assignments a WHERE a.active = 1 AND NOT EXISTS (SELECT 1 FROM portal_pa_inventory i WHERE i.active = 1 AND i.pa_number = a.pa_number)) AS vinculos_fora_do_inventario, (SELECT COUNT(*) FROM portal_pa_assignments a LEFT JOIN portal_schedule_rules r ON r.employee_id = a.employee_id AND r.effective_from <= CURDATE() AND (r.effective_until IS NULL OR r.effective_until >= CURDATE()) WHERE a.active = 1 AND a.valid_from <= CURDATE() AND (a.valid_until IS NULL OR a.valid_until >= CURDATE()) AND a.team <> 'lideranca' AND a.schedule_rule_type <> COALESCE(IF(r.rule_type = 'cycle', 'undefined', r.rule_type), 'undefined')) AS copia_tipo_divergente_hoje, (SELECT COUNT(*) FROM portal_pa_assignments WHERE active = 1 AND notes IS NOT NULL AND notes <> '') AS vinculos_com_observacao, (SELECT COUNT(*) FROM portal_pa_map WHERE record_status = 'active') AS legado_total, (SELECT COUNT(*) FROM portal_pa_map WHERE record_status = 'active' AND work_date >= CURDATE()) AS legado_futuro;"; unset P
```

- [ ] **11.** (0f) Esperado: referencia `ativos_n1 = 13`, `ativos_n2 = 9`, `ativos_lideranca = 3`; `ativos_outras_equipes`, `jornada_invertida` e `almoco_fora_da_jornada` em `0`. `[N_SEM_REGRA]` = `n1_n2_sem_regra_hoje`. Num dia util, no expediente. **PARE se:** um dos tres maior que `0` (corrigir o cadastro pela tela antes do dia).

```bash
read -rsp 'Senha root do MySQL: ' P; echo; docker exec -i -e MYSQL_PWD="$P" Chrono_Desk_DB mysql -uroot --table sistema_pausas -e "SET time_zone = 'America/Sao_Paulo'; SELECT (SELECT COUNT(*) FROM funcionarios WHERE ativo = 1 AND LOWER(TRIM(equipe)) = 'n1') AS ativos_n1, (SELECT COUNT(*) FROM funcionarios WHERE ativo = 1 AND LOWER(TRIM(equipe)) = 'n2') AS ativos_n2, (SELECT COUNT(*) FROM funcionarios WHERE ativo = 1 AND LOWER(TRIM(equipe)) IN ('lideranca', 'liderança', 'na', 'não se aplica', 'nao se aplica', 'nao_se_aplica', 'sem_equipe')) AS ativos_lideranca, (SELECT COUNT(*) FROM funcionarios WHERE ativo = 1 AND LOWER(TRIM(equipe)) NOT IN ('n1', 'n2', 'lideranca', 'liderança', 'na', 'não se aplica', 'nao se aplica', 'nao_se_aplica', 'sem_equipe')) AS ativos_outras_equipes, (SELECT COUNT(*) FROM funcionarios f WHERE f.ativo = 1 AND LOWER(TRIM(f.equipe)) IN ('n1', 'n2') AND NOT EXISTS (SELECT 1 FROM portal_schedule_rules r WHERE r.employee_id = f.id AND r.rule_type <> 'undefined' AND r.effective_from <= CURDATE() AND (r.effective_until IS NULL OR r.effective_until >= CURDATE()))) AS n1_n2_sem_regra_hoje, (SELECT COUNT(*) FROM funcionarios WHERE ativo = 1 AND jornada_entrada >= jornada_saida) AS jornada_invertida, (SELECT COUNT(*) FROM funcionarios WHERE ativo = 1 AND NOT (jornada_entrada <= almoco_inicio AND almoco_inicio < almoco_fim AND almoco_fim <= jornada_saida)) AS almoco_fora_da_jornada, (SELECT COUNT(*) FROM portal_absences WHERE status = 'approved' AND starts_on <= CURDATE() AND ends_on >= CURDATE()) AS ausencias_aprovadas_hoje, (SELECT COUNT(*) FROM portal_schedule_exceptions WHERE exception_date = CURDATE() AND exception_type IN ('day_off', 'absence', 'vacation', 'leave')) AS excecoes_de_ausencia_hoje;"; unset P
```

- [ ] **12.** (0f) Esperado: `rotulo_que_o_php_nao_reconhece = 0`. **PARE se:** maior que `0` (corrigir o cadastro pela tela antes do dia).

```bash
read -rsp 'Senha root do MySQL: ' P; echo; docker exec -i -e MYSQL_PWD="$P" Chrono_Desk_DB mysql -uroot --table sistema_pausas -e "SELECT COUNT(*) AS rotulo_que_o_php_nao_reconhece FROM funcionarios WHERE ativo = 1 AND (CAST(LOWER(TRIM(equipe)) AS BINARY) NOT IN ('n1', 'n2', 'lideranca', 'liderança', 'na', 'não se aplica', 'nao se aplica', 'nao_se_aplica', 'sem_equipe') OR LOCATE(CAST('Ç' AS BINARY), CAST(TRIM(equipe) AS BINARY)) > 0 OR LOCATE(CAST('Ã' AS BINARY), CAST(TRIM(equipe) AS BINARY)) > 0);"; unset P
```

- [ ] **13.** (0g) Esperado: `tabela_019 = 0`, `gatilhos_019 = 0`, `copia_rollback = 0`, `eventos_registro = 0`, `eventos_remocao = 0`, `inconsistente = 0`. **PARE se:** `inconsistente = 1` (regra 16a) ou saida diferente do esperado.

```bash
cd /var/www/chronodesk; read -rsp 'Senha root do MySQL: ' P; echo; export P; git cat-file -e origin/feat/employee-form-rhf-zod:migrations/verificacao/20261013_019_presence_cutoff_check.sql && bash -o pipefail -c 'git show origin/feat/employee-form-rhf-zod:migrations/verificacao/20261013_019_presence_cutoff_check.sql | docker exec -i -e MYSQL_PWD="$P" Chrono_Desk_DB mysql -uroot --table sistema_pausas'; unset P
```

- [ ] **14.** (0h) Esperado: `usuario_lido = 1`, `concessoes_drop = 0`, `concessoes_trigger = 0`. **PARE se:** `usuario_lido = 0`. Concessoes maiores que `0` nao bloqueiam: decisao do responsavel antes do dia (VULN-002).

```bash
U=$(grep -E '^DB_USER=' /var/www/.env | cut -d= -f2- | tr -d "\"'"); read -rsp 'Senha root do MySQL: ' P; echo; docker exec -i -e MYSQL_PWD="$P" Chrono_Desk_DB mysql -uroot --table -e "SELECT '$U' <> '' AS usuario_lido, (SELECT COUNT(*) FROM (SELECT GRANTEE, PRIVILEGE_TYPE FROM information_schema.USER_PRIVILEGES UNION ALL SELECT GRANTEE, PRIVILEGE_TYPE FROM information_schema.SCHEMA_PRIVILEGES UNION ALL SELECT GRANTEE, PRIVILEGE_TYPE FROM information_schema.TABLE_PRIVILEGES) g WHERE g.GRANTEE LIKE CONCAT('''', '$U', '''@%') AND g.PRIVILEGE_TYPE = 'DROP') AS concessoes_drop, (SELECT COUNT(*) FROM (SELECT GRANTEE, PRIVILEGE_TYPE FROM information_schema.USER_PRIVILEGES UNION ALL SELECT GRANTEE, PRIVILEGE_TYPE FROM information_schema.SCHEMA_PRIVILEGES UNION ALL SELECT GRANTEE, PRIVILEGE_TYPE FROM information_schema.TABLE_PRIVILEGES) g WHERE g.GRANTEE LIKE CONCAT('''', '$U', '''@%') AND g.PRIVILEGE_TYPE = 'TRIGGER') AS concessoes_trigger;"; unset P U
```

- [ ] **15.** (0i) Esperado: a data do deploy ja esta no comando (`2026-11-11`). `corte planejado: 2026-11-16` (anote: e o `[DATA_CORTE]`, 16/11). **PARE se:** `PARE: data do corte invalida` ou outro corte (a linha da data do roteiro diz 16/11/2026).

```bash
D='2026-11-11'; if [ "$(date -d "$D" +%d)" -ge 16 ]; then C=$(date -d "$(date -d "$D" +%Y-%m-01) +1 month" +%Y-%m-16); else C=$(date -d "$D" +%Y-%m-16); fi; [[ "$C" =~ ^[0-9]{4}-[0-9]{2}-16$ ]] && echo "corte planejado: $C" || echo "PARE: data do corte invalida ($C)"
```

- [ ] **16.** (0i) Esperado: referencia `ativos_n1_n2 = 22`; anote `sem_regra_no_corte` e `sem_regra_na_competencia` (o ideal e `0`). **PARE se:** nao para aqui; maior que `0` no dia impede o registro do corte (comando 116).

```bash
read -rsp 'Senha root do MySQL: ' P; echo; docker exec -i -e MYSQL_PWD="$P" Chrono_Desk_DB mysql -uroot --table sistema_pausas -e "SET @corte := '$C'; WITH RECURSIVE dias (d) AS (SELECT CAST(@corte AS DATE) UNION ALL SELECT d + INTERVAL 1 DAY FROM dias WHERE d < @corte + INTERVAL 1 MONTH - INTERVAL 1 DAY) SELECT COUNT(*) AS ativos_n1_n2, COALESCE(SUM(NOT EXISTS (SELECT 1 FROM portal_schedule_rules r WHERE r.employee_id = f.id AND r.rule_type <> 'undefined' AND r.effective_from <= @corte AND (r.effective_until IS NULL OR r.effective_until >= @corte))), 0) AS sem_regra_no_corte, COALESCE(SUM(EXISTS (SELECT 1 FROM dias WHERE NOT EXISTS (SELECT 1 FROM portal_schedule_rules r WHERE r.employee_id = f.id AND r.rule_type <> 'undefined' AND r.effective_from <= dias.d AND (r.effective_until IS NULL OR r.effective_until >= dias.d)))), 0) AS sem_regra_na_competencia FROM funcionarios f WHERE f.ativo = 1 AND LOWER(f.equipe) IN ('n1', 'n2');"; unset P
```

- [ ] **17.** (0j) Esperado: `app_env=staging`, `secret_key_caracteres` 32 ou mais, `secret_key_texto_de_exemplo=0`. Confira a olho no `.env`: `AD_ADMIN_USERS` com o login do responsavel, `ENABLE_LOCAL_ADMIN` desligado, `HEALTH_ALLOWED_IPS` com a monitoracao, se houver. **PARE se:** saida diferente do esperado: no dia, pule o comando 58 (nao troque o `APP_ENV`).

```bash
awk -F= '/^APP_ENV=/{print "app_env=" $2} /^SECRET_KEY=/{v=substr($0, 12); gsub(/["\047]/, "", v); print "secret_key_caracteres=" length(v); print "secret_key_texto_de_exemplo=" (tolower(v) ~ /gere_uma_chave|troque|changeme|change_me/)}' /var/www/.env
```

- [ ] **18.** (0k, estacao) Esperado: a versao (8.19 ou mais nova; antes, `gitleaks detect`) e `no leaks found`. **PARE se:** qualquer achado: nao siga (revogar, gerar outro, tirar do historico).

```powershell
git fetch origin; gitleaks version; gitleaks git --redact --log-opts="596db67c67a9cd84e53b28aaef4c3316191fe87c..origin/feat/employee-form-rhf-zod" .
```

- [ ] **19.** (0l) Esperado: so contagens por metodo, caminho e codigo (anote; registre na 9.12). **PARE se:** nada; uso maior que `0` nas paginas `POST`: avise no aviso a gestao.

```bash
sh -c 'cat /var/log/apache2/chronodesk_access.log /var/log/apache2/chronodesk_access.log.1 2>/dev/null; zcat /var/log/apache2/chronodesk_access.log.*.gz 2>/dev/null' | awk '{print $6, $7, $9}' | sed -E 's/\?[^ ]*//' | grep -E '^"(GET|POST) /(admin|admin_login|admin_logout|login|logout|metricas|index)\.php |^"GET /static/js/admin(_users)?\.js ' | sort | uniq -c
```

- [ ] **20.** (0l) Esperado: `GET 302` no `/`; as demais, como hoje (anote). **PARE se:** nada (so anotar); o comando 109 repete com o esperado novo.

```bash
for p in / /index.php /login.php /admin_login.php /admin_logout.php /admin.php /metricas.php /logout.php; do echo "$p GET $(curl -k -s -o /dev/null -w '%{http_code}' "<URL>$p") POST $(curl -k -s -o /dev/null -w '%{http_code}' -X POST "<URL>$p")"; done; for j in admin.js admin_users.js; do echo "$j $(curl -k -s -o /dev/null -w '%{http_code}' "<URL>/static/js/$j")"; done
```

- [ ] **21.** (0m) Esperado: seis linhas `OK` e `espaco livre: OK` (minimos: codigo 500 MB, cache do npm 200, `/var/backups` 100, raiz do Docker 200, `/tmp` 50, `/root` 50; o `npm ci` ocupa uns 114 MB, ate 150, mais ate 27 MB de cache; o build, 0,9 MB). Anote os livres. **PARE se:** qualquer `BAIXO` ou `PARE: espaco livre abaixo do minimo` (liberar espaco e da infraestrutura; nada se apaga de improviso).

```bash
cd /var/www/chronodesk/frontend; NPMC=$(npm config get cache); test -e "$NPMC" || NPMC=$(dirname "$NPMC"); DROOT=$(docker info --format '{{.DockerRootDir}}'); ESP=OK; for LIM in "/var/www/chronodesk:500" "$NPMC:200" "/var/backups:100" "$DROOT:200" "/tmp:50" "/root:50"; do DIR=${LIM%:*}; MIN=${LIM##*:}; LIVRE=$(df -BM --output=avail "$DIR" | tail -n 1 | tr -dc '0-9'); if [ "${LIVRE:-0}" -ge "$MIN" ]; then echo "OK $DIR (disco $(df --output=target "$DIR" | tail -n 1)): $LIVRE MB livres, minimo $MIN"; else echo "BAIXO $DIR: ${LIVRE:-?} MB livres, minimo $MIN"; ESP=PARE; fi; done; [ "$ESP" = OK ] && echo "espaco livre: OK" || echo "PARE: espaco livre abaixo do minimo"; unset NPMC DROOT ESP LIM DIR MIN LIVRE
```

- [ ] **22.** (0n, copia) Esperado: `tabelas` 33 e 33; `manutencao_*` 0 e 0; `gatilhos, rotinas e eventos` 0 e 0; `funcionarios`, `pausas` e `audit_log` na copia iguais ou menores que na producao (a copia e de 09/11, 15:49). **PARE se:** copia ausente (`copia = 0`), `manutencao_*` na copia maior que `0` (o 0n ja rodou nela) ou gatilho, rotina ou evento maior que `0`.

```bash
read -rsp 'Senha root do MySQL: ' P; echo; docker exec -i -e MYSQL_PWD="$P" Chrono_Desk_DB mysql -uroot --table -e "SELECT 'tabelas' AS item, (SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = 'sistema_pausas') AS producao, (SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = 'sistema_pausas_ensaio') AS copia UNION ALL SELECT 'manutencao_*', (SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = 'sistema_pausas' AND TABLE_NAME LIKE 'manutencao%'), (SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = 'sistema_pausas_ensaio' AND TABLE_NAME LIKE 'manutencao%') UNION ALL SELECT 'gatilhos, rotinas e eventos', (SELECT COUNT(*) FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = 'sistema_pausas') + (SELECT COUNT(*) FROM information_schema.ROUTINES WHERE ROUTINE_SCHEMA = 'sistema_pausas') + (SELECT COUNT(*) FROM information_schema.EVENTS WHERE EVENT_SCHEMA = 'sistema_pausas'), (SELECT COUNT(*) FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = 'sistema_pausas_ensaio') + (SELECT COUNT(*) FROM information_schema.ROUTINES WHERE ROUTINE_SCHEMA = 'sistema_pausas_ensaio') + (SELECT COUNT(*) FROM information_schema.EVENTS WHERE EVENT_SCHEMA = 'sistema_pausas_ensaio') UNION ALL SELECT 'funcionarios', (SELECT COUNT(*) FROM sistema_pausas.funcionarios), (SELECT COUNT(*) FROM sistema_pausas_ensaio.funcionarios) UNION ALL SELECT 'pausas', (SELECT COUNT(*) FROM sistema_pausas.pausas), (SELECT COUNT(*) FROM sistema_pausas_ensaio.pausas) UNION ALL SELECT 'audit_log', (SELECT COUNT(*) FROM sistema_pausas.audit_log), (SELECT COUNT(*) FROM sistema_pausas_ensaio.audit_log);"; unset P
```

- [ ] **23.** (0n, BIZ-02 na copia) Esperado: `CORTE REGISTRADO` com `g1`, `g2` e `g3` em `0` (anote a contagem por coluna); script 2 antes com `corrigida = 0` e `decisao_antes_do_pedido = 0`; `CORRECAO EXECUTADA` e `nao_corrigidas = 0`; script 2 depois com `a_corrigir = 0`, `no_futuro = 0`, `decisao_antes_do_pedido = 0`, `utc_3h_adiante = 0`; `ROLLBACK EXECUTADO`; script 2 por fim igual ao antes. V8 e V9: so quantas linhas. **PARE se:** `ABORTADO PELAS GUARDAS`, `PARE: ... falhou` ou `decisao_antes_do_pedido` maior que `0` depois do script 3: o deploy nao segue; apague a copia (comando 42) e avise (analise antes do deploy).

```bash
cd /var/www/chronodesk; read -rsp 'Senha root do MySQL: ' P; echo; export P; for S in biz02_01_registrar_corte biz02_02_verificar biz02_03_corrigir biz02_02_verificar biz02_04_rollback biz02_02_verificar; do export S; echo "=== $S"; git cat-file -e "origin/feat/employee-form-rhf-zod:migrations/correcao_dados/$S.sql" && bash -o pipefail -c 'git show "origin/feat/employee-form-rhf-zod:migrations/correcao_dados/$S.sql" | docker exec -i -e MYSQL_PWD="$P" Chrono_Desk_DB mysql -uroot --table sistema_pausas_ensaio' || { echo "PARE: $S falhou"; break; }; done; unset P; unset S
```

- [ ] **24.** (0n, copia depois) Esperado: `manutencao_na_copia = 2`, `corte_na_copia = 1`, `manutencao_na_producao = 0`. A copia fica para o 0q (comandos 26 a 42), que a apaga no fim. **PARE se:** `manutencao_na_producao` maior que `0` (avise ja) ou saida diferente do esperado.

```bash
read -rsp 'Senha root do MySQL: ' P; echo; docker exec -i -e MYSQL_PWD="$P" Chrono_Desk_DB mysql -uroot --table -e "SELECT (SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = 'sistema_pausas_ensaio' AND TABLE_NAME LIKE 'manutencao%') AS manutencao_na_copia, (SELECT COUNT(*) FROM sistema_pausas_ensaio.manutencao_marcadores WHERE chave = 'biz02_corte') AS corte_na_copia, (SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = 'sistema_pausas' AND TABLE_NAME LIKE 'manutencao%') AS manutencao_na_producao;"; unset P
```

- [ ] **25.** (0o, estacao) Esperado: `Validacao local concluida com sucesso`, roteiro de navegador com `falharam: 0` e o hash completo: anote como `<commit validado na estacao>`. **PARE se:** qualquer falha: nao ha deploy com esse commit.

```powershell
git fetch origin; git worktree add --detach ..\chronodesk-deploy1 origin/feat/employee-form-rhf-zod; Set-Location ..\chronodesk-deploy1\frontend; npm ci; Set-Location ..; $env:CHROME_PATH='C:\Program Files\Google\Chrome\Application\chrome.exe'; powershell -NoProfile -ExecutionPolicy Bypass -File scripts\test-local.ps1 -SkipHttp -Browser; git rev-parse HEAD
```

- [ ] **0p.** Aviso unico a gestao enviado (texto no roteiro, 0p) na vespera, com `[N_PAS]` (comando 9), `[N_SEM_REGRA]` (comando 11) e `[DATA_CORTE]` (comando 15) preenchidos no dia do envio. A data, a janela e o horario da indisponibilidade ja estao no texto (iguais as linhas do inicio desta folha). Nenhum marcador no texto enviado. Quem usa o CSV de horas extras em outra ferramenta tambem recebe.

## 0q. Ensaios das migrations na vespera (10/11), na copia `sistema_pausas_ensaio`

Depois do 0n (comandos 22 a 24) e do 0o (comando 25), sobre o commit validado. Todo ensaio le so da copia (`ORIGEM`), nunca da producao, e apaga o proprio banco de ensaio no fim; o `funcionarios` do ensaio fica no estado do `596db67` (roteiro, 0q). Os comandos 41 e 42 rodam sempre, tambem depois de um `PARE`.

- [ ] **26.** (0q, vespera) Esperado: antes, troque `<commit validado na estacao>` (o hash do comando 25). `extraido em /root/ensaio-vespera.<sufixo> (<hash curto>)`, o hash curto igual ao comeco do validado. **PARE se:** `PARE: o commit validado nao esta no clone` (rode o comando 2 de novo) ou falha ao extrair; rode o comando 41.

```bash
cd /var/www/chronodesk; export K='<commit validado na estacao>'; EV=$(mktemp -d /root/ensaio-vespera.XXXXXX); export EV; git cat-file -e "$K^{commit}" && bash -o pipefail -c 'git archive "$K" migrations scripts/ensaio-migration.sh | tar -x -C "$EV"' && echo "extraido em $EV ($(git rev-parse --short "$K"))" || echo "PARE: o commit validado nao esta no clone (rode o 0a de novo) ou a extracao falhou"; unset K
```

- [ ] **27.** (0q, ensaio da 015) Esperado: a tabela aparece nas duas execucoes e some nos dois rollbacks; `funcionarios` sem mudanca. **PARE se:** `FALHOU` ou saida diferente do esperado.

```bash
env ORIGEM=sistema_pausas_ensaio bash "$EV/scripts/ensaio-migration.sh" d1_015 "$EV/migrations/20261001_015_login_user_throttle.sql" "$EV/migrations/rollback/20261001_015_login_user_throttle_down.sql"
```

- [ ] **28.** (0q, ensaio da 016) Esperado: as duas execucoes iguais (`lideranca` no ENUM, indice unico); o rollback volta ao checksum e as contagens iniciais, o 2o tambem. **PARE se:** `migration_016_abortada_ad_login_duplicado`, `FALHOU` ou saida diferente do esperado.

```bash
env ORIGEM=sistema_pausas_ensaio bash "$EV/scripts/ensaio-migration.sh" d1_016 "$EV/migrations/20261002_016_lideranca_profile.sql" "$EV/migrations/rollback/20261002_016_lideranca_profile_down.sql"
```

- [ ] **29.** (0q, ensaio da 016b) Esperado: depois da 1a execucao, os `admin` da Lideranca como `lideranca` e o `admin` inativo fora dela como `tecnico`, um evento de cada (anote as contagens por equipe, perfil e situacao: o comando 98 compara); a 2a nao muda nada; o rollback volta. **PARE se:** `FALHOU` ou saida diferente do esperado.

```bash
env ORIGEM=sistema_pausas_ensaio PREPARO="$EV/migrations/20261002_016_lideranca_profile.sql" bash "$EV/scripts/ensaio-migration.sh" d1_016b "$EV/migrations/20261002_016b_lideranca_profile_data.sql" "$EV/migrations/rollback/20261002_016b_lideranca_profile_data_down.sql" audit_log
```

- [ ] **30.** (0q, ensaio da 017) Esperado: a coluna `team` e o indice aparecem nas duas execucoes e somem nos dois rollbacks. **PARE se:** `FALHOU` ou saida diferente do esperado.

```bash
env ORIGEM=sistema_pausas_ensaio bash "$EV/scripts/ensaio-migration.sh" d1_017 "$EV/migrations/20261002_017_shift_attachment_team.sql" "$EV/migrations/rollback/20261002_017_shift_attachment_team_down.sql" portal_shift_attachments
```

- [ ] **31.** (0q, ensaio da 011) Esperado: as duas execucoes iguais; `colaboradores_com_mais_de_uma_aberta`, `sobreposicoes` e `inicio_repetido` em `0`; contagens e assinatura iguais as do estado inicial; o 1o rollback volta e o 2o para de proposito. **PARE se:** `FALHOU` ou saida diferente do esperado.

```bash
env ORIGEM=sistema_pausas_ensaio DADOS=portal_schedule_rules,portal_schedule_exceptions VERIFICA="$EV/migrations/verificacao/20261005_011_schedule_history_check.sql" bash "$EV/scripts/ensaio-migration.sh" d1_011 "$EV/migrations/20261005_011_schedule_history.sql" "$EV/migrations/rollback/20261005_011_schedule_history_down.sql"
```

- [ ] **32.** (0q, ensaio da 014) Esperado: como no roteiro do F4, passo 3 (variante sem dados). **PARE se:** `FALHOU` ou saida diferente do esperado.

```bash
env ORIGEM=sistema_pausas_ensaio VERIFICA="$EV/migrations/verificacao/20261013_014_employee_archive_check.sql" bash "$EV/scripts/ensaio-migration.sh" d1_014 "$EV/migrations/20261013_014_employee_archive.sql" "$EV/migrations/rollback/20261013_014_employee_archive_down.sql"
```

- [ ] **33.** (0q, ensaio da 014) Esperado: como no roteiro do F4, passo 3 (variante com dados). **PARE se:** `FALHOU` ou saida diferente do esperado.

```bash
env ORIGEM=sistema_pausas_ensaio DADOS=portal_pa_assignments,portal_schedule_rules,portal_schedule_exceptions,portal_absences VERIFICA="$EV/migrations/verificacao/20261013_014_employee_archive_check.sql" bash "$EV/scripts/ensaio-migration.sh" d1_014_dados "$EV/migrations/20261013_014_employee_archive.sql" "$EV/migrations/rollback/20261013_014_employee_archive_down.sql"
```

- [ ] **34.** (0q, ensaio da 014) Esperado: como no roteiro do F4, passo 3 (variante do rollback; o 2o rollback para). **PARE se:** `FALHOU` ou saida diferente do esperado.

```bash
env ORIGEM=sistema_pausas_ensaio PREPARO="$EV/migrations/20261013_014_employee_archive.sql" VERIFICA="$EV/migrations/verificacao/20261013_014_employee_archive_check.sql" bash "$EV/scripts/ensaio-migration.sh" d1_014_rollback "$EV/migrations/rollback/20261013_014_ensaio_linhas_f4.sql" "$EV/migrations/rollback/20261013_014_employee_archive_down.sql"
```

- [ ] **35.** (0q, ensaio da 019) Esperado: como no roteiro do F5, passo 4. **PARE se:** `FALHOU` ou saida diferente do esperado.

```bash
env ORIGEM=sistema_pausas_ensaio VERIFICA="$EV/migrations/verificacao/20261013_019_presence_cutoff_check.sql" bash "$EV/scripts/ensaio-migration.sh" d1_019 "$EV/migrations/20261013_019_presence_cutoff.sql" "$EV/migrations/rollback/20261013_019_presence_cutoff_down.sql" audit_log
```

- [ ] **36.** (0q, ensaio da 019) Esperado: o registro de 2099 grava e o 2o para. **PARE se:** `FALHOU` ou saida diferente do esperado.

```bash
{ printf "SET @corte := '2099-12-16'; SET @autor := 'qa.ensaio';\n"; cat "$EV/migrations/20261013_019_registrar_corte.sql"; } | tee "$EV/registro_d1.sql" > /dev/null; env ORIGEM=sistema_pausas_ensaio PREPARO="$EV/migrations/20261013_019_presence_cutoff.sql" VERIFICA="$EV/migrations/verificacao/20261013_019_presence_cutoff_check.sql" bash "$EV/scripts/ensaio-migration.sh" d1_019_registro "$EV/registro_d1.sql" "$EV/migrations/rollback/20261013_019_presence_cutoff_down.sql" audit_log
```

- [ ] **37.** (0q, ensaio da 019) Esperado: a data passada para nas duas execucoes. **PARE se:** `FALHOU` ou saida diferente do esperado.

```bash
{ printf "SET @corte := '2020-01-16'; SET @autor := 'qa.ensaio';\n"; cat "$EV/migrations/20261013_019_registrar_corte.sql"; } | tee "$EV/registro_d1_passado.sql" > /dev/null; env ORIGEM=sistema_pausas_ensaio PREPARO="$EV/migrations/20261013_019_presence_cutoff.sql" VERIFICA="$EV/migrations/verificacao/20261013_019_presence_cutoff_check.sql" bash "$EV/scripts/ensaio-migration.sh" d1_019_passado "$EV/registro_d1_passado.sql" "$EV/migrations/rollback/20261013_019_presence_cutoff_down.sql" audit_log
```

- [ ] **38.** (0q, ensaio da 012) Esperado: a tabela vazia migra (roteiro do F3, passo 3). **PARE se:** `FALHOU` ou saida diferente do esperado.

```bash
env ORIGEM=sistema_pausas_ensaio DADOS=portal_absences VERIFICA="$EV/migrations/verificacao/20261007_012_absences_check.sql" bash "$EV/scripts/ensaio-migration.sh" d1_012 "$EV/migrations/20261007_012_absences.sql" "$EV/migrations/rollback/20261007_012_absences_down.sql"
```

- [ ] **39.** (0q, ensaio da 012) Esperado: a linha antiga faz a 012 parar sem alterar nada. **PARE se:** `FALHOU` ou saida diferente do esperado.

```bash
env ORIGEM=sistema_pausas_ensaio DADOS=portal_absences PREPARO="$EV/migrations/rollback/20261007_012_ensaio_linha_antiga.sql" VERIFICA="$EV/migrations/verificacao/20261007_012_absences_check.sql" bash "$EV/scripts/ensaio-migration.sh" d1_012_parada "$EV/migrations/20261007_012_absences.sql" "$EV/migrations/rollback/20261007_012_absences_down.sql"
```

- [ ] **40.** (0q, ensaio da 012) Esperado: o rollback com linhas do F3 volta e o 2o para. **PARE se:** `FALHOU` ou saida diferente do esperado.

```bash
env ORIGEM=sistema_pausas_ensaio DADOS=portal_absences PREPARO="$EV/migrations/20261007_012_absences.sql" VERIFICA="$EV/migrations/verificacao/20261007_012_absences_check.sql" bash "$EV/scripts/ensaio-migration.sh" d1_012_rollback "$EV/migrations/rollback/20261007_012_ensaio_linhas_f3.sql" "$EV/migrations/rollback/20261007_012_absences_down.sql"
```

- [ ] **41.** (0q, limpeza) Esperado: sempre, tambem depois de um `PARE`. `ensaio da vespera removido`. **PARE se:** `PARE: ... fora do padrao, nada apagado`.

```bash
case "$EV" in /root/ensaio-vespera.*) rm -r "$EV" && echo "ensaio da vespera removido";; *) echo "PARE: $EV fora do padrao, nada apagado";; esac; unset EV
```

- [ ] **42.** (0q, copia de ensaio) Esperado: sempre, tambem depois de um `PARE`: digite `sistema_pausas_ensaio` no pedido; `copia_restante = 0` e `producao = 1`. **PARE se:** `nada apagado`: repita com o nome certo (a copia tem dados pessoais e nao passa da vespera).

```bash
read -rp 'Apagar a copia de ensaio? Digite o nome dela (sistema_pausas_ensaio): ' NC; if [ "$NC" = "sistema_pausas_ensaio" ]; then read -rsp 'Senha root do MySQL: ' P; echo; docker exec -i -e MYSQL_PWD="$P" Chrono_Desk_DB mysql -uroot --table -e "DROP DATABASE IF EXISTS sistema_pausas_ensaio; SELECT (SELECT COUNT(*) FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = 'sistema_pausas_ensaio') AS copia_restante, (SELECT COUNT(*) FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = 'sistema_pausas') AS producao;"; unset P; else echo "nada apagado: nome diferente de sistema_pausas_ensaio"; fi; unset NC
```

## 0r. Conferencias da auditoria pre-deploy na vespera (10/11)

Depois do 0q, so leitura e so contagens (roteiro, 0r; `docs/AUDITORIA_PRE_DEPLOY1.md`).

- [ ] **43.** (0r, C1) Esperado: antes, troque `<commit validado na estacao>` (o hash do comando 25). `RESULTADO=0`, com as mesmas linhas `OK` do comando 57 (o preflight do dia). **PARE se:** qualquer `FALHA`: o deploy nao acontece com esse `.env` (o codigo novo aplica as travas tambem em `staging`); o responsavel corrige o `.env` antes do dia e o comando roda de novo.

```bash
cd /var/www/chronodesk; export K='<commit validado na estacao>'; git cat-file -e "$K:scripts/preflight-deploy.sh" && bash -o pipefail -c 'git show "$K:scripts/preflight-deploy.sh" | sh -s -- /var/www/.env'; unset K
```

- [ ] **44.** (0r, C2) Esperado: `registro do npm alcancado` (`PONG` ou `Ping success`, conforme a versao do npm). **PARE se:** `PARE: a VM nao alcanca o registro do npm`: a infraestrutura libera o acesso antes do dia.

```bash
cd /var/www/chronodesk/frontend; npm ping && echo "registro do npm alcancado" || echo "PARE: a VM nao alcanca o registro do npm"
```

- [ ] **45.** (0r, M9, C3 e A1) Esperado: `admin_rotulo_antigo = 0` e `ad_login_ativo = 1`; anote `audit_username_tamanho` (M10, depois do deploy) e, por tipo de excecao, `n` e `com_observacao` (A1, depois do deploy). **PARE se:** `admin_rotulo_antigo` maior que `0` (corrigir a equipe pela tela antes do dia) ou `ad_login_ativo = 0` (decisao do responsavel antes do dia).

```bash
read -rsp 'Senha root do MySQL: ' P; echo; docker exec -i -e MYSQL_PWD="$P" Chrono_Desk_DB mysql -uroot --table sistema_pausas -e "SELECT (SELECT COUNT(*) FROM funcionarios WHERE access_role = 'admin' AND LOWER(TRIM(equipe)) IN ('na', 'não se aplica', 'nao se aplica', 'nao_se_aplica', 'sem_equipe')) AS admin_rotulo_antigo, (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'funcionarios' AND COLUMN_NAME = 'ad_login_ativo') AS ad_login_ativo, (SELECT CHARACTER_MAXIMUM_LENGTH FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'audit_log' AND COLUMN_NAME = 'username') AS audit_username_tamanho; SELECT exception_type, COUNT(*) AS n, SUM(note IS NOT NULL AND note <> '') AS com_observacao FROM portal_schedule_exceptions GROUP BY exception_type;"; unset P
```

- [ ] **46.** (0r, risco 6) Esperado: as quatro contagens em `0`. **PARE se:** qualquer uma maior que `0`: um processo fora do Apache grava no banco durante a manutencao; o responsavel decide como para-lo na janela.

```bash
echo "timers com chronodesk: $(systemctl list-timers --all 2>/dev/null | grep -ci chronodesk)"; echo "cron do root com chronodesk: $(crontab -l 2>/dev/null | grep -ci chronodesk)"; echo "cron do www-data, linhas ativas: $(crontab -l -u www-data 2>/dev/null | grep -v '^#' | grep -c .)"; echo "arquivos em /etc/cron* com chronodesk: $(grep -rlis chronodesk /etc/cron* 2>/dev/null | wc -l)"
```

- [ ] **47.** (0r, log do PHP) Esperado: o caminho do arquivo que recebe os erros do PHP (o `PHP_ERROR_LOG` do `.env` vence; senao, o `error_log` do pool ou do `php.ini`; senao, o log de erros do vhost): anote como `<log de erros do PHP>` (comando 103). **PARE se:** nenhum identificado: combine com o responsavel antes do dia.

```bash
echo "PHP_ERROR_LOG no .env: $(sed -n 's/^PHP_ERROR_LOG=//p' /var/www/.env | tr -d "\"'")"; grep -HE '^[[:space:]]*(error_log|php_admin_value\[error_log\]|php_value\[error_log\]|catch_workers_output)[[:space:]]*=' /etc/php/*/fpm/php.ini /etc/php/*/fpm/pool.d/*.conf; ls -l /var/log/apache2/*error*.log /var/log/php*-fpm.log 2>/dev/null
```

## 1. No dia, antes de comecar

- [ ] **Passo 1.** A data e a janela (**11/11/2026, das 19:00 as 23:00**) e quem acompanha presente; 0a a 0r feitos na vespera, com os numeros anotados; o ensaio do BIZ-02 (comando 23) sem guarda disparada e os 14 ensaios (comandos 27 a 40) como esperado, sobre o mesmo commit validado, com a copia apagada (comando 42); aviso enviado; `<commit validado na estacao>` e `<log de erros do PHP>` (comando 47) em maos; ninguem gravando cadastro, escala, ausencia ou Mapa PA. Daqui em diante, um shell so, na ordem (as variaveis `E`, `Q`, `B` e `C` passam de um comando ao outro).

- [ ] **48.** (1, ambiente) Esperado: antes, `sudo -i` no terminal do dia; oito linhas `OK` e `ambiente: OK` (o mesmo do comando 1). **PARE se:** qualquer `BLOQUEADO`: nada foi alterado; nao comece.

```bash
R=OK; [ "$(id -u)" = 0 ] && echo "OK id -u = 0" || { echo "BLOQUEADO id -u (o shell nao e root)"; R=PARE; }; bash -c 'exit 0' && echo "OK bash -c" || { echo "BLOQUEADO bash -c"; R=PARE; }; tar -czf - -C /etc hostname 2>/dev/null | tar -tzf - >/dev/null 2>&1 && echo "OK tar com gzip" || { echo "BLOQUEADO tar com gzip"; R=PARE; }; [ "$(find /etc/hostname -maxdepth 0 -exec echo ok \; 2>/dev/null)" = ok ] && echo "OK find -exec" || { echo "BLOQUEADO find -exec"; R=PARE; }; runuser -u www-data -- php -v >/dev/null 2>&1 && echo "OK runuser -u www-data -- php -v" || { echo "BLOQUEADO runuser -u www-data -- php -v"; R=PARE; }; [ "$(runuser -u www-data -- php -r '$p = proc_open([PHP_BINARY, "-v"], [1 => ["pipe", "w"]], $x); if (!is_resource($p)) { exit(1); } stream_get_contents($x[1]); echo proc_close($p) === 0 ? "ok" : "falha";' 2>/dev/null)" = ok ] && echo "OK php filho como www-data (proc_open, como no qa-security)" || { echo "BLOQUEADO php filho como www-data"; R=PARE; }; npm -v >/dev/null 2>&1 && echo "OK npm -v" || { echo "BLOQUEADO npm -v"; R=PARE; }; git -C /var/www/chronodesk rev-parse --git-dir >/dev/null 2>&1 && echo "OK git no clone" || { echo "BLOQUEADO git no clone"; R=PARE; }; [ "$R" = OK ] && echo "ambiente: OK" || echo "PARE: ambiente com BLOQUEADO"; unset R
```


## 2. Branch, commit gravado, fast-forward e regras locais do `.htaccess`

- [ ] **49.** (2) Esperado: `diretorio de backup: 700 root` (criado aqui, M1) e `branch OK: codex/modernizacao-ui-backend`. **PARE se:** `PARE: o clone esta em ...` ou sem a linha do diretorio.

```bash
cd /var/www/chronodesk; install -d -o root -g root -m 700 /var/backups/chronodesk && echo "diretorio de backup: $(stat -c '%a %U' /var/backups/chronodesk)"; B=$(git rev-parse --abbrev-ref HEAD); [ "$B" = "codex/modernizacao-ui-backend" ] && echo "branch OK: $B" || echo "PARE: o clone esta em $B, nao em codex/modernizacao-ui-backend"; unset B
```

- [ ] **50.** (2) Esperado: antes, troque `<commit validado na estacao>`. O hash gravado, igual ao do comando 25. **PARE se:** `PARE: origin/feat/employee-form-rhf-zod (...) nao e o commit validado na estacao`.

```bash
cd /var/www/chronodesk; git fetch origin feat/employee-form-rhf-zod && K=$(git rev-parse origin/feat/employee-form-rhf-zod) && if [ "$K" = "<commit validado na estacao>" ]; then echo "$K" | tee /var/backups/chronodesk/deploy1-commit; else echo "PARE: origin/feat/employee-form-rhf-zod ($K) nao e o commit validado na estacao"; fi; unset K
```

- [ ] **51.** (2) Esperado: `deploy1-commit OK`. **PARE se:** `PARE: deploy1-commit ausente ou incompleto`.

```bash
cd /var/www/chronodesk; D1=$(cat /var/backups/chronodesk/deploy1-commit); git cat-file -e "$D1^{commit}" && git cat-file -e "$D1:scripts/ensaio-migration.sh" && git cat-file -e "$D1:migrations/20261013_019_registrar_corte.sql" && git cat-file -e "$D1:migrations/correcao_dados/biz02_01_registrar_corte.sql" && echo "deploy1-commit OK" || echo "PARE: deploy1-commit ausente ou incompleto"; unset D1
```

- [ ] **52.** (2) Esperado: `fast-forward OK: N commits a aplicar` (o `N` do comando 2) e `migrations 013, 018 ou 020 no commit: 0`. **PARE se:** `PARE: o HEAD nao e ancestral ...` (nada alterado; opcoes a, b e c do passo 2 do roteiro, decisao do responsavel) ou contagem de migrations maior que `0`.

```bash
cd /var/www/chronodesk; D1=$(cat /var/backups/chronodesk/deploy1-commit); git merge-base --is-ancestor HEAD "$D1" && echo "fast-forward OK: $(git rev-list --count HEAD.."$D1") commits a aplicar" || echo "PARE: o HEAD nao e ancestral do deploy1-commit (o merge --ff-only recusaria)"; echo "migrations 013, 018 ou 020 no commit: $(git ls-tree -r --name-only "$D1" migrations/ | grep -cE '_(013|018|020)_')"; unset D1
```

- [ ] **53.** (2, `.htaccess`) Esperado: `regras locais no .htaccess do commit: 3` e, antes de `fim das linhas locais ...`, nenhuma linha ou so uma regra `^$` para `/app/`. **PARE se:** outra linha listada (o `merge` descartaria uma regra que o servidor usa).

```bash
cd /var/www/chronodesk; D1=$(cat /var/backups/chronodesk/deploy1-commit); echo "regras locais no .htaccess do commit: $(git show "$D1:.htaccess" | grep -cxF -e 'RewriteBase /' -e 'RewriteRule ^var/www/chronodesk(/.*)?$ /app/ [R=302,L]' -e 'RewriteRule ^chronodesk(/.*)?$ /app/ [R=302,L]')"; git diff -U0 -- .htaccess | sed -n 's/^+\([^+].*\)$/\1/p' | sed 's/^[[:space:]]*//; s/[[:space:]]*$//' | grep -v -e '^$' -e '^#' | grep -vxF --file=<(git show "$D1:.htaccess" | sed 's/^[[:space:]]*//; s/[[:space:]]*$//'); echo "fim das linhas locais que o commit nao tem"; unset D1
```

## 3. Ponto de rollback, copias do `.htaccess` local e do `app/`, preflight, `APP_ENV` e backup

- [ ] **54.** (3a) Esperado: o hash do `596db67` e `alteracoes locais: 1; fora do .htaccess: 0`. **PARE se:** alteracao fora do `.htaccess`.

```bash
cd /var/www/chronodesk; install -d -o root -g root -m 700 /var/backups/chronodesk; git rev-parse HEAD | tee /var/backups/chronodesk/pre-deploy-commit; echo "alteracoes locais: $(git status --porcelain | wc -l); fora do .htaccess: $(git status --porcelain | grep -vc '^ M \.htaccess$')"
```

- [ ] **55.** (3a) Esperado: `copia do .htaccess local: ... (... root)` (ou `ja existe e e igual`). **PARE se:** `PARE: ... existe e e diferente` ou `PARE: copia do .htaccess falhou`.

```bash
cd /var/www/chronodesk; H=/var/backups/chronodesk/htaccess-local-antes-deploy1; if test -e "$H"; then cmp -s .htaccess "$H" && echo "copia do .htaccess local ja existe e e igual: $H" || echo "PARE: $H existe e e diferente do .htaccess atual"; else cp -p .htaccess "$H" && cmp -s .htaccess "$H" && echo "copia do .htaccess local: $H ($(stat -c '%a %U' "$H"))" || echo "PARE: copia do .htaccess falhou"; fi; unset H
```

- [ ] **56.** (3a, `app/`) Esperado: `copia do app/: /var/backups/chronodesk/app-antes-deploy1 (<tamanho>; <N> arquivos)`, perto de 1 MB (ou `ja existe e e igual`). **PARE se:** linha do `diff -rq`, `PARE: ... existe e e diferente` ou `PARE: copia do app/ falhou` (nada alterado; sem a copia, o degrau 2 depende do `npm`).

```bash
cd /var/www/chronodesk; A=/var/backups/chronodesk/app-antes-deploy1; if test -e "$A"; then diff -rq app "$A" && echo "copia do app/ ja existe e e igual: $A" || echo "PARE: $A existe e e diferente do app/ atual"; else cp -a app "$A" && diff -rq app "$A" && echo "copia do app/: $A ($(du -sh "$A" | cut -f1); $(find "$A" -type f | wc -l) arquivos)" || echo "PARE: copia do app/ falhou"; fi; unset A
```

- [ ] **57.** (3b, preflight ANTES do `APP_ENV`) Esperado: `RESULTADO=0`, com `AD_ADMIN_USERS: OK`, `ENABLE_LOCAL_ADMIN: OK`, `STATUS_PRESENCE_ENABLED: OK (ligado)` e `FORCE_HTTPS: OK` (`AUTH_SOURCE: AVISO` nao reprova). **PARE se:** qualquer `FALHA`: nada foi alterado (o `APP_ENV` ainda e `staging`) e o comando 58 nao roda.

```bash
cd /var/www/chronodesk; export D1=$(cat /var/backups/chronodesk/deploy1-commit); git cat-file -e "$D1:scripts/preflight-deploy.sh" && bash -o pipefail -c 'git show "$D1:scripts/preflight-deploy.sh" | sh -s -- /var/www/.env'; unset D1
```

- [ ] **58.** (3c) Esperado: so com os comandos 17 e 57 como esperado (NN7); senao, pule. Antes, deixe pronto no terminal o `sed` inverso do 3c do roteiro. `copia do .env: ... (600 root)` e `app_env_production: 1`. O valor vale na hora, com o codigo anterior no ar: logo depois, abra o portal numa estacao e entre. **PARE se:** `PARE: copia do .env falhou`, `app_env_production: 0`, ou o portal com erro ou indo para `https://`: volte na hora com o `sed` inverso (sem recarga) e avise.

```bash
K=/var/backups/chronodesk/env-antes-app-env-$(date +%Y%m%d-%H%M%S); if install -m 600 /var/www/.env "$K"; then echo "copia do .env: $K ($(stat -c '%a %U' "$K"))"; sed -i 's/^APP_ENV=staging$/APP_ENV=production/' /var/www/.env; echo "app_env_production: $(grep -c '^APP_ENV=production$' /var/www/.env)"; else echo "PARE: copia do .env falhou, nada alterado"; fi; unset K
```

- [ ] **59.** (3d, espaco) Esperado: como no comando 21: seis linhas `OK` e `espaco livre: OK` (minimos: codigo 500 MB, cache do npm 200, `/var/backups` 100, raiz do Docker 200, `/tmp` 50, `/root` 50; o `npm ci` ocupa uns 114 MB, ate 150, mais ate 27 MB de cache; o build, 0,9 MB). **PARE se:** qualquer `BAIXO` ou `PARE` (nada foi alterado; o `npm ci` do comando 99 depende do espaco).

```bash
cd /var/www/chronodesk/frontend; NPMC=$(npm config get cache); test -e "$NPMC" || NPMC=$(dirname "$NPMC"); DROOT=$(docker info --format '{{.DockerRootDir}}'); ESP=OK; for LIM in "/var/www/chronodesk:500" "$NPMC:200" "/var/backups:100" "$DROOT:200" "/tmp:50" "/root:50"; do DIR=${LIM%:*}; MIN=${LIM##*:}; LIVRE=$(df -BM --output=avail "$DIR" | tail -n 1 | tr -dc '0-9'); if [ "${LIVRE:-0}" -ge "$MIN" ]; then echo "OK $DIR (disco $(df --output=target "$DIR" | tail -n 1)): $LIVRE MB livres, minimo $MIN"; else echo "BAIXO $DIR: ${LIVRE:-?} MB livres, minimo $MIN"; ESP=PARE; fi; done; [ "$ESP" = OK ] && echo "espaco livre: OK" || echo "PARE: espaco livre abaixo do minimo"; unset NPMC DROOT ESP LIM DIR MIN LIVRE
```

- [ ] **60.** (3d) Esperado: `backup concluido: /var/backups/chronodesk/deploy1-<data> (<tamanho>)` (anote o diretorio: ponto de restauracao do banco). **PARE se:** `PARE: backup falhou` (sem ponto de restauracao, as migrations nao rodam).

```bash
B=/var/backups/chronodesk/deploy1-$(date +%Y%m%d-%H%M%S); export B; read -rsp 'Senha root do MySQL: ' P; echo; export P; install -d -o root -g root -m 700 "$B" && bash -o pipefail -c 'docker exec -i -e MYSQL_PWD="$P" Chrono_Desk_DB mysqldump -uroot --single-transaction --routines --triggers --no-tablespaces --set-gtid-purged=OFF sistema_pausas | gzip | tee "$B/database.sql.gz" > /dev/null' && gzip -t "$B/database.sql.gz" && tar -czf "$B/estado.tar.gz" -C /var/www/chronodesk --ignore-failed-read estado.json pausas.csv config_sistema.json funcionarios.json && sh -c 'cd "$1" && sha256sum database.sql.gz estado.tar.gz > SHA256SUMS' _ "$B" && echo "backup concluido: $B ($(du -sh "$B" | cut -f1))" || echo "PARE: backup falhou (sem ponto de restauracao, as migrations nao rodam)"; unset P
```

## 4. Migrations antes do codigo: o "antes", a aplicacao e a verificacao de 015, 016, 017, 011, 014 e 019 (os ensaios foram na vespera)

- [ ] **61.** (4a) Esperado: `extraido em /root/ensaio-deploy1.<sufixo>`. **PARE se:** `PARE: falha ao extrair o deploy1-commit`.

```bash
cd /var/www/chronodesk; export D1=$(cat /var/backups/chronodesk/deploy1-commit); E=$(mktemp -d /root/ensaio-deploy1.XXXXXX); export E; bash -o pipefail -c 'git archive "$D1" migrations | tar -x -C "$E"' && echo "extraido em $E" || echo "PARE: falha ao extrair o deploy1-commit"
```

- [ ] **62.** (4a, o "antes" de novo) Esperado: o mesmo esperado do comando 5 (0b), agora com os numeros de hoje. **PARE se:** qualquer numero diferente: pare antes de qualquer migration (nada foi alterado; `ad_login_repetidos` maior que `0` faria a 016 parar).

```bash
read -rsp 'Senha root do MySQL: ' P; echo; docker exec -i -e MYSQL_PWD="$P" Chrono_Desk_DB mysql -uroot --table sistema_pausas -e "SELECT (SELECT COLUMN_TYPE = 'enum(''tecnico'',''gestor'',''admin'',''somente_leitura'')' FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'funcionarios' AND COLUMN_NAME = 'access_role') AS access_role_005, (SELECT COUNT(*) FROM (SELECT ad_login FROM funcionarios WHERE ad_login IS NOT NULL GROUP BY ad_login HAVING COUNT(*) > 1) d) AS ad_login_repetidos, (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'funcionarios' AND INDEX_NAME = 'uq_funcionarios_ad_login') AS uq_ad_login, (SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'funcionarios_perfil_016b') AS perfil_016b, (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'portal_shift_attachments' AND COLUMN_NAME = 'team') AS anexo_team, (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'portal_shift_attachments' AND COLUMN_NAME = 'reference_month') AS anexo_reference_month, (SELECT COUNT(DISTINCT INDEX_NAME) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'portal_schedule_rules' AND INDEX_NAME = 'uq_schedule_rule_employee') AS uq_regra_por_colaborador, (SELECT LOCATE('fixed_weekdays', COLUMN_TYPE) > 0 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'portal_schedule_rules' AND COLUMN_NAME = 'rule_type') AS rule_type_010, (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'portal_schedule_rules' AND COLUMN_NAME = 'rule_config') AS rule_config_010, (SELECT COLUMN_TYPE = 'enum(''n1'',''n2'')' FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'portal_schedule_rules' AND COLUMN_NAME = 'team') AS team_regras_n1_n2, (SELECT COLUMN_TYPE = 'enum(''n1'',''n2'')' FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'portal_schedule_exceptions' AND COLUMN_NAME = 'team') AS team_excecoes_n1_n2, (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'funcionarios' AND COLUMN_NAME IN ('fora_mapa_escala', 'arquivado_em', 'arquivado_por')) AS colunas_011_014, (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'portal_absences' AND COLUMN_NAME = 'absence_type') AS absence_type, (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'portal_absences' AND COLUMN_NAME IN ('category', 'period_type', 'cancelled_by')) AS colunas_012, (SELECT COUNT(*) FROM portal_absences) AS ausencias, (SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME LIKE '%\_bkp\_0%') AS copias_de_rollback, (SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME REGEXP '_[0-9]{8}_[0-9]{6}$') AS copias_de_junho, (SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = 'BASE TABLE' AND (ENGINE IS NULL OR ENGINE <> 'InnoDB')) AS nao_innodb, ABS(TIMESTAMPDIFF(MINUTE, UTC_TIMESTAMP(), NOW())) AS minutos_fora_de_utc, (SELECT COUNT(*) FROM mysql.time_zone_name) AS fusos_nomeados;"; unset P
```

- [ ] **63.** (4b) Esperado: `015 aplicada`. **PARE se:** `PARE: a 015 nao foi aplicada`.

```bash
cd /var/www/chronodesk; export D1=$(cat /var/backups/chronodesk/deploy1-commit); read -rsp 'Senha root do MySQL: ' P; echo; export P; git cat-file -e "$D1:migrations/20261001_015_login_user_throttle.sql" && bash -o pipefail -c 'git show "$D1:migrations/20261001_015_login_user_throttle.sql" | docker exec -i -e MYSQL_PWD="$P" Chrono_Desk_DB mysql -uroot sistema_pausas' && echo "015 aplicada" || echo "PARE: a 015 nao foi aplicada"; unset P
```

- [ ] **64.** (4b) Esperado: `migration_015 = 1`, `linhas_015 = 0`. **PARE se:** saida diferente do esperado.

```bash
read -rsp 'Senha root do MySQL: ' P; echo; docker exec -i -e MYSQL_PWD="$P" Chrono_Desk_DB mysql -uroot --table sistema_pausas -e "SELECT (SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'login_user_throttle') AS migration_015, (SELECT COUNT(*) FROM login_user_throttle) AS linhas_015;"; unset P
```

- [ ] **65.** (4c) Esperado: `016 aplicada`. **PARE se:** `PARE: a 016 nao foi aplicada` (com `migration_016_abortada_ad_login_duplicado`, nada foi alterado).

```bash
cd /var/www/chronodesk; export D1=$(cat /var/backups/chronodesk/deploy1-commit); read -rsp 'Senha root do MySQL: ' P; echo; export P; git cat-file -e "$D1:migrations/20261002_016_lideranca_profile.sql" && bash -o pipefail -c 'git show "$D1:migrations/20261002_016_lideranca_profile.sql" | docker exec -i -e MYSQL_PWD="$P" Chrono_Desk_DB mysql -uroot sistema_pausas' && echo "016 aplicada" || echo "PARE: a 016 nao foi aplicada"; unset P
```

- [ ] **66.** (4c) Esperado: `uq_ad_login = 1`, `access_role_com_lideranca = 1`, `cadastros_lideranca = 0`. **PARE se:** saida diferente do esperado.

```bash
read -rsp 'Senha root do MySQL: ' P; echo; docker exec -i -e MYSQL_PWD="$P" Chrono_Desk_DB mysql -uroot --table sistema_pausas -e "SELECT (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'funcionarios' AND INDEX_NAME = 'uq_funcionarios_ad_login') AS uq_ad_login, (SELECT LOCATE('''lideranca''', COLUMN_TYPE) > 0 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'funcionarios' AND COLUMN_NAME = 'access_role') AS access_role_com_lideranca, (SELECT COUNT(*) FROM funcionarios WHERE access_role = 'lideranca') AS cadastros_lideranca;"; unset P
```

- [ ] **67.** (4d) Esperado: `017 aplicada`. **PARE se:** `PARE: a 017 nao foi aplicada`.

```bash
cd /var/www/chronodesk; export D1=$(cat /var/backups/chronodesk/deploy1-commit); read -rsp 'Senha root do MySQL: ' P; echo; export P; git cat-file -e "$D1:migrations/20261002_017_shift_attachment_team.sql" && bash -o pipefail -c 'git show "$D1:migrations/20261002_017_shift_attachment_team.sql" | docker exec -i -e MYSQL_PWD="$P" Chrono_Desk_DB mysql -uroot sistema_pausas' && echo "017 aplicada" || echo "PARE: a 017 nao foi aplicada"; unset P
```

- [ ] **68.** (4d) Esperado: `migration_017 = 1`, `indice_017 = 1`, `anexos_com_equipe = 0`. **PARE se:** saida diferente do esperado.

```bash
read -rsp 'Senha root do MySQL: ' P; echo; docker exec -i -e MYSQL_PWD="$P" Chrono_Desk_DB mysql -uroot --table sistema_pausas -e "SELECT (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'portal_shift_attachments' AND COLUMN_NAME = 'team') AS migration_017, (SELECT COUNT(DISTINCT INDEX_NAME) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'portal_shift_attachments' AND INDEX_NAME = 'idx_shift_attachment_team') AS indice_017, (SELECT COUNT(*) FROM portal_shift_attachments WHERE team IS NOT NULL) AS anexos_com_equipe;"; unset P
```

- [ ] **69.** (4e) Esperado: `011 aplicada`. **PARE se:** `PARE: a 011 nao foi aplicada`.

```bash
cd /var/www/chronodesk; export D1=$(cat /var/backups/chronodesk/deploy1-commit); read -rsp 'Senha root do MySQL: ' P; echo; export P; git cat-file -e "$D1:migrations/20261005_011_schedule_history.sql" && bash -o pipefail -c 'git show "$D1:migrations/20261005_011_schedule_history.sql" | docker exec -i -e MYSQL_PWD="$P" Chrono_Desk_DB mysql -uroot sistema_pausas' && echo "011 aplicada" || echo "PARE: a 011 nao foi aplicada"; unset P
```

- [ ] **70.** (4e) Esperado: as tres primeiras contagens em `0`. **PARE se:** saida diferente do esperado (desfazer so com o comando 136, com aprovacao).

```bash
read -rsp 'Senha root do MySQL: ' P; echo; docker exec -i -e MYSQL_PWD="$P" Chrono_Desk_DB mysql -uroot --table sistema_pausas < <(cat "$E/migrations/verificacao/20261005_011_schedule_history_check.sql"); unset P
```

- [ ] **71.** (4f, Q-F4) Esperado: referencia `inativos = 5`, `inativos_com_pa = 1`, `inativos_com_regra = 0`, `excecoes_de_inativos = 0` (anote). **PARE se:** nao bloqueia a 014; numeros diferentes: envie ao responsavel antes do comando 110.

```bash
read -rsp 'Senha root do MySQL: ' P; echo; docker exec -i -e MYSQL_PWD="$P" Chrono_Desk_DB mysql -uroot --table sistema_pausas -e "SET time_zone = 'America/Sao_Paulo'; SELECT (SELECT COUNT(*) FROM funcionarios WHERE ativo = 0) AS inativos, (SELECT COUNT(DISTINCT a.employee_id) FROM portal_pa_assignments a JOIN funcionarios f ON f.id = a.employee_id AND f.ativo = 0 WHERE a.active = 1 AND (a.valid_until IS NULL OR a.valid_until >= CURDATE())) AS inativos_com_pa, (SELECT COUNT(DISTINCT r.employee_id) FROM portal_schedule_rules r JOIN funcionarios f ON f.id = r.employee_id AND f.ativo = 0 WHERE (r.effective_until IS NULL OR r.effective_until >= CURDATE()) AND (r.effective_until IS NULL OR r.effective_until >= r.effective_from)) AS inativos_com_regra, (SELECT COUNT(*) FROM portal_schedule_exceptions e JOIN funcionarios f ON f.id = e.employee_id AND f.ativo = 0 WHERE e.exception_date >= CURDATE()) AS excecoes_de_inativos;"; unset P
```

- [ ] **72.** (4g) Esperado: `014 aplicada`. **PARE se:** `PARE: a 014 nao foi aplicada`.

```bash
cd /var/www/chronodesk; export D1=$(cat /var/backups/chronodesk/deploy1-commit); read -rsp 'Senha root do MySQL: ' P; echo; export P; git cat-file -e "$D1:migrations/20261013_014_employee_archive.sql" && bash -o pipefail -c 'git show "$D1:migrations/20261013_014_employee_archive.sql" | docker exec -i -e MYSQL_PWD="$P" Chrono_Desk_DB mysql -uroot sistema_pausas' && echo "014 aplicada" || echo "PARE: a 014 nao foi aplicada"; unset P
```

- [ ] **73.** (4g) Esperado: `colunas_014 = 2`, `check_014 = 1`, `copia_rollback = 0`, `arquivados_f4 = 0`, `inativos_sem_data` = `inativos` do comando 71, `ativos_com_data = 0`, `arquivados_sem_autor = 0`, efeitos pendentes iguais aos do comando 71. **PARE se:** saida diferente do esperado.

```bash
read -rsp 'Senha root do MySQL: ' P; echo; docker exec -i -e MYSQL_PWD="$P" Chrono_Desk_DB mysql -uroot --table sistema_pausas < <(cat "$E/migrations/verificacao/20261013_014_employee_archive_check.sql"); unset P
```

- [ ] **74.** (4h, antes da 019) Esperado: como no comando 13 (`inconsistente = 0`). **PARE se:** `inconsistente = 1`: nao registre o corte, nao altere linha, avise o responsavel.

```bash
read -rsp 'Senha root do MySQL: ' P; echo; docker exec -i -e MYSQL_PWD="$P" Chrono_Desk_DB mysql -uroot --table sistema_pausas < <(cat "$E/migrations/verificacao/20261013_019_presence_cutoff_check.sql"); unset P
```

- [ ] **75.** (4h) Esperado: `019 aplicada`. **PARE se:** `PARE: a 019 nao foi aplicada`.

```bash
cd /var/www/chronodesk; export D1=$(cat /var/backups/chronodesk/deploy1-commit); read -rsp 'Senha root do MySQL: ' P; echo; export P; git cat-file -e "$D1:migrations/20261013_019_presence_cutoff.sql" && bash -o pipefail -c 'git show "$D1:migrations/20261013_019_presence_cutoff.sql" | docker exec -i -e MYSQL_PWD="$P" Chrono_Desk_DB mysql -uroot sistema_pausas' && echo "019 aplicada" || echo "PARE: a 019 nao foi aplicada"; unset P
```

- [ ] **76.** (4h) Esperado: `tabela_019 = 1`, `checks_019 = 2`, `gatilhos_019 = 2`, `copia_rollback = 0`, `procedimentos_soltos = 0`, `cortes = 0`, `sem_autor = 0`, `eventos_registro = 0`, `eventos_remocao = 0`, `inconsistente = 0`. **PARE se:** saida diferente do esperado.

```bash
read -rsp 'Senha root do MySQL: ' P; echo; docker exec -i -e MYSQL_PWD="$P" Chrono_Desk_DB mysql -uroot --table sistema_pausas < <(cat "$E/migrations/verificacao/20261013_019_presence_cutoff_check.sql"); unset P
```

- [ ] **77.** (4i, Q4) Esperado: contagens por tipo (anote; o comando 108 compara) e `ausencias = 0`. **PARE se:** `ausencias` maior que `0` (a 012 pararia): envie a contagem.

```bash
read -rsp 'Senha root do MySQL: ' P; echo; docker exec -i -e MYSQL_PWD="$P" Chrono_Desk_DB mysql -uroot --table sistema_pausas -e "SET time_zone = 'America/Sao_Paulo'; SELECT exception_type, COUNT(*) AS n, SUM(exception_date >= CURDATE()) AS futuras FROM portal_schedule_exceptions GROUP BY exception_type; SELECT COUNT(*) AS ausencias FROM portal_absences;"; unset P
```

## 5. Ensaio do codigo do commit gravado

- [ ] **78.** (5) Esperado: `/tmp/.env ausente: OK`. **PARE se:** `PARE: existe /tmp/.env`.

```bash
[ ! -e /tmp/.env ] && echo "/tmp/.env ausente: OK" || echo "PARE: existe /tmp/.env; o ensaio leria esse arquivo"
```

- [ ] **79.** (5) Esperado: `extraido em /tmp/ensaio-deploy1-qa.<sufixo>`. **PARE se:** `PARE: falha ao extrair o deploy1-commit`.

```bash
cd /var/www/chronodesk; export D1=$(cat /var/backups/chronodesk/deploy1-commit); Q=$(mktemp -d /tmp/ensaio-deploy1-qa.XXXXXX); export Q; bash -o pipefail -c 'git archive "$D1" | tar -x -C "$Q"' && chown -R www-data: "$Q" && echo "extraido em $Q" || echo "PARE: falha ao extrair o deploy1-commit"
```

- [ ] **80.** (5) Esperado: nenhuma linha antes de `fim do php -l`. **PARE se:** qualquer linha antes de `fim do php -l`.

```bash
find "$Q" -name '*.php' -not -path '*/node_modules/*' -exec runuser -u www-data -- php -l {} \; | grep -v '^No syntax errors' ; echo "fim do php -l"
```

- [ ] **81.** (5) Esperado: os 16 QAs com `OK`. **PARE se:** algum `FALHOU`: antes do comando 82, envie so o nome do QA e a ultima linha dele (comando no passo 5 do roteiro).

```bash
[ ! -e /tmp/.env ] && for q in qa-smoke qa-auth qa-timezone qa-overtime qa-schedule-history qa-schedule-legacy-output qa-presence-engine qa-absences qa-calendar-text qa-archive qa-presence-cutoff qa-pa-conflicts qa-operational-view qa-employee-lunch qa-security qa-deploy-runbook; do printf '%s: ' "$q"; runuser -u www-data -- php "$Q/scripts/$q.php" >/dev/null 2>&1 && echo OK || echo FALHOU; done
```

- [ ] **82.** (5) Esperado: `ensaio removido`. **PARE se:** `PARE: ... fora do padrao, nada apagado`.

```bash
case "$Q" in /tmp/ensaio-deploy1-qa.*) rm -r "$Q" && echo "ensaio removido";; *) echo "PARE: $Q fora do padrao, nada apagado";; esac; unset Q
```

## 6. Q-F6, Q-F7, base, manutencao, 012 com o `merge`, corte do BIZ-02, 016b, build, recarga, validacao e reabertura

- [ ] **83.** (6a, Q-F6) Esperado: `vinculos_de_inativos = 1` e `vinculos_fora_do_mapa = 0`; os demais como no comando 9. `pas_compartilhados_hoje` maior que o do aviso: avise a gestao (nao bloqueia). **PARE se:** saida diferente do esperado (nada do codigo mudou).

```bash
read -rsp 'Senha root do MySQL: ' P; echo; docker exec -i -e MYSQL_PWD="$P" Chrono_Desk_DB mysql -uroot --table sistema_pausas -e "SET time_zone = 'America/Sao_Paulo'; SELECT (SELECT COUNT(*) FROM portal_pa_inventory WHERE active = 1) AS pas_inventario, (SELECT COUNT(*) FROM portal_pa_assignments WHERE active = 1) AS vinculos_ativos, (SELECT COUNT(*) FROM portal_pa_assignments WHERE active = 1 AND (valid_until IS NULL OR valid_until >= CURDATE())) AS vinculos_vigentes_ou_futuros, (SELECT COUNT(*) FROM (SELECT pa_number FROM portal_pa_assignments WHERE active = 1 AND valid_from <= CURDATE() AND (valid_until IS NULL OR valid_until >= CURDATE()) GROUP BY pa_number HAVING COUNT(*) > 1) t) AS pas_compartilhados_hoje, (SELECT COUNT(*) FROM portal_pa_assignments a JOIN funcionarios f ON f.id = a.employee_id WHERE a.active = 1 AND (a.valid_until IS NULL OR a.valid_until >= CURDATE()) AND f.ativo = 0) AS vinculos_de_inativos, (SELECT COUNT(*) FROM portal_pa_assignments a JOIN funcionarios f ON f.id = a.employee_id WHERE a.active = 1 AND (a.valid_until IS NULL OR a.valid_until >= CURDATE()) AND f.fora_mapa_escala = 1) AS vinculos_fora_do_mapa, (SELECT COUNT(*) FROM portal_pa_assignments WHERE active = 1 AND team = 'lideranca' AND (valid_until IS NULL OR valid_until >= CURDATE())) AS vinculos_lideranca;"; unset P
```

- [ ] **84.** (6a, Q-F6) Esperado: como no comando 10. **PARE se:** saida diferente do esperado.

```bash
read -rsp 'Senha root do MySQL: ' P; echo; docker exec -i -e MYSQL_PWD="$P" Chrono_Desk_DB mysql -uroot --table sistema_pausas -e "SET time_zone = 'America/Sao_Paulo'; SELECT (SELECT COUNT(*) FROM portal_pa_assignments a JOIN portal_pa_assignments b ON b.employee_id = a.employee_id AND b.id > a.id AND b.active = 1 AND b.valid_from <= COALESCE(a.valid_until, '9999-12-31') AND COALESCE(b.valid_until, '9999-12-31') >= a.valid_from WHERE a.active = 1) AS sobreposicoes_mesmo_colaborador, (SELECT COUNT(*) FROM portal_pa_assignments WHERE active = 1 AND valid_until < valid_from) AS periodo_invertido, (SELECT COUNT(*) FROM portal_pa_assignments a WHERE a.active = 1 AND NOT EXISTS (SELECT 1 FROM portal_pa_inventory i WHERE i.active = 1 AND i.pa_number = a.pa_number)) AS vinculos_fora_do_inventario, (SELECT COUNT(*) FROM portal_pa_assignments a LEFT JOIN portal_schedule_rules r ON r.employee_id = a.employee_id AND r.effective_from <= CURDATE() AND (r.effective_until IS NULL OR r.effective_until >= CURDATE()) WHERE a.active = 1 AND a.valid_from <= CURDATE() AND (a.valid_until IS NULL OR a.valid_until >= CURDATE()) AND a.team <> 'lideranca' AND a.schedule_rule_type <> COALESCE(IF(r.rule_type = 'cycle', 'undefined', r.rule_type), 'undefined')) AS copia_tipo_divergente_hoje, (SELECT COUNT(*) FROM portal_pa_assignments WHERE active = 1 AND notes IS NOT NULL AND notes <> '') AS vinculos_com_observacao, (SELECT COUNT(*) FROM portal_pa_map WHERE record_status = 'active') AS legado_total, (SELECT COUNT(*) FROM portal_pa_map WHERE record_status = 'active' AND work_date >= CURDATE()) AS legado_futuro;"; unset P
```

- [ ] **85.** (6a, Q-F7) Esperado: `ativos_outras_equipes`, `jornada_invertida` e `almoco_fora_da_jornada` em `0`. `n1_n2_sem_regra_hoje` maior que o do aviso: avise a gestao (nao bloqueia). **PARE se:** um dos tres maior que `0`.

```bash
read -rsp 'Senha root do MySQL: ' P; echo; docker exec -i -e MYSQL_PWD="$P" Chrono_Desk_DB mysql -uroot --table sistema_pausas -e "SET time_zone = 'America/Sao_Paulo'; SELECT (SELECT COUNT(*) FROM funcionarios WHERE ativo = 1 AND LOWER(TRIM(equipe)) = 'n1') AS ativos_n1, (SELECT COUNT(*) FROM funcionarios WHERE ativo = 1 AND LOWER(TRIM(equipe)) = 'n2') AS ativos_n2, (SELECT COUNT(*) FROM funcionarios WHERE ativo = 1 AND LOWER(TRIM(equipe)) IN ('lideranca', 'liderança', 'na', 'não se aplica', 'nao se aplica', 'nao_se_aplica', 'sem_equipe')) AS ativos_lideranca, (SELECT COUNT(*) FROM funcionarios WHERE ativo = 1 AND LOWER(TRIM(equipe)) NOT IN ('n1', 'n2', 'lideranca', 'liderança', 'na', 'não se aplica', 'nao se aplica', 'nao_se_aplica', 'sem_equipe')) AS ativos_outras_equipes, (SELECT COUNT(*) FROM funcionarios WHERE ativo = 1 AND fora_mapa_escala = 1) AS fora_do_mapa, (SELECT COUNT(*) FROM funcionarios f WHERE f.ativo = 1 AND f.fora_mapa_escala = 0 AND LOWER(TRIM(f.equipe)) IN ('n1', 'n2') AND NOT EXISTS (SELECT 1 FROM portal_schedule_rules r WHERE r.employee_id = f.id AND r.rule_type <> 'undefined' AND r.effective_from <= CURDATE() AND (r.effective_until IS NULL OR r.effective_until >= CURDATE()))) AS n1_n2_sem_regra_hoje, (SELECT COUNT(*) FROM funcionarios WHERE ativo = 1 AND jornada_entrada >= jornada_saida) AS jornada_invertida, (SELECT COUNT(*) FROM funcionarios WHERE ativo = 1 AND NOT (jornada_entrada <= almoco_inicio AND almoco_inicio < almoco_fim AND almoco_fim <= jornada_saida)) AS almoco_fora_da_jornada, (SELECT COUNT(*) FROM portal_absences WHERE status = 'approved' AND starts_on <= CURDATE() AND ends_on >= CURDATE()) AS ausencias_aprovadas_hoje, (SELECT COUNT(*) FROM portal_schedule_exceptions WHERE exception_date = CURDATE() AND exception_type IN ('day_off', 'absence', 'vacation', 'leave')) AS excecoes_de_ausencia_hoje;"; unset P
```

- [ ] **86.** (6a, rotulo) Esperado: `rotulo_que_o_php_nao_reconhece = 0`. **PARE se:** maior que `0`.

```bash
read -rsp 'Senha root do MySQL: ' P; echo; docker exec -i -e MYSQL_PWD="$P" Chrono_Desk_DB mysql -uroot --table sistema_pausas -e "SELECT COUNT(*) AS rotulo_que_o_php_nao_reconhece FROM funcionarios WHERE ativo = 1 AND (CAST(LOWER(TRIM(equipe)) AS BINARY) NOT IN ('n1', 'n2', 'lideranca', 'liderança', 'na', 'não se aplica', 'nao se aplica', 'nao_se_aplica', 'sem_equipe') OR LOCATE(CAST('Ç' AS BINARY), CAST(TRIM(equipe) AS BINARY)) > 0 OR LOCATE(CAST('Ã' AS BINARY), CAST(TRIM(equipe) AS BINARY)) > 0);"; unset P
```

- [ ] **87.** (6a, base) Esperado: p50 e p95 do `status.php` (anote: base do comando 112). **PARE se:** nada (so anotar).

```bash
cd /var/www/chronodesk; D1=$(cat /var/backups/chronodesk/deploy1-commit); git cat-file -e "$D1:scripts/medir-polling.sh" && bash <(git show "$D1:scripts/medir-polling.sh")
```

- [ ] **88.** (6a, base) Esperado: p50 e p95 do `dashboard.php` (anote: base do comando 113). **PARE se:** nada (so anotar).

```bash
git cat-file -e "$D1:scripts/medir-polling.sh" && URL=http://127.0.0.1/api/portal/dashboard.php bash <(git show "$D1:scripts/medir-polling.sh"); unset D1
```

- [ ] **89.** (6b, manutencao) Esperado: `arquivo de manutencao: presente` (so passa a valer no `merge`). **PARE se:** `AUSENTE`.

```bash
touch /var/www/chronodesk.maintenance; echo "arquivo de manutencao: $(test -e /var/www/chronodesk.maintenance && echo presente || echo AUSENTE)"
```

- [ ] **90.** (6b) Esperado: a 012 aplicada, o `.htaccess` local descartado e o `merge` em fast-forward, sem erro. **PARE se:** `migration_012 abortada` ou erro do `cat-file`/`git show` (nada mais rodou: tire o arquivo de manutencao e envie a contagem de `portal_absences`); a 012 terminou e o `merge` falhou: copia do `.htaccess` de volta e "Se a verificacao falhar" do roteiro (sem `fetch`, sem `pull`).

```bash
cd /var/www/chronodesk; export D1=$(cat /var/backups/chronodesk/deploy1-commit); read -rsp 'Senha root do MySQL: ' P; echo; export P; git cat-file -e "$D1:migrations/20261007_012_absences.sql" && bash -o pipefail -c 'git show "$D1:migrations/20261007_012_absences.sql" | docker exec -i -e MYSQL_PWD="$P" Chrono_Desk_DB mysql -uroot sistema_pausas' && git checkout -- .htaccess && git merge --ff-only "$D1"; unset P
```

- [ ] **91.** (6b) Esperado: `HEAD = deploy1-commit` e `alteracoes locais: 0`. **PARE se:** `PARE: HEAD diferente do deploy1-commit`.

```bash
[ "$(git rev-parse HEAD)" = "$(cat /var/backups/chronodesk/deploy1-commit)" ] && echo "HEAD = deploy1-commit" || echo "PARE: HEAD diferente do deploy1-commit"; echo "alteracoes locais: $(git status --porcelain | wc -l)"
```

- [ ] **92.** (6b) Esperado: `portal visto de fora (manutencao): 503` (sem `503`: confira de uma estacao que aparece "ChronoDesk temporariamente indisponivel."). **PARE se:** a estacao ve a tela do portal (a manutencao nao vale).

```bash
curl -s -o /dev/null -w 'portal visto de fora (manutencao): %{http_code}\n' "<URL>/"
```

- [ ] **93.** (6b, espera e backup em manutencao) Esperado: depois de 10 s de espera, `backup em manutencao concluido: /var/backups/chronodesk/deploy1-manutencao-<data> (<tamanho>)` (anote: ponto de restauracao com o portal parado, A2). **PARE se:** `PARE: backup em manutencao falhou`: fique em manutencao, sem o comando 94 nem a 016b, e avise o responsavel.

```bash
B2=/var/backups/chronodesk/deploy1-manutencao-$(date +%Y%m%d-%H%M%S); export B2; read -rsp 'Senha root do MySQL: ' P; echo; export P; echo "aguardando 10 s (requisicoes em andamento terminam)"; sleep 10; install -d -o root -g root -m 700 "$B2" && bash -o pipefail -c 'docker exec -i -e MYSQL_PWD="$P" Chrono_Desk_DB mysqldump -uroot --single-transaction --routines --triggers --no-tablespaces --set-gtid-purged=OFF sistema_pausas | gzip | tee "$B2/database.sql.gz" > /dev/null' && gzip -t "$B2/database.sql.gz" && tar -czf "$B2/estado.tar.gz" -C /var/www/chronodesk --ignore-failed-read estado.json pausas.csv config_sistema.json funcionarios.json && sh -c 'cd "$1" && sha256sum database.sql.gz estado.tar.gz > SHA256SUMS' _ "$B2" && echo "backup em manutencao concluido: $B2 ($(du -sh "$B2" | cut -f1))" || echo "PARE: backup em manutencao falhou (o corte do BIZ-02 e a 016b nao rodam)"; unset B2; unset P
```

- [ ] **94.** (6c, corte do BIZ-02) Esperado: `CORTE REGISTRADO`, `g1_linhas_formato_novo = 0`, `g2_saltos_auditoria = 0`, `g3_minutos_fora_de_utc = 0`, contagem por coluna proxima da do comando 23. **PARE se:** `ABORTADO PELAS GUARDAS`: fique em manutencao, sem 016b nem recarga, e avise o responsavel.

```bash
cd /var/www/chronodesk; read -rsp 'Senha root do MySQL: ' P; echo; docker exec -i -e MYSQL_PWD="$P" Chrono_Desk_DB mysql -uroot --table sistema_pausas < migrations/correcao_dados/biz02_01_registrar_corte.sql; unset P
```

- [ ] **95.** (6c) Esperado: o `.htaccess`; em `migrations/`, os arquivos da 011, 012, 014, 015, 016, 016b, 017 e 019, do ciclo do F2B e de `correcao_dados/`; o `scripts/backup-chronodesk.sh` novo. **PARE se:** saida diferente do esperado.

```bash
git diff --stat "$(cat /var/backups/chronodesk/pre-deploy-commit)" HEAD -- migrations/ scripts/backup-chronodesk.sh .htaccess
```

- [ ] **96.** (6c, verificacao da 012) Esperado: `colunas_012 = 10`, `absence_type = 0`, `status_tipo = enum('approved','cancelled')`, `fk = 1`, `indice = 1`, `checks = 2`, `copia_rollback = 0`, `linhas = 0`; na 2a consulta, tudo `0`. **PARE se:** `orfas`, `periodo_invalido` ou `sobreposicoes_aprovadas` maior que `0` (nao apague nem altere linha; incidente) ou saida diferente do esperado.

```bash
read -rsp 'Senha root do MySQL: ' P; echo; docker exec -i -e MYSQL_PWD="$P" Chrono_Desk_DB mysql -uroot --table sistema_pausas < migrations/verificacao/20261007_012_absences_check.sql; unset P
```

- [ ] **97.** (6d) Esperado: `016b aplicada`. **PARE se:** `PARE: a 016b nao foi aplicada`.

```bash
cd /var/www/chronodesk; export D1=$(cat /var/backups/chronodesk/deploy1-commit); read -rsp 'Senha root do MySQL: ' P; echo; export P; git cat-file -e "$D1:migrations/20261002_016b_lideranca_profile_data.sql" && bash -o pipefail -c 'git show "$D1:migrations/20261002_016b_lideranca_profile_data.sql" | docker exec -i -e MYSQL_PWD="$P" Chrono_Desk_DB mysql -uroot sistema_pausas' && echo "016b aplicada" || echo "PARE: a 016b nao foi aplicada"; unset P
```

- [ ] **98.** (6d) Esperado: contagens por equipe, perfil e situacao iguais as do comando 29 (ensaio da 016b, na vespera; um cadastro mudado desde 09/11 explica diferenca, anotada); um evento de cada tipo; `funcionarios_perfil_016b` com as linhas de cada passo. **PARE se:** saida diferente do esperado (reversao: degrau 2, comando 128).

```bash
read -rsp 'Senha root do MySQL: ' P; echo; docker exec -i -e MYSQL_PWD="$P" Chrono_Desk_DB mysql -uroot --table sistema_pausas -e "SELECT equipe, access_role, ativo, COUNT(*) AS cadastros FROM funcionarios GROUP BY equipe, access_role, ativo ORDER BY equipe, access_role, ativo; SELECT action, COUNT(*) AS eventos FROM audit_log WHERE action IN ('LIDERANCA_PERFIL_MIGRADO', 'ADMIN_INATIVO_REBAIXADO') GROUP BY action; SELECT passo, COUNT(*) AS registrados FROM funcionarios_perfil_016b GROUP BY passo;"; unset P
```

- [ ] **99.** (6e) Esperado: build sem erro. **PARE se:** falha por falta de espaco (`ENOSPC`) ou de rede (`ETIMEDOUT`, `ECONNRESET`, `ECONNREFUSED`, `EAI_AGAIN`, `ENOTFOUND`, `E403`, `E404`, `E5xx`, `EINTEGRITY`): fique em manutencao, sem recarga, e avise a infraestrutura; resolvido, repita uma vez; senao, degrau 2 (comandos 126 a 132), com aprovacao (o `app/` volta da copia do comando 56, sem `npm`); outra falha no build: degrau 2, com aprovacao.

```bash
cd /var/www/chronodesk/frontend && npm ci && npm run build
```

- [ ] **100.** (6e) Esperado: nenhuma saida. **PARE se:** erro na recarga.

```bash
systemctl reload "php$(php -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;')-fpm"
```

- [ ] **101.** (6f, em manutencao) Esperado: `health: 200`. **PARE se:** diferente de `200`: degrau 2, com aprovacao.

```bash
curl -s -o /dev/null -w 'health: %{http_code}\n' -H 'Host: <ServerName do vhost>' http://127.0.0.1/api/health.php
```

- [ ] **102.** (6f) Esperado: `sessao MySQL em America/Sao_Paulo` e `QA timezone (banco real) OK`. **PARE se:** falha: sem permissao de `SET timestamp`, use a secao 8.3 do BIZ-02 (root); fuso errado: degrau 2, com aprovacao.

```bash
cd /var/www/chronodesk; runuser -u www-data -- php scripts/qa-timezone.php --db
```

- [ ] **103.** (6f, teste de fumaca) Esperado: antes, troque `<log de erros do PHP>` (comando 47) e `<ServerName do vhost>`; pede login e senha do AD do responsavel (admin), a senha sem eco. `login: 200`, perfil `admin`, os quatro `GET` com `200`, `ausencia de teste lancada: 201`, `ausencia de teste cancelada: 200`, `logout: 200`, `linhas novas no log do PHP, fora o login: 0`, `logins bem-sucedidos no log: 1` e, na ultima linha, `FUMACA OK`. **PARE se:** qualquer `PARE`: nao reabra (comando 104), fique em manutencao e envie so a saida; ausencia de teste que ficou lancada: cancele pela tela; senha errada (`401`): uma nova tentativa; outro codigo ou linha nova no log: degrau 2, com aprovacao.

```bash
cd /var/www/chronodesk; env PHPLOG='<log de erros do PHP>' bash scripts/smoke-deploy1.sh '<ServerName do vhost>'
```

- [ ] **104.** (6g, reabertura) Esperado: so com os comandos 101, 102 e 103 como esperado (`FUMACA OK`). `manutencao encerrada` e `portal visto de fora: 302`. Numa tela, uma data de atualizacao recente bate com o relogio. **PARE se:** `PARE: arquivo de manutencao ainda presente`.

```bash
[ -e /var/www/chronodesk.maintenance ] && rm -- /var/www/chronodesk.maintenance; [ -e /var/www/chronodesk.maintenance ] && echo "PARE: arquivo de manutencao ainda presente" || echo "manutencao encerrada"; curl -s -o /dev/null -w 'portal visto de fora: %{http_code}\n' "<URL>/"
```

## 7. Verificacao pos-deploy

- [ ] **105.** (7a) Esperado: `200`. **PARE se:** diferente de `200`: degrau 2, com aprovacao.

```bash
curl -s -o /dev/null -w '%{http_code}\n' -H 'Host: <ServerName do vhost>' http://127.0.0.1/api/health.php
```

- [ ] **106.** (7a) Esperado: as quatro verificacoes como nos comandos 70, 96, 73 e 76 (019 com `inconsistente = 0`), salvo `linhas = 1` na da 012 (a folga de teste do comando 103, cancelada). **PARE se:** `inconsistente = 1` (regra 16a) ou saida diferente do esperado.

```bash
cd /var/www/chronodesk; for v in 20261005_011_schedule_history_check 20261007_012_absences_check 20261013_014_employee_archive_check 20261013_019_presence_cutoff_check; do echo "== $v"; read -rsp 'Senha root do MySQL: ' P; echo; docker exec -i -e MYSQL_PWD="$P" Chrono_Desk_DB mysql -uroot --table sistema_pausas < "migrations/verificacao/$v.sql"; unset P; done
```

- [ ] **107.** (7a) Esperado: `migration_015 = 1`, `migration_016 = 1`, `migration_017 = 1`, `registrados_016b` como no comando 98, `corte_biz02 = 1`. **PARE se:** saida diferente do esperado.

```bash
read -rsp 'Senha root do MySQL: ' P; echo; docker exec -i -e MYSQL_PWD="$P" Chrono_Desk_DB mysql -uroot --table sistema_pausas -e "SELECT (SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'login_user_throttle') AS migration_015, (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'funcionarios' AND INDEX_NAME = 'uq_funcionarios_ad_login') AS migration_016, (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'portal_shift_attachments' AND COLUMN_NAME = 'team') AS migration_017, (SELECT COUNT(*) FROM funcionarios_perfil_016b) AS registrados_016b, (SELECT COUNT(*) FROM manutencao_marcadores WHERE chave = 'biz02_corte') AS corte_biz02, (SELECT COUNT(*) FROM login_user_throttle) AS logins_com_falha;"; unset P
```

- [ ] **108.** (7a, Q4 e eventos) Esperado: as mesmas contagens do comando 77; desde o corte, so os eventos das acoes de hoje (os da 016b, os do teste de fumaca do comando 103 e os dos logins de teste). **PARE se:** `LOGIN_THROTTLE_UNAVAILABLE` (todo login daria 503) ou saida diferente do esperado.

```bash
read -rsp 'Senha root do MySQL: ' P; echo; docker exec -i -e MYSQL_PWD="$P" Chrono_Desk_DB mysql -uroot --table sistema_pausas -e "SET time_zone = 'America/Sao_Paulo'; SELECT exception_type, COUNT(*) AS n, SUM(exception_date >= CURDATE()) AS futuras FROM portal_schedule_exceptions GROUP BY exception_type; SELECT action, COUNT(*) AS n FROM audit_log WHERE id > (SELECT COALESCE(MAX(linha_id), 0) FROM manutencao_biz02_datetime WHERE tabela = 'audit_log') GROUP BY action ORDER BY action;"; unset P
```

- [ ] **7b.** Checagem HTTP do `scripts/test-linux.sh` (agora na arvore), sem `MAINTENANCE_TEST`.

- [ ] **109.** (7c) Esperado: `GET 302` e `POST 410` em todas as paginas, menos `/logout.php` (`GET 410` e `POST 410`); `403` nos dois JS. **PARE se:** `GET 200` numa pagina legada (`AllowOverride`; o rollback e o `.htaccess` anterior).

```bash
for p in / /index.php /login.php /admin_login.php /admin_logout.php /admin.php /metricas.php /logout.php; do echo "$p GET $(curl -k -s -o /dev/null -w '%{http_code}' "<URL>$p") POST $(curl -k -s -o /dev/null -w '%{http_code}' -X POST "<URL>$p")"; done; for j in admin.js admin_users.js; do echo "$j $(curl -k -s -o /dev/null -w '%{http_code}' "<URL>/static/js/$j")"; done
```

- [ ] **7d.** Repita os comandos 83 e 84: os mesmos numeros (diferencas batem com os `PA_MAP_*` do comando 108).
- [ ] **7e.** Repita os comandos 85 e 86: os mesmos numeros.

- [ ] **110.** (7f, D5) Esperado: so com os numeros do comando 71 iguais a referencia. So o ID interno; depois, como admin, Funcionarios > "Arquivados" > "Arquivar (regularizar)" na linha com esse ID: "Cadastro inativo regularizado e arquivado. 1 vinculo de PA encerrado." (ou "removido"). **PARE se:** numeros do comando 71 diferentes da referencia: avise antes de arquivar.

```bash
read -rsp 'Senha root do MySQL: ' P; echo; docker exec -i -e MYSQL_PWD="$P" Chrono_Desk_DB mysql -uroot --table sistema_pausas -e "SET time_zone = 'America/Sao_Paulo'; SELECT DISTINCT a.employee_id AS id_cadastro FROM portal_pa_assignments a JOIN funcionarios f ON f.id = a.employee_id AND f.ativo = 0 WHERE a.active = 1 AND (a.valid_until IS NULL OR a.valid_until >= CURDATE());"; unset P
```

- [ ] **111.** (7f) Esperado: `inativos_com_pa = 0`, `arquivados_f4 = 1`, `inativos_sem_data = 4`, `ativos_com_data = 0`, `arquivados_sem_autor = 0`. **PARE se:** `ativos_com_data` ou `arquivados_sem_autor` maior que `0` (incidente) ou saida diferente do esperado.

```bash
read -rsp 'Senha root do MySQL: ' P; echo; docker exec -i -e MYSQL_PWD="$P" Chrono_Desk_DB mysql -uroot --table sistema_pausas < /var/www/chronodesk/migrations/verificacao/20261013_014_employee_archive_check.sql; unset P
```

- [ ] **7g.** Repita o comando 83: `vinculos_de_inativos = 0` e `vinculos_fora_do_mapa = 0`. **PARE se:** diferente.
- [ ] **7h.** O responsavel marca pela tela os cadastros fora do Mapa (so admin) e repete o comando 85 (`fora_do_mapa` cresce na mesma conta).
- [ ] **7i.** Navegador, por perfil (login de cada perfil e revalidacao com usuario de teste; admin, com funcionario sem login AD; Lideranca, com anexo de escala para N1; gestor, com horas extras e CSV; tecnico; 1280 px e 390 px): lista do roteiro, 7i. Depois, o comando 108 de novo (`ADMIN_ROLE_SEM_ALLOWLIST`, `SESSION_REVOKED`). Anote so numeros.

- [ ] **112.** (7j) Esperado: p50 e p95 do `status.php` (anote na 9.9). **PARE se:** p95 mais de 50 ms **e** mais de 20% acima do comando 87: degrau 1 (comandos 120 a 123) e medir de novo.

```bash
cd /var/www/chronodesk; bash scripts/medir-polling.sh
```

- [ ] **113.** (7j) Esperado: p50 e p95 do `dashboard.php` (anote na 9.9). **PARE se:** p95 mais de 50 ms **e** mais de 20% acima do comando 88: degrau 1 e medir de novo.

```bash
cd /var/www/chronodesk; URL=http://127.0.0.1/api/portal/dashboard.php bash scripts/medir-polling.sh
```

- [ ] **114.** (7k) Esperado: `corte planejado` igual ao do comando 15 (diferente: o deploy passou do dia 16; a data nova vale, avise a gestao). **PARE se:** `PARE: data do corte invalida`.

```bash
read -rsp 'Senha root do MySQL: ' P; echo; C=$(docker exec -i -e MYSQL_PWD="$P" Chrono_Desk_DB mysql -uroot -N sistema_pausas -e "SET time_zone = 'America/Sao_Paulo'; SELECT IF(DAYOFMONTH(CURDATE()) >= 16, DATE_FORMAT(CURDATE() + INTERVAL 1 MONTH, '%Y-%m-16'), DATE_FORMAT(CURDATE(), '%Y-%m-16'));"); unset P; [[ "$C" =~ ^[0-9]{4}-[0-9]{2}-16$ ]] && echo "corte planejado: $C" || echo "PARE: data do corte invalida ($C)"
```

- [ ] **115.** (7k) Esperado: `sem_regra_no_corte = 0` e `sem_regra_na_competencia = 0`. **PARE se:** qualquer um maior que `0`: o comando 116 nao roda (o deploy fica no ar sem corte).

```bash
read -rsp 'Senha root do MySQL: ' P; echo; docker exec -i -e MYSQL_PWD="$P" Chrono_Desk_DB mysql -uroot --table sistema_pausas -e "SET @corte := '$C'; WITH RECURSIVE dias (d) AS (SELECT CAST(@corte AS DATE) UNION ALL SELECT d + INTERVAL 1 DAY FROM dias WHERE d < @corte + INTERVAL 1 MONTH - INTERVAL 1 DAY) SELECT COUNT(*) AS ativos_n1_n2, COALESCE(SUM(NOT EXISTS (SELECT 1 FROM portal_schedule_rules r WHERE r.employee_id = f.id AND r.rule_type <> 'undefined' AND r.effective_from <= @corte AND (r.effective_until IS NULL OR r.effective_until >= @corte))), 0) AS sem_regra_no_corte, COALESCE(SUM(EXISTS (SELECT 1 FROM dias WHERE NOT EXISTS (SELECT 1 FROM portal_schedule_rules r WHERE r.employee_id = f.id AND r.rule_type <> 'undefined' AND r.effective_from <= dias.d AND (r.effective_until IS NULL OR r.effective_until >= dias.d)))), 0) AS sem_regra_na_competencia FROM funcionarios f WHERE f.ativo = 1 AND f.fora_mapa_escala = 0 AND LOWER(f.equipe) IN ('n1', 'n2');"; unset P
```

- [ ] **116.** (7l) Esperado: so com o comando 115 em `0`, `inconsistente = 0` e o `C` do comando 114 na mesma sessao; antes, troque `<login do operador>`. `corte a registrar: <C>` e nenhuma outra mensagem. **PARE se:** `registro_019 abortado: ...` (nada gravado): confira o `C`, `DROP PROCEDURE IF EXISTS registrar_corte_019` e envie a mensagem.

```bash
cd /var/www/chronodesk; echo "corte a registrar: $C"; read -rsp 'Senha root do MySQL: ' P; echo; { printf "SET @corte := '%s'; SET @autor := '%s';\n" "$C" '<login do operador>'; cat migrations/20261013_019_registrar_corte.sql; } | docker exec -i -e MYSQL_PWD="$P" Chrono_Desk_DB mysql -uroot sistema_pausas; unset P
```

- [ ] **117.** (7l) Esperado: `cortes = 1`, `corte` = o `C`, `corte_dia_16 = 1`, `corte_no_futuro = 1`, `sem_autor = 0`, `eventos_registro = 1`, `eventos_remocao = 0`, `procedimentos_soltos = 0`, `inconsistente = 0`. **PARE se:** `inconsistente = 1` (regra 16a) ou saida diferente do esperado.

```bash
read -rsp 'Senha root do MySQL: ' P; echo; docker exec -i -e MYSQL_PWD="$P" Chrono_Desk_DB mysql -uroot --table sistema_pausas < migrations/verificacao/20261013_019_presence_cutoff_check.sql; unset P
```

- [ ] **118.** (7m) Esperado: `ensaio das migrations removido`. **PARE se:** `PARE: ... fora do padrao, nada apagado`.

```bash
case "$E" in /root/ensaio-deploy1.*) rm -r "$E" && echo "ensaio das migrations removido";; *) echo "PARE: $E fora do padrao, nada apagado";; esac; unset E D1
```

- [ ] **119.** (7m, copia de ensaio) Esperado: `copia_restante = 0` (apagada na vespera, comando 42). **PARE se:** `copia_restante = 1`: apague-a com o comando 42 (nome digitado) e registre o atraso.

```bash
read -rsp 'Senha root do MySQL: ' P; echo; docker exec -i -e MYSQL_PWD="$P" Chrono_Desk_DB mysql -uroot --table -e "SELECT COUNT(*) AS copia_restante FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = 'sistema_pausas_ensaio';"; unset P
```

## 8. Desligamento rapido e rollback em degraus (so se preciso)

Do degrau mais baixo para o mais alto; os degraus 2 e 3 so com aprovacao previa do responsavel. Riscos de cada degrau: roteiro, passo 8.

- [ ] **120.** (8a, degrau 1) Esperado: nada acima de `fim (esperado: nada acima)`. **PARE se:** alguma linha (a variavel no pool do PHP-FPM venceria o `.env`).

```bash
grep -rn 'STATUS_PRESENCE_ENABLED' /etc/php/*/fpm/ ; echo "fim (esperado: nada acima)"
```

- [ ] **121.** (8a) Esperado: `copia do .env: ... (600 root)`. **PARE se:** `PARE: copia do .env falhou, nada alterado`.

```bash
K=/var/backups/chronodesk/env-antes-interruptor-$(date +%Y%m%d-%H%M%S); if install -m 600 /var/www/.env "$K"; then echo "copia do .env: $K ($(stat -c '%a %U' "$K"))"; if grep -q '^STATUS_PRESENCE_ENABLED=' /var/www/.env; then sed -i 's/^STATUS_PRESENCE_ENABLED=.*/STATUS_PRESENCE_ENABLED=false/' /var/www/.env; else echo 'STATUS_PRESENCE_ENABLED=false' | tee -a /var/www/.env >/dev/null; fi; else echo "PARE: copia do .env falhou, nada alterado"; fi; unset K
```

- [ ] **122.** (8a) Esperado: `STATUS_PRESENCE_ENABLED: OK (desligado)`. **PARE se:** saida diferente do esperado.

```bash
cd /var/www/chronodesk && sh scripts/preflight-deploy.sh /var/www/.env | grep STATUS_PRESENCE_ENABLED
```

- [ ] **123.** (8a) Esperado: nenhuma saida. Para religar: os comandos 121 a 123 com `true` (roteiro, 8a). **PARE se:** erro na recarga.

```bash
systemctl reload "php$(php -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;')-fpm"
```

- [ ] **124.** (8a, limpeza das copias) Esperado: so com aprovacao do responsavel. As copias, modo `600`, dono `root` (so nome, modo e dono). **PARE se:** saida diferente do esperado.

```bash
find /var/backups/chronodesk -maxdepth 1 -type f \( -name 'env-antes-interruptor-*' -o -name 'env-antes-app-env-*' \) -printf '%f %m %u\n'
```

- [ ] **125.** (8a, limpeza das copias) Esperado: as mesmas copias ao apagar; o comando 124 de novo nao mostra nada. **PARE se:** saida diferente do esperado.

```bash
find /var/backups/chronodesk -maxdepth 1 -type f \( -name 'env-antes-interruptor-*' -o -name 'env-antes-app-env-*' \) -print -delete
```

- [ ] **126.** (8b, degrau 2) Esperado: so com aprovacao previa do responsavel. `regras_cycle` (anote; maior que `0`: rode o 127 antes do 129). **PARE se:** saida diferente do esperado.

```bash
cd /var/www/chronodesk; export D1=$(cat /var/backups/chronodesk/deploy1-commit); read -rsp 'Senha root do MySQL: ' P; echo; export P; git cat-file -e "$D1:migrations/verificacao/20261007_f2b_cycle_check.sql" && bash -o pipefail -c 'git show "$D1:migrations/verificacao/20261007_f2b_cycle_check.sql" | docker exec -i -e MYSQL_PWD="$P" Chrono_Desk_DB mysql -uroot --table sistema_pausas'; unset P
```

- [ ] **127.** (8b) Esperado: so com `regras_cycle` maior que `0`, com o codigo novo no ar. `ciclo trocado por undefined`. **PARE se:** sem a mensagem.

```bash
cd /var/www/chronodesk; export D1=$(cat /var/backups/chronodesk/deploy1-commit); read -rsp 'Senha root do MySQL: ' P; echo; export P; git cat-file -e "$D1:migrations/rollback/20261007_f2b_cycle_down.sql" && bash -o pipefail -c 'git show "$D1:migrations/rollback/20261007_f2b_cycle_down.sql" | docker exec -i -e MYSQL_PWD="$P" Chrono_Desk_DB mysql -uroot sistema_pausas' && echo "ciclo trocado por undefined"; unset P
```

- [ ] **128.** (8b) Esperado: com o codigo novo no ar. `perfis da 016b revertidos`. **PARE se:** sem a mensagem.

```bash
cd /var/www/chronodesk; export D1=$(cat /var/backups/chronodesk/deploy1-commit); read -rsp 'Senha root do MySQL: ' P; echo; export P; git cat-file -e "$D1:migrations/rollback/20261002_016b_lideranca_profile_data_down.sql" && bash -o pipefail -c 'git show "$D1:migrations/rollback/20261002_016b_lideranca_profile_data_down.sql" | docker exec -i -e MYSQL_PWD="$P" Chrono_Desk_DB mysql -uroot sistema_pausas' && echo "perfis da 016b revertidos"; unset P
```

- [ ] **129.** (8b) Esperado: `codigo anterior no ar: 596db67; .htaccess local de volta: 6 0`. **PARE se:** sem a mensagem, ou outra contagem do `.htaccess`.

```bash
cd /var/www/chronodesk; git checkout --detach "$(cat /var/backups/chronodesk/pre-deploy-commit)" && cp -p /var/backups/chronodesk/htaccess-local-antes-deploy1 .htaccess && echo "codigo anterior no ar: $(git rev-parse --short HEAD); .htaccess local de volta: $(git diff --numstat -- .htaccess | cut -f1,2)"
```

- [ ] **130.** (8b, `app/`) Esperado: `app/ anterior de volta (o build retirado esta em ...)` (a copia do comando 56, sem `npm`; nada e apagado). **PARE se:** `PARE: a volta do app/ falhou` ou `PARE: copia ... ausente`: fique em manutencao e avise.

```bash
cd /var/www/chronodesk; A=/var/backups/chronodesk/app-antes-deploy1; X=/var/backups/chronodesk/app-deploy1-retirado-$(date +%Y%m%d-%H%M%S); if test -d "$A"; then { ! test -e app || mv app "$X"; } && cp -a "$A" app && diff -rq "$A" app && echo "app/ anterior de volta (o build retirado esta em $X)" || echo "PARE: a volta do app/ falhou"; else echo "PARE: copia $A ausente; nada mudou no app/"; fi; unset A X
```

- [ ] **131.** (8b) Esperado: nenhuma saida. Arquivo de manutencao ainda presente: tire-o como no comando 104. **PARE se:** erro na recarga.

```bash
systemctl reload "php$(php -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;')-fpm"
```

- [ ] **132.** (8b, BIZ-02) Esperado: `marcadores_biz02 = 0` (so sem a correcao historica, A3). **PARE se:** `PARE: biz02_correcao = 1` (nada apagado: o script 4 do BIZ-02 vem antes, com o responsavel) ou saida diferente do esperado.

```bash
read -rsp 'Senha root do MySQL: ' P; echo; X=$(docker exec -i -e MYSQL_PWD="$P" Chrono_Desk_DB mysql -uroot -N sistema_pausas -e "SELECT COUNT(*) FROM manutencao_marcadores WHERE chave = 'biz02_correcao';"); if [ "$X" = "0" ]; then docker exec -i -e MYSQL_PWD="$P" Chrono_Desk_DB mysql -uroot --table sistema_pausas -e "DROP TABLE IF EXISTS manutencao_biz02_datetime; DELETE FROM manutencao_marcadores WHERE chave LIKE 'biz02%'; SELECT COUNT(*) AS marcadores_biz02 FROM manutencao_marcadores WHERE chave LIKE 'biz02%';"; else echo "PARE: biz02_correcao = ${X:-erro na consulta}; a correcao historica ja rodou: o script 4 do BIZ-02 vem antes, com o responsavel; nada apagado"; fi; unset X; unset P
```

- [ ] **133.** (8c, degrau 3) Esperado: so com aprovacao e depois do degrau 2. `rollback da 019 aplicado`. **PARE se:** sem a mensagem (nao rodou; copia `*_bkp_019` ja existente: renomear e repetir).

```bash
cd /var/www/chronodesk; export D1=$(cat /var/backups/chronodesk/deploy1-commit); read -rsp 'Senha root do MySQL: ' P; echo; export P; git cat-file -e "$D1:migrations/rollback/20261013_019_presence_cutoff_down.sql" && bash -o pipefail -c 'git show "$D1:migrations/rollback/20261013_019_presence_cutoff_down.sql" | docker exec -i -e MYSQL_PWD="$P" Chrono_Desk_DB mysql -uroot sistema_pausas' && echo "rollback da 019 aplicado"; unset P
```

- [ ] **134.** (8c) Esperado: `rollback da 014 aplicado`. **PARE se:** sem a mensagem (copia `*_bkp_014` ja existente: renomear e repetir).

```bash
cd /var/www/chronodesk; export D1=$(cat /var/backups/chronodesk/deploy1-commit); read -rsp 'Senha root do MySQL: ' P; echo; export P; git cat-file -e "$D1:migrations/rollback/20261013_014_employee_archive_down.sql" && bash -o pipefail -c 'git show "$D1:migrations/rollback/20261013_014_employee_archive_down.sql" | docker exec -i -e MYSQL_PWD="$P" Chrono_Desk_DB mysql -uroot sistema_pausas' && echo "rollback da 014 aplicado"; unset P
```

- [ ] **135.** (8c) Esperado: so sem ausencia alem da folga de teste do comando 103, cancelada (`linhas = 1` na verificacao da 012; `0` se ela nao foi lancada). `rollback da 012 aplicado`. **PARE se:** sem a mensagem (copia `*_bkp_012` ja existente: renomear e repetir).

```bash
cd /var/www/chronodesk; export D1=$(cat /var/backups/chronodesk/deploy1-commit); read -rsp 'Senha root do MySQL: ' P; echo; export P; git cat-file -e "$D1:migrations/rollback/20261007_012_absences_down.sql" && bash -o pipefail -c 'git show "$D1:migrations/rollback/20261007_012_absences_down.sql" | docker exec -i -e MYSQL_PWD="$P" Chrono_Desk_DB mysql -uroot sistema_pausas' && echo "rollback da 012 aplicado"; unset P
```

- [ ] **136.** (8c) Esperado: `rollback da 011 aplicado`. **PARE se:** sem a mensagem (copia `*_bkp_011` ja existente: renomear e repetir).

```bash
cd /var/www/chronodesk; export D1=$(cat /var/backups/chronodesk/deploy1-commit); read -rsp 'Senha root do MySQL: ' P; echo; export P; git cat-file -e "$D1:migrations/rollback/20261005_011_schedule_history_down.sql" && bash -o pipefail -c 'git show "$D1:migrations/rollback/20261005_011_schedule_history_down.sql" | docker exec -i -e MYSQL_PWD="$P" Chrono_Desk_DB mysql -uroot sistema_pausas' && echo "rollback da 011 aplicado"; unset P
```

- [ ] **137.** (8c) Esperado: `rollback da 017 aplicado`. **PARE se:** sem a mensagem.

```bash
cd /var/www/chronodesk; export D1=$(cat /var/backups/chronodesk/deploy1-commit); read -rsp 'Senha root do MySQL: ' P; echo; export P; git cat-file -e "$D1:migrations/rollback/20261002_017_shift_attachment_team_down.sql" && bash -o pipefail -c 'git show "$D1:migrations/rollback/20261002_017_shift_attachment_team_down.sql" | docker exec -i -e MYSQL_PWD="$P" Chrono_Desk_DB mysql -uroot sistema_pausas' && echo "rollback da 017 aplicado"; unset P
```

- [ ] **138.** (8c) Esperado: `rollback da 016 aplicado`. **PARE se:** sem a mensagem (`rollback_016_abortado_perfil_lideranca_em_uso`: nada alterado; ha cadastro `lideranca`).

```bash
cd /var/www/chronodesk; export D1=$(cat /var/backups/chronodesk/deploy1-commit); read -rsp 'Senha root do MySQL: ' P; echo; export P; git cat-file -e "$D1:migrations/rollback/20261002_016_lideranca_profile_down.sql" && bash -o pipefail -c 'git show "$D1:migrations/rollback/20261002_016_lideranca_profile_down.sql" | docker exec -i -e MYSQL_PWD="$P" Chrono_Desk_DB mysql -uroot sistema_pausas' && echo "rollback da 016 aplicado"; unset P
```

- [ ] **139.** (8c) Esperado: `rollback da 015 aplicado`. **PARE se:** sem a mensagem.

```bash
cd /var/www/chronodesk; export D1=$(cat /var/backups/chronodesk/deploy1-commit); read -rsp 'Senha root do MySQL: ' P; echo; export P; git cat-file -e "$D1:migrations/rollback/20261001_015_login_user_throttle_down.sql" && bash -o pipefail -c 'git show "$D1:migrations/rollback/20261001_015_login_user_throttle_down.sql" | docker exec -i -e MYSQL_PWD="$P" Chrono_Desk_DB mysql -uroot sistema_pausas' && echo "rollback da 015 aplicado"; unset P
```

## 9. Depois de um ciclo estavel, com o responsavel

- [ ] **140.** (9a, estacao) Esperado: push do responsavel, depois de um ciclo estavel; antes, troque `<commit validado na estacao>`. A linha `<antigo>..<novo>` com `-> codex/modernizacao-ui-backend`. **PARE se:** `rejected` ou `non-fast-forward`: nunca `--force`; o responsavel decide.

```powershell
git push origin <commit validado na estacao>:codex/modernizacao-ui-backend
```

- [ ] **9b.** Contagem dos logs de novo (comando 19): so `302`, `410` e `403` desde o deploy. A remocao das paginas legadas e do responsavel, no repositorio, num commit proprio (roteiro, 9b).
- [ ] **9c.** Rotina de backup instalada, teste de restauracao e so entao a correcao historica do BIZ-02 (roteiro, 9c).
- [ ] **9d.** Pendencia LGPD das copias de junho: decisao do responsavel (inventario, secao 8).
- [ ] **9e.** Limpeza com aprovacao: backup do dia e o em manutencao (comando 93), copia do `app/` e, se o degrau 2 rodou, o build retirado, backup pre-dia de 09/11 (`/root/backups_chronodesk/`), copia do `.htaccess` local, copias do `.env`, `funcionarios_perfil_016b` (roteiro, 9e).
