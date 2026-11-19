-- Verificacao da migration 022 (inventario de PA completo e preferencias,
-- F10-3). So contagens e assinaturas, nenhum nome nem login. Rodar depois da
-- 022, no pos-deploy e sempre que quiser conferir:
--   docker exec -i -e MYSQL_PWD="$P" Chrono_Desk_DB mysql -uroot --table sistema_pausas \
--     < migrations/verificacao/20261119_022_pa_inventory_check.sql
-- Tambem serve antes da 022 e depois do rollback: as contagens das colunas e
-- da tabela novas so rodam com elas presentes (senao, "022 ausente").
--
-- Esperado com a 022: colunas_022 = 3, checks_022 = 2, tabela_022 = 1; logo
-- depois da migration, todos os PAs 'flexible' (fixos = 0, reservados_critico
-- = 0, fixos_que_emprestam = 0) e preferencias = 0. Sempre: as contagens de
-- problema (reservados_hibrido, preferencias_em_pa_reservado,
-- preferencias_fora_do_grupo_comum, reservados_com_vinculo_vigente,
-- fixos_com_vinculos_sobrepostos) = 0. As assinaturas (no fim) cobrem o
-- inventario (sem as colunas novas) e os vinculos: a 022 nao muda nenhuma
-- linha, entao sao iguais antes, depois e depois do rollback.

SET time_zone = 'America/Sao_Paulo';

SELECT
    (SELECT COUNT(*) FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'portal_pa_inventory'
        AND COLUMN_NAME IN ('seat_type', 'can_lend', 'reserved_team')) AS colunas_022,
    (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'portal_pa_inventory' AND CONSTRAINT_TYPE = 'CHECK'
        AND CONSTRAINT_NAME IN ('ck_pa_inventory_reserved', 'ck_pa_inventory_lend')) AS checks_022,
    (SELECT COUNT(*) FROM information_schema.TABLES
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'portal_pa_preferences') AS tabela_022,
    (SELECT COUNT(*) FROM information_schema.TABLES
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME LIKE '%\_bkp\_022') AS copias_de_rollback_022;

SET @tem_022 := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'portal_pa_inventory'
      AND COLUMN_NAME IN ('seat_type', 'can_lend', 'reserved_team')
) + (
    SELECT COUNT(*) FROM information_schema.TABLES
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'portal_pa_preferences'
);
SET @inventario_022_sql := IF(
    @tem_022 = 4,
    'SELECT
        (SELECT COUNT(*) FROM portal_pa_inventory WHERE active = 1) AS pas_ativos,
        (SELECT COUNT(*) FROM portal_pa_inventory WHERE active = 1 AND seat_type = ''flexible'') AS flexiveis,
        (SELECT COUNT(*) FROM portal_pa_inventory WHERE active = 1 AND seat_type = ''fixed'' AND reserved_team IS NULL) AS fixos,
        (SELECT COUNT(*) FROM portal_pa_inventory WHERE active = 1 AND seat_type = ''fixed'' AND can_lend = 1) AS fixos_que_emprestam,
        (SELECT COUNT(*) FROM portal_pa_inventory WHERE active = 1 AND reserved_team = ''critico'') AS reservados_critico,
        (SELECT COUNT(*) FROM portal_pa_inventory WHERE reserved_team = ''hibrido'') AS reservados_hibrido,
        (SELECT COUNT(*) FROM portal_pa_preferences) AS preferencias',
    'SELECT ''022 ausente'' AS situacao_022'
);
PREPARE inventario_022_stmt FROM @inventario_022_sql;
EXECUTE inventario_022_stmt;
DEALLOCATE PREPARE inventario_022_stmt;

SET @problemas_022_sql := IF(
    @tem_022 = 4,
    'SELECT
        (SELECT COUNT(*) FROM portal_pa_preferences p
           JOIN portal_pa_inventory i ON i.id = p.pa_id
          WHERE i.reserved_team IS NOT NULL) AS preferencias_em_pa_reservado,
        (SELECT COUNT(*) FROM portal_pa_preferences p
           JOIN funcionarios f ON f.id = p.employee_id
          WHERE f.ativo = 1
            AND (LOWER(TRIM(f.equipe)) NOT IN (''n1'', ''n2'') OR f.grupo_escala <> ''comum'' OR f.fora_mapa_escala = 1)) AS preferencias_fora_do_grupo_comum,
        (SELECT COUNT(*) FROM portal_pa_assignments a
           JOIN portal_pa_inventory i ON i.pa_number = a.pa_number
          WHERE a.active = 1 AND i.reserved_team IS NOT NULL
            AND (a.valid_until IS NULL OR a.valid_until >= CURDATE())) AS reservados_com_vinculo_vigente,
        (SELECT COUNT(*) FROM portal_pa_assignments a
           JOIN portal_pa_assignments b
             ON b.pa_number = a.pa_number AND b.id > a.id AND b.active = 1
            AND b.valid_from <= COALESCE(a.valid_until, ''9999-12-31'')
            AND a.valid_from <= COALESCE(b.valid_until, ''9999-12-31'')
           JOIN portal_pa_inventory i ON i.pa_number = a.pa_number
          WHERE a.active = 1 AND i.seat_type = ''fixed'' AND i.reserved_team IS NULL
            AND COALESCE(a.valid_until, ''9999-12-31'') >= CURDATE()
            AND COALESCE(b.valid_until, ''9999-12-31'') >= CURDATE()) AS fixos_com_vinculos_sobrepostos',
    'SELECT ''022 ausente'' AS situacao_022'
);
PREPARE problemas_022_stmt FROM @problemas_022_sql;
EXECUTE problemas_022_stmt;
DEALLOCATE PREPARE problemas_022_stmt;

-- Assinaturas do inventario (sem as colunas novas) e dos vinculos (CRC32 por
-- linha, combinado por XOR; sem nome, login nem observacao)
SELECT 'inventario' AS tabela, COUNT(*) AS linhas,
       BIT_XOR(CRC32(CONCAT_WS('|', id, pa_number, display_order, COALESCE(grid_row, ''), COALESCE(grid_column, ''), status, active))) AS assinatura
  FROM portal_pa_inventory
UNION ALL
SELECT 'vinculos', COUNT(*),
       BIT_XOR(CRC32(CONCAT_WS('|', id, pa_number, employee_id, valid_from, COALESCE(valid_until, ''), active)))
  FROM portal_pa_assignments;
