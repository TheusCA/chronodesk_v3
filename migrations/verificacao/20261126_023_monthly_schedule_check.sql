-- Verificacao da migration 023 (escala do mes, F10-4). So contagens e
-- assinaturas: nenhum nome, login nem texto livre. Rodar depois da 023, no
-- pos-deploy e sempre que quiser conferir:
--   docker exec -i -e MYSQL_PWD="$P" Chrono_Desk_DB mysql -uroot --table sistema_pausas \
--     < migrations/verificacao/20261126_023_monthly_schedule_check.sql
-- Tambem serve antes da 023 e depois do rollback: as contagens novas so
-- rodam com a 023 inteira (senao, "023 ausente").
--
-- Esperado com a 023: tabelas_023 = 4, fks_023 = 4, checks_023 = 7 (as duas
-- so contam nas tabelas vivas: depois do rollback, 0, com as copias); logo
-- depois da migration, meses = 0, revisoes = 0, linhas = 0 e travas = 0.
-- Sempre: meses_sem_revisao_publicada_com_contador = 0 (current_revision > 0
-- sem a revisao), revisoes_acima_do_contador = 0, linhas_com_pa_e_sem_pa = 0 e
-- travas_de_inativos = 0. As assinaturas (no fim) cobrem os cadastros e o
-- inventario de PA: a 023 nao muda nenhuma dessas linhas, entao sao iguais
-- antes, depois e depois do rollback.

SET time_zone = 'America/Sao_Paulo';

SELECT
    (SELECT COUNT(*) FROM information_schema.TABLES
      WHERE TABLE_SCHEMA = DATABASE()
        AND TABLE_NAME IN ('portal_monthly_schedules', 'portal_monthly_schedule_revisions', 'portal_monthly_schedule_entries', 'portal_pa_day_locks')) AS tabelas_023,
    (SELECT COUNT(*) FROM information_schema.REFERENTIAL_CONSTRAINTS
      WHERE CONSTRAINT_SCHEMA = DATABASE()
        AND TABLE_NAME IN ('portal_monthly_schedule_revisions', 'portal_monthly_schedule_entries', 'portal_pa_day_locks')
        AND CONSTRAINT_NAME IN ('fk_monthly_revision_schedule', 'fk_monthly_entry_revision', 'fk_monthly_entry_employee', 'fk_pa_day_lock_employee')) AS fks_023,
    (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
      WHERE TABLE_SCHEMA = DATABASE() AND CONSTRAINT_TYPE = 'CHECK'
        AND TABLE_NAME IN ('portal_monthly_schedules', 'portal_monthly_schedule_revisions', 'portal_monthly_schedule_entries')
        AND CONSTRAINT_NAME IN ('ck_monthly_schedule_day1', 'ck_monthly_schedule_version', 'ck_monthly_revision_number', 'ck_monthly_revision_hash',
                                'ck_monthly_revision_counts', 'ck_monthly_entry_pa', 'ck_monthly_entry_no_pa')) AS checks_023,
    (SELECT COUNT(*) FROM information_schema.TABLES
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME LIKE '%\_bkp\_023') AS copias_de_rollback_023;

SET @tem_023 := (
    SELECT COUNT(*) FROM information_schema.TABLES
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME IN ('portal_monthly_schedules', 'portal_monthly_schedule_revisions', 'portal_monthly_schedule_entries', 'portal_pa_day_locks')
);
SET @contagens_023_sql := IF(
    @tem_023 = 4,
    'SELECT
        (SELECT COUNT(*) FROM portal_monthly_schedules) AS meses,
        (SELECT COUNT(*) FROM portal_monthly_schedules WHERE current_revision > 0) AS meses_publicados,
        (SELECT COUNT(*) FROM portal_monthly_schedule_revisions) AS revisoes,
        (SELECT COUNT(*) FROM portal_monthly_schedule_revisions WHERE reason = ''regeracao'') AS regeracoes,
        (SELECT COUNT(*) FROM portal_monthly_schedule_entries) AS linhas,
        (SELECT COUNT(*) FROM portal_monthly_schedule_entries WHERE no_pa = 1) AS linhas_sem_pa,
        (SELECT COUNT(*) FROM portal_pa_day_locks) AS travas',
    'SELECT ''023 ausente'' AS situacao_023'
);
PREPARE contagens_023_stmt FROM @contagens_023_sql;
EXECUTE contagens_023_stmt;
DEALLOCATE PREPARE contagens_023_stmt;

SET @problemas_023_sql := IF(
    @tem_023 = 4,
    'SELECT
        (SELECT COUNT(*) FROM portal_monthly_schedules m
          WHERE m.current_revision > 0
            AND NOT EXISTS (SELECT 1 FROM portal_monthly_schedule_revisions r WHERE r.schedule_id = m.id AND r.revision = m.current_revision)) AS meses_sem_revisao_publicada_com_contador,
        (SELECT COUNT(*) FROM portal_monthly_schedule_revisions r JOIN portal_monthly_schedules m ON m.id = r.schedule_id
          WHERE r.revision > m.current_revision) AS revisoes_acima_do_contador,
        (SELECT COUNT(*) FROM portal_monthly_schedule_entries WHERE no_pa = 1 AND pa_number IS NOT NULL) AS linhas_com_pa_e_sem_pa,
        (SELECT COUNT(*) FROM portal_pa_day_locks l JOIN funcionarios f ON f.id = l.employee_id WHERE f.ativo = 0) AS travas_de_inativos',
    'SELECT ''023 ausente'' AS situacao_023'
);
PREPARE problemas_023_stmt FROM @problemas_023_sql;
EXECUTE problemas_023_stmt;
DEALLOCATE PREPARE problemas_023_stmt;

-- Assinaturas dos cadastros e do inventario (CRC32 por linha, combinado por
-- XOR; sem nome, login nem texto livre)
SELECT 'cadastros' AS tabela, COUNT(*) AS linhas,
       BIT_XOR(CRC32(CONCAT_WS('|', id, equipe, ativo))) AS assinatura
  FROM funcionarios
UNION ALL
SELECT 'inventario', COUNT(*),
       BIT_XOR(CRC32(CONCAT_WS('|', id, pa_number, display_order, grid_row, grid_column, status, active)))
  FROM portal_pa_inventory;
