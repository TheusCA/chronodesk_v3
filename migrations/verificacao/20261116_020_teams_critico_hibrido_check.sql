-- Verificacao da migration 020 (equipes Critico e Hibrido e limite aplicado
-- da pausa, F9). So contagens e assinaturas, nenhum nome nem login. Rodar
-- depois da 020, no pos-deploy e sempre que quiser conferir:
--   docker exec -i -e MYSQL_PWD="$P" Chrono_Desk_DB mysql -uroot --table sistema_pausas \
--     < migrations/verificacao/20261116_020_teams_critico_hibrido_check.sql
-- Tambem serve antes da 020 e depois do rollback: as contagens do limite so
-- rodam com a coluna presente (senao, "020 ausente").
--
-- Esperado com a 020: enums_com_critico_020 = 8, coluna_020 = 1,
-- enum_legado_pa_map_com_critico = 0; almoco_fora_do_critico = 0,
-- almoco_com_alerta = 0, ativos_equipe_desconhecida = 0 e
-- critico_ou_hibrido_no_grupo_comum = 0. Logo depois da migration, nenhuma
-- linha com equipe nova e pausas_com_limite = 0.
-- As assinaturas (no fim) cobrem so colunas anteriores a 020: nao mudam com a
-- migration nem com o rollback se nenhuma linha mudar.

SELECT
    (SELECT COUNT(*) FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE() AND COLUMN_NAME = 'team' AND DATA_TYPE = 'enum'
        AND TABLE_NAME IN ('portal_schedule_rules', 'portal_schedule_exceptions', 'portal_pa_assignments', 'portal_absences',
                           'portal_shift_attachments', 'portal_overtime_entries', 'portal_time_adjustments', 'portal_oncall_shifts')
        AND COLUMN_TYPE LIKE '%''critico'',''hibrido'')') AS enums_com_critico_020,
    (SELECT COUNT(*) FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'portal_pa_map' AND COLUMN_NAME = 'team'
        AND COLUMN_TYPE LIKE '%critico%') AS enum_legado_pa_map_com_critico,
    (SELECT COUNT(*) FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'pausas' AND COLUMN_NAME = 'limite_segundos') AS coluna_020,
    (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'portal_absences' AND CONSTRAINT_TYPE = 'CHECK') AS checks_ausencias,
    (SELECT COUNT(*) FROM information_schema.TABLES
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME LIKE '%\_bkp\_020') AS copias_de_rollback_020;

-- Cadastros por equipe e situacao (texto gravado, normalizado so por caixa e espaco)
SELECT LOWER(TRIM(equipe)) AS equipe, ativo, COUNT(*) AS cadastros
  FROM funcionarios
 GROUP BY LOWER(TRIM(equipe)), ativo
 ORDER BY equipe, ativo;

SELECT COUNT(*) AS ativos_equipe_desconhecida
  FROM funcionarios
 WHERE ativo = 1
   AND LOWER(TRIM(equipe)) NOT IN ('n1', 'n2', 'lideranca', 'liderança', 'critico', 'hibrido',
                                   'na', 'não se aplica', 'nao se aplica', 'nao_se_aplica', 'sem_equipe');

-- Linhas com equipe nova nas oito tabelas
SELECT 'portal_schedule_rules' AS tabela, COUNT(*) AS linhas_critico_ou_hibrido FROM portal_schedule_rules WHERE team IN ('critico', 'hibrido')
UNION ALL SELECT 'portal_schedule_exceptions', COUNT(*) FROM portal_schedule_exceptions WHERE team IN ('critico', 'hibrido')
UNION ALL SELECT 'portal_pa_assignments', COUNT(*) FROM portal_pa_assignments WHERE team IN ('critico', 'hibrido')
UNION ALL SELECT 'portal_absences', COUNT(*) FROM portal_absences WHERE team IN ('critico', 'hibrido')
UNION ALL SELECT 'portal_shift_attachments', COUNT(*) FROM portal_shift_attachments WHERE team IN ('critico', 'hibrido')
UNION ALL SELECT 'portal_overtime_entries', COUNT(*) FROM portal_overtime_entries WHERE team IN ('critico', 'hibrido')
UNION ALL SELECT 'portal_time_adjustments', COUNT(*) FROM portal_time_adjustments WHERE team IN ('critico', 'hibrido')
UNION ALL SELECT 'portal_oncall_shifts', COUNT(*) FROM portal_oncall_shifts WHERE team IN ('critico', 'hibrido');

