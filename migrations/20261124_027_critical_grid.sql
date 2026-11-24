-- ChronoDesk - Escala do Critico em grade semanal (pedido da Lideranca,
-- 2026-11-24; desenho em docs/DESENHO_ESCALA_CRITICO.md, D-ESC-13 a D-ESC-18).
-- Idempotente: cada passo confere information_schema antes de alterar.
--
-- 1. portal_critical_roles.color_token VARCHAR(16) NULL (ALGORITHM=INSTANT):
--    a cor do papel numa paleta fechada (ck_critical_role_color: azul, verde,
--    pessego, lilas, amarelo, turquesa, cinza, areia), sem hex no banco.
--    Padrao pelo comeco do nome, SO onde a cor esta nula: Presencial azul,
--    Abertura verde, Fechamento pessego, Home lilas (os demais ficam nulos e a
--    tela mostra areia). Unica linha que muda: a cor desses papeis (o
--    updated_at deles muda junto; o updated_by nao).
-- 2. portal_critical_role_rotations: a CHECK ck_critical_rotation_roles passa
--    a aceitar o mesmo papel mais de uma vez (vagas, D-ESC-15); continua 1 a 20
--    IDs inteiros >= 1. As pessoas continuam sem repetir. Nenhuma linha muda.
-- 3. portal_absences.category ganha 'banco_horas' no FIM do ENUM (D-ESC-18).
--    Nenhuma linha muda.
-- 4. portal_holidays: feriados (data unica, nome, abrangencia 'todos',
--    row_version, autoria), vazia (D-ESC-16).
--
-- Pode ser aplicada ANTES do git merge (o codigo anterior le os papeis e as
-- ausencias com a lista de colunas dele, nunca grava papel repetido nem
-- banco_horas, e nao le portal_holidays) e DEVE ser aplicada antes dele: o
-- codigo novo le a cor e os feriados.
-- A data nao e usada; SET time_zone segue a regra do A4 para sessao manual.
-- Exige MySQL 8.0.17 ou mais novo (JSON_SCHEMA_VALID em CHECK).
--
-- Ensaio (no servidor, a partir de /var/www/chronodesk; audit_log so na
-- estrutura):
--   sudo env DADOS=portal_critical_roles,portal_critical_role_rotations,portal_absences \
--     VERIFICA=migrations/verificacao/20261124_027_critical_grid_check.sql \
--     bash scripts/ensaio-migration.sh esc_027 \
--     migrations/20261124_027_critical_grid.sql \
--     migrations/rollback/20261124_027_critical_grid_down.sql audit_log
--
-- Rollback: migrations/rollback/20261124_027_critical_grid_down.sql.
-- Verificacao: migrations/verificacao/20261124_027_critical_grid_check.sql.

SET time_zone = 'America/Sao_Paulo';

-- 1. Cor do papel
SET @has_color := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'portal_critical_roles' AND COLUMN_NAME = 'color_token'
);
SET @add_color_sql := IF(
    @has_color = 0,
    'ALTER TABLE portal_critical_roles
        ADD COLUMN color_token VARCHAR(16) NULL COMMENT ''Cor do papel na paleta fechada da tela (D-ESC-13); nulo = areia''
        AFTER sort_order, ALGORITHM=INSTANT',
    'SELECT 1'
);
PREPARE add_color_stmt FROM @add_color_sql;
EXECUTE add_color_stmt;
DEALLOCATE PREPARE add_color_stmt;

SET @has_color_check := (
    SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'portal_critical_roles'
      AND CONSTRAINT_TYPE = 'CHECK' AND CONSTRAINT_NAME = 'ck_critical_role_color'
);
SET @add_color_check_sql := IF(
    @has_color_check = 0,
    'ALTER TABLE portal_critical_roles
        ADD CONSTRAINT ck_critical_role_color CHECK (color_token IS NULL OR color_token IN (''azul'', ''verde'', ''pessego'', ''lilas'', ''amarelo'', ''turquesa'', ''cinza'', ''areia''))',
    'SELECT 1'
);
PREPARE add_color_check_stmt FROM @add_color_check_sql;
EXECUTE add_color_check_stmt;
DEALLOCATE PREPARE add_color_check_stmt;

