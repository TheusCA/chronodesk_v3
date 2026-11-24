-- Verificacao da migration 027 (Escala do Critico em grade: cor do papel,
-- vagas no rodizio, banco de horas e feriados). So contagens e assinaturas:
-- nenhum nome de papel, de pessoa, de feriado nem login. Rodar depois da 027,
-- no pos-deploy e sempre que quiser conferir:
--   docker exec -i -e MYSQL_PWD="$P" Chrono_Desk_DB mysql -uroot --table sistema_pausas \
--     < migrations/verificacao/20261124_027_critical_grid_check.sql
-- Tambem serve antes da 027 e depois do rollback: as contagens novas so
-- rodam com a 027 inteira (senao, "027 ausente").
--
-- Esperado com a 027: colunas_027 = 1, checks_027 = 2 (a da cor e a dos
-- papeis do rodizio sem uniqueItems), tabela_027 = 1, enum_027 = 1; logo
-- depois da migration, feriados = 0, ausencias_banco_horas = 0,
-- rodizios_com_vaga_repetida = 0 e papeis_com_cor = quantos papeis comecam
-- por Presencial, Abertura, Fechamento ou Home. Sempre: cor_fora_da_lista = 0
-- e feriados_repetidos = 0. As assinaturas (no fim) cobrem os papeis sem a
-- cor, os rodizios e as ausencias: a 027 nao muda nenhuma dessas linhas, entao
-- sao iguais antes, depois e depois do rollback (sem banco de horas gravado).

SET time_zone = 'America/Sao_Paulo';

SELECT
    (SELECT COUNT(*) FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'portal_critical_roles' AND COLUMN_NAME = 'color_token') AS colunas_027,
    (SELECT COUNT(*) FROM information_schema.CHECK_CONSTRAINTS
      WHERE CONSTRAINT_SCHEMA = DATABASE()
        AND (CONSTRAINT_NAME = 'ck_critical_role_color'
          OR (CONSTRAINT_NAME = 'ck_critical_rotation_roles' AND CHECK_CLAUSE NOT LIKE '%uniqueItems%'))) AS checks_027,
    (SELECT COUNT(*) FROM information_schema.TABLES
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'portal_holidays') AS tabela_027,
    (SELECT COUNT(*) FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'portal_absences' AND COLUMN_NAME = 'category'
        AND COLUMN_TYPE LIKE '%''banco_horas''%') AS enum_027,
    (SELECT COUNT(*) FROM information_schema.TABLES
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME LIKE '%\_bkp\_027') AS copias_de_rollback_027;

SET @tem_027 := (
    (SELECT COUNT(*) FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'portal_critical_roles' AND COLUMN_NAME = 'color_token')
  + (SELECT COUNT(*) FROM information_schema.TABLES
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'portal_holidays')
);
SET @contagens_027_sql := IF(
    @tem_027 = 2,
    'SELECT
        (SELECT COUNT(*) FROM portal_critical_roles) AS papeis,
        (SELECT COUNT(*) FROM portal_critical_roles WHERE color_token IS NOT NULL) AS papeis_com_cor,
        (SELECT COUNT(*) FROM portal_critical_roles WHERE color_token IS NULL) AS papeis_sem_cor,
        (SELECT COUNT(*) FROM portal_critical_role_rotations) AS rodizios,
        (SELECT COUNT(*) FROM portal_critical_role_rotations r
          WHERE JSON_LENGTH(r.roles) <> (SELECT COUNT(DISTINCT j.role_id)
                FROM JSON_TABLE(r.roles, ''$[*]'' COLUMNS (role_id INT PATH ''$'')) AS j)) AS rodizios_com_vaga_repetida,
        (SELECT COUNT(*) FROM portal_holidays) AS feriados,
        (SELECT COUNT(*) FROM portal_absences WHERE category = ''banco_horas'') AS ausencias_banco_horas',
    'SELECT ''027 ausente'' AS situacao_027'
);
PREPARE contagens_027_stmt FROM @contagens_027_sql;
EXECUTE contagens_027_stmt;
DEALLOCATE PREPARE contagens_027_stmt;

SET @problemas_027_sql := IF(
    @tem_027 = 2,
    'SELECT
        (SELECT COUNT(*) FROM portal_critical_roles
          WHERE color_token IS NOT NULL
            AND color_token NOT IN (''azul'', ''verde'', ''pessego'', ''lilas'', ''amarelo'', ''turquesa'', ''cinza'', ''areia'')) AS cor_fora_da_lista,
        (SELECT COUNT(*) - COUNT(DISTINCT holiday_date) FROM portal_holidays) AS feriados_repetidos',
    'SELECT ''027 ausente'' AS situacao_027'
);
PREPARE problemas_027_stmt FROM @problemas_027_sql;
EXECUTE problemas_027_stmt;
DEALLOCATE PREPARE problemas_027_stmt;

-- Assinaturas dos papeis (sem a cor), dos rodizios e das ausencias (CRC32 por
-- linha, combinado por XOR; sem nome, login nem texto livre)
SELECT 'papeis' AS tabela, COUNT(*) AS linhas,
       BIT_XOR(CRC32(CONCAT_WS('|', id, presence_type, work_start, work_end, active, sort_order))) AS assinatura
  FROM portal_critical_roles
UNION ALL
SELECT 'rodizios', COUNT(*),
       BIT_XOR(CRC32(CONCAT_WS('|', id, effective_from, period, anchor_date, CAST(roles AS CHAR), CAST(employees AS CHAR))))
  FROM portal_critical_role_rotations
UNION ALL
SELECT 'ausencias', COUNT(*),
       BIT_XOR(CRC32(CONCAT_WS('|', id, employee_id, category, starts_on, ends_on, period_type, status)))
  FROM portal_absences;
