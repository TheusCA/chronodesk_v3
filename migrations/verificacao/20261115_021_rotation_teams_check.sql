-- Verificacao da migration 021 (times de rodizio e grupo da escala, F10-2).
-- So contagens e assinaturas, nenhum nome nem login. Rodar depois da 021, no
-- pos-deploy e sempre que quiser conferir:
--   docker exec -i -e MYSQL_PWD="$P" Chrono_Desk_DB mysql -uroot --table sistema_pausas \
--     < migrations/verificacao/20261115_021_rotation_teams_check.sql
-- Tambem serve antes da 021 e depois do rollback: as contagens dos times so
-- rodam com as tres tabelas presentes (senao, "021 ausente").
--
-- Esperado com a 021: tabelas_021 = 3, colunas_021 = 2, restricoes_021 = 9;
-- pessoas_com_mais_de_uma_aberta = 0, pertencas_sobrepostas = 0,
-- membros_abertos_sem_regra_aberta_do_time = 0 e regras_de_time_sem_membro = 0
-- (o time grava a regra na mesma transacao). Logo depois da migration, tudo
-- dos times em 0 e grupo 'comum' para todos.
-- As assinaturas (no fim) cobrem so colunas anteriores a 021: nao mudam com a
-- migration nem com o rollback se nenhuma linha mudar (o CHECKSUM TABLE muda,
-- porque a coluna nova entra nele).

SELECT
    (SELECT COUNT(*) FROM information_schema.TABLES
      WHERE TABLE_SCHEMA = DATABASE()
        AND TABLE_NAME IN ('portal_rotation_teams', 'portal_rotation_team_versions', 'portal_rotation_team_members')) AS tabelas_021,
    (SELECT COUNT(*) FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE()
        AND ((TABLE_NAME = 'portal_schedule_rules' AND COLUMN_NAME = 'rotation_team_id')
          OR (TABLE_NAME = 'funcionarios' AND COLUMN_NAME = 'grupo_escala'))) AS colunas_021,
    (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
      WHERE TABLE_SCHEMA = DATABASE()
        AND CONSTRAINT_NAME IN ('fk_rotation_version_team', 'ck_rotation_version_pattern', 'ck_rotation_version_anchor',
                                'ck_rotation_version_weeks', 'fk_rotation_member_team', 'fk_rotation_member_employee',
                                'ck_rotation_member_period', 'fk_schedule_rule_rotation_team', 'ck_rotation_team_name')) AS restricoes_021,
    (SELECT COUNT(*) FROM information_schema.TABLES
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME LIKE '%\_bkp\_021') AS copias_de_rollback_021;

-- Contagens dos times: so com as tres tabelas e as duas colunas presentes.
SET @tem_021 := (
    SELECT COUNT(*) FROM information_schema.TABLES
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME IN ('portal_rotation_teams', 'portal_rotation_team_versions', 'portal_rotation_team_members')
) + (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND ((TABLE_NAME = 'portal_schedule_rules' AND COLUMN_NAME = 'rotation_team_id')
        OR (TABLE_NAME = 'funcionarios' AND COLUMN_NAME = 'grupo_escala'))
);
SET @contagens_021_sql := IF(
    @tem_021 = 5,
    'SELECT
        (SELECT COUNT(*) FROM portal_rotation_teams) AS times,
        (SELECT COUNT(*) FROM portal_rotation_teams WHERE active = 1) AS times_ativos,
        (SELECT COUNT(*) FROM portal_rotation_team_versions) AS versoes,
        (SELECT COUNT(*) FROM portal_rotation_team_members WHERE valid_until IS NULL) AS pertencas_abertas,
        (SELECT COUNT(*) FROM portal_rotation_team_members WHERE valid_until IS NOT NULL) AS pertencas_encerradas,
        (SELECT COUNT(*) FROM (
            SELECT employee_id FROM portal_rotation_team_members
            WHERE valid_until IS NULL GROUP BY employee_id HAVING COUNT(*) > 1
        ) AS abertas) AS pessoas_com_mais_de_uma_aberta,
        (SELECT COUNT(*)
           FROM portal_rotation_team_members a
           JOIN portal_rotation_team_members b
             ON b.employee_id = a.employee_id AND b.id > a.id
            AND a.valid_from <= COALESCE(b.valid_until, ''9999-12-31'')
            AND b.valid_from <= COALESCE(a.valid_until, ''9999-12-31'')) AS pertencas_sobrepostas,
        (SELECT COUNT(*)
           FROM portal_rotation_team_members m
          WHERE m.valid_until IS NULL
            AND NOT EXISTS (
                SELECT 1 FROM portal_schedule_rules r
                WHERE r.employee_id = m.employee_id AND r.effective_until IS NULL AND r.rotation_team_id = m.team_id
            )) AS membros_abertos_sem_regra_aberta_do_time,
        (SELECT COUNT(*) FROM portal_schedule_rules WHERE rotation_team_id IS NOT NULL) AS regras_de_time,
        (SELECT COUNT(*)
           FROM portal_schedule_rules r
          WHERE r.rotation_team_id IS NOT NULL
            AND NOT EXISTS (
                SELECT 1 FROM portal_rotation_team_members m
                WHERE m.employee_id = r.employee_id AND m.team_id = r.rotation_team_id
            )) AS regras_de_time_sem_membro,
        (SELECT COUNT(*)
           FROM portal_rotation_team_members m
           JOIN funcionarios f ON f.id = m.employee_id
          WHERE m.valid_until IS NULL AND f.ativo = 0) AS pertencas_abertas_de_inativo',
    'SELECT ''021 ausente'' AS situacao_021'
);
PREPARE contagens_021_stmt FROM @contagens_021_sql;
EXECUTE contagens_021_stmt;
DEALLOCATE PREPARE contagens_021_stmt;

SET @grupos_021_sql := IF(
    @tem_021 = 5,
    'SELECT grupo_escala, ativo, COUNT(*) AS funcionarios FROM funcionarios GROUP BY grupo_escala, ativo ORDER BY grupo_escala, ativo',
    'SELECT ''021 ausente'' AS situacao_021'
);
PREPARE grupos_021_stmt FROM @grupos_021_sql;
EXECUTE grupos_021_stmt;
DEALLOCATE PREPARE grupos_021_stmt;

-- Regras por tipo e situacao (a mesma da verificacao da 011)
SELECT rule_type, effective_until IS NULL AS aberta, COUNT(*) AS regras
  FROM portal_schedule_rules
 GROUP BY rule_type, aberta
 ORDER BY rule_type, aberta;

-- Assinaturas das colunas anteriores a 021 (CRC32 por linha, combinado por
-- XOR; sem nome, login nem observacao)
SELECT 'regras' AS tabela, COUNT(*) AS linhas,
       BIT_XOR(CRC32(CONCAT_WS('|', id, employee_id, team, rule_type, COALESCE(rule_config, ''),
                               effective_from, COALESCE(effective_until, '')))) AS assinatura
  FROM portal_schedule_rules
UNION ALL
SELECT 'funcionarios', COUNT(*),
       BIT_XOR(CRC32(CONCAT_WS('|', id, equipe, COALESCE(access_role, ''), ativo, fora_mapa_escala,
                               jornada_entrada, jornada_saida)))
  FROM funcionarios;