-- Pausas: por motivo; almoco so do Critico e sem alerta de 15/20 min
SELECT motivo_pausa, COUNT(*) AS pausas FROM pausas GROUP BY motivo_pausa ORDER BY motivo_pausa;
SELECT
    (SELECT COUNT(*) FROM pausas WHERE motivo_pausa = 'Almoço' AND LOWER(TRIM(equipe)) <> 'critico') AS almoco_fora_do_critico,
    (SELECT COUNT(*) FROM pausas WHERE motivo_pausa = 'Almoço' AND (alerta_15min = 1 OR alerta_20min = 1)) AS almoco_com_alerta;

SET @tem_020 := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'pausas' AND COLUMN_NAME = 'limite_segundos'
);
SET @limite_020_sql := IF(
    @tem_020 = 1,
    'SELECT
        (SELECT COUNT(*) FROM pausas WHERE limite_segundos IS NOT NULL) AS pausas_com_limite,
        (SELECT COUNT(*) FROM pausas WHERE limite_segundos IS NOT NULL AND (motivo_pausa = ''Pessoal'' OR motivo_pausa LIKE ''Reuni%'')) AS limite_em_motivo_sem_limite,
        (SELECT COUNT(*) FROM pausas WHERE limite_segundos = 0) AS limite_zero,
        (SELECT COUNT(*) FROM pausas WHERE motivo_pausa = ''Almoço'' AND limite_segundos IS NULL) AS almoco_sem_limite',
    'SELECT ''020 ausente'' AS situacao_020'
);
PREPARE limite_020_stmt FROM @limite_020_sql;
EXECUTE limite_020_stmt;
DEALLOCATE PREPARE limite_020_stmt;

-- Grupo da escala (021): Critico e Hibrido nunca no grupo comum (D-F9-12)
SET @tem_grupo := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'funcionarios' AND COLUMN_NAME = 'grupo_escala'
);
SET @grupo_020_sql := IF(
    @tem_grupo = 1,
    'SELECT COUNT(*) AS critico_ou_hibrido_no_grupo_comum FROM funcionarios
      WHERE LOWER(TRIM(equipe)) IN (''critico'', ''hibrido'') AND grupo_escala = ''comum''',
    'SELECT ''021 ausente'' AS critico_ou_hibrido_no_grupo_comum'
);
PREPARE grupo_020_stmt FROM @grupo_020_sql;
EXECUTE grupo_020_stmt;
DEALLOCATE PREPARE grupo_020_stmt;

-- Assinaturas das colunas anteriores a 020 (CRC32 por linha, combinado por
-- XOR; sem nome, login nem observacao)
SELECT 'pausas' AS tabela, COUNT(*) AS linhas,
       BIT_XOR(CRC32(CONCAT_WS('|', id, id_funcionario, equipe, inicio_pausa, COALESCE(fim_pausa, ''),
                               COALESCE(duracao_segundos, ''), motivo_pausa, alerta_15min, alerta_20min))) AS assinatura
  FROM pausas
UNION ALL
SELECT 'ausencias', COUNT(*),
       BIT_XOR(CRC32(CONCAT_WS('|', id, employee_id, team, starts_on, ends_on, period_type, status)))
  FROM portal_absences
UNION ALL
SELECT 'regras', COUNT(*),
       BIT_XOR(CRC32(CONCAT_WS('|', id, employee_id, team, rule_type, COALESCE(rule_config, ''),
                               effective_from, COALESCE(effective_until, ''))))
  FROM portal_schedule_rules;
