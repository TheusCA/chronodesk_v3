-- ChronoDesk - escala fixa por dias da semana.
-- Idempotente; preserva regras existentes e adiciona configuracao JSON serializada.

SET @has_schedule_rules_table := (
    SELECT COUNT(*)
    FROM information_schema.TABLES
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'portal_schedule_rules'
);
SET @schedule_rule_type := (
    SELECT COLUMN_TYPE
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'portal_schedule_rules'
      AND COLUMN_NAME = 'rule_type'
);
SET @fix_schedule_rule_enum_sql := IF(
    @has_schedule_rules_table > 0 AND @schedule_rule_type IS NOT NULL AND @schedule_rule_type NOT LIKE '%fixed_weekdays%',
    'ALTER TABLE portal_schedule_rules
        MODIFY COLUMN rule_type ENUM(''even_days'', ''odd_days'', ''always_onsite'', ''always_remote'', ''undefined'', ''fixed_weekdays'') NOT NULL DEFAULT ''undefined''',
    'SELECT 1'
);
PREPARE fix_schedule_rule_enum_stmt FROM @fix_schedule_rule_enum_sql;
EXECUTE fix_schedule_rule_enum_stmt;
DEALLOCATE PREPARE fix_schedule_rule_enum_stmt;

SET @has_schedule_rule_config := (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'portal_schedule_rules'
      AND COLUMN_NAME = 'rule_config'
);
SET @add_schedule_rule_config_sql := IF(
    @has_schedule_rules_table > 0 AND @has_schedule_rule_config = 0,
    'ALTER TABLE portal_schedule_rules
        ADD COLUMN rule_config TEXT DEFAULT NULL AFTER rule_type',
    'SELECT 1'
);
PREPARE add_schedule_rule_config_stmt FROM @add_schedule_rule_config_sql;
EXECUTE add_schedule_rule_config_stmt;
DEALLOCATE PREPARE add_schedule_rule_config_stmt;

SET @has_pa_assignments_table := (
    SELECT COUNT(*)
    FROM information_schema.TABLES
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'portal_pa_assignments'
);
SET @pa_assignment_rule_type := (
    SELECT COLUMN_TYPE
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'portal_pa_assignments'
      AND COLUMN_NAME = 'schedule_rule_type'
);
SET @fix_pa_assignment_rule_enum_sql := IF(
    @has_pa_assignments_table > 0 AND @pa_assignment_rule_type IS NOT NULL AND @pa_assignment_rule_type NOT LIKE '%fixed_weekdays%',
    'ALTER TABLE portal_pa_assignments
        MODIFY COLUMN schedule_rule_type ENUM(''even_days'', ''odd_days'', ''always_onsite'', ''always_remote'', ''undefined'', ''fixed_weekdays'') NOT NULL DEFAULT ''undefined''',
    'SELECT 1'
);
PREPARE fix_pa_assignment_rule_enum_stmt FROM @fix_pa_assignment_rule_enum_sql;
EXECUTE fix_pa_assignment_rule_enum_stmt;
DEALLOCATE PREPARE fix_pa_assignment_rule_enum_stmt;

SET @has_pa_assignment_rule_config := (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'portal_pa_assignments'
      AND COLUMN_NAME = 'schedule_rule_config'
);
SET @add_pa_assignment_rule_config_sql := IF(
    @has_pa_assignments_table > 0 AND @has_pa_assignment_rule_config = 0,
    'ALTER TABLE portal_pa_assignments
        ADD COLUMN schedule_rule_config TEXT DEFAULT NULL AFTER schedule_rule_type',
    'SELECT 1'
);
PREPARE add_pa_assignment_rule_config_stmt FROM @add_pa_assignment_rule_config_sql;
EXECUTE add_pa_assignment_rule_config_stmt;
DEALLOCATE PREPARE add_pa_assignment_rule_config_stmt;