-- Padrao pelo nome, so onde a cor esta nula (a 2a execucao nao muda nada).
UPDATE portal_critical_roles
   SET color_token = CASE
           WHEN name LIKE 'Presencial%' THEN 'azul'
           WHEN name LIKE 'Abertura%' THEN 'verde'
           WHEN name LIKE 'Fechamento%' THEN 'pessego'
           WHEN name LIKE 'Home%' THEN 'lilas'
       END
 WHERE color_token IS NULL
   AND (name LIKE 'Presencial%' OR name LIKE 'Abertura%' OR name LIKE 'Fechamento%' OR name LIKE 'Home%');

-- 2. Vagas: a lista de papeis do rodizio aceita repeticao
SET @roles_check_unique := (
    SELECT COUNT(*) FROM information_schema.CHECK_CONSTRAINTS
    WHERE CONSTRAINT_SCHEMA = DATABASE() AND CONSTRAINT_NAME = 'ck_critical_rotation_roles'
      AND CHECK_CLAUSE LIKE '%uniqueItems%'
);
SET @drop_roles_check_sql := IF(
    @roles_check_unique > 0,
    'ALTER TABLE portal_critical_role_rotations DROP CHECK ck_critical_rotation_roles',
    'SELECT 1'
);
PREPARE drop_roles_check_stmt FROM @drop_roles_check_sql;
EXECUTE drop_roles_check_stmt;
DEALLOCATE PREPARE drop_roles_check_stmt;

SET @has_roles_check := (
    SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'portal_critical_role_rotations'
      AND CONSTRAINT_TYPE = 'CHECK' AND CONSTRAINT_NAME = 'ck_critical_rotation_roles'
);
SET @add_roles_check_sql := IF(
    @has_roles_check = 0,
    'ALTER TABLE portal_critical_role_rotations
        ADD CONSTRAINT ck_critical_rotation_roles CHECK (JSON_SCHEMA_VALID(''{"type": "array", "minItems": 1, "maxItems": 20, "items": {"type": "integer", "minimum": 1}}'', roles))',
    'SELECT 1'
);
PREPARE add_roles_check_stmt FROM @add_roles_check_sql;
EXECUTE add_roles_check_stmt;
DEALLOCATE PREPARE add_roles_check_stmt;

-- 3. Banco de horas no fim do ENUM das ausencias
SET @has_banco := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'portal_absences' AND COLUMN_NAME = 'category'
      AND COLUMN_TYPE LIKE '%''banco_horas''%'
);
SET @add_banco_sql := IF(
    @has_banco = 0,
    'ALTER TABLE portal_absences
        MODIFY COLUMN category ENUM(''folga'', ''ferias'', ''ausencia_justificada'', ''compromisso'', ''outro'', ''banco_horas'') NOT NULL',
    'SELECT 1'
);
PREPARE add_banco_stmt FROM @add_banco_sql;
EXECUTE add_banco_stmt;
DEALLOCATE PREPARE add_banco_stmt;

-- 4. Feriados
SET @has_holidays := (
    SELECT COUNT(*) FROM information_schema.TABLES
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'portal_holidays'
);
SET @create_holidays_sql := IF(
    @has_holidays = 0,
    'CREATE TABLE portal_holidays (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT,
        holiday_date DATE NOT NULL COMMENT ''Data do feriado (uma por data)'',
        name VARCHAR(80) NOT NULL COMMENT ''Nome do feriado'',
        scope ENUM(''todos'') NOT NULL DEFAULT ''todos'' COMMENT ''Abrangencia: todos os cadastros'',
        row_version INT UNSIGNED NOT NULL DEFAULT 1 COMMENT ''Versao para o 409 da edicao'',
        created_by VARCHAR(100) NOT NULL,
        updated_by VARCHAR(100) NOT NULL,
        created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY uq_holiday_date (holiday_date),
        CONSTRAINT ck_holiday_name CHECK (CHAR_LENGTH(TRIM(name)) > 0),
        CONSTRAINT ck_holiday_version CHECK (row_version >= 1)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
      COMMENT=''Feriados (D-ESC-16): dia sem expediente para todos; dia util no ciclo''',
    'SELECT 1'
);
PREPARE create_holidays_stmt FROM @create_holidays_sql;
EXECUTE create_holidays_stmt;
DEALLOCATE PREPARE create_holidays_stmt;
