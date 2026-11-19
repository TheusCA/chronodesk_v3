-- ChronoDesk - inventario de PA completo e preferencias (desenho do F10, 3.3,
-- revisto em 2026-11-19: 3.3.1; lote F10-3).
-- Idempotente: cada passo confere information_schema antes de alterar.
-- So estrutura: nenhuma linha existente muda. Todo PA continua 'flexible',
-- sem emprestimo e sem reserva; o tipo, a reserva do Critico e as preferencias
-- sao cadastrados pela tela, depois do deploy, com a gestao.
--
-- 1. portal_pa_inventory ganha tres colunas (ALGORITHM=INSTANT, no fim):
--    seat_type ENUM('flexible','fixed') NOT NULL DEFAULT 'flexible';
--    can_lend TINYINT(1) NOT NULL DEFAULT 0 (so vale em fixed sem reserva: o
--    dono ausente empresta o PA ao grupo comum; D-F10-13);
--    reserved_team ENUM('critico','hibrido') NULL (assento reservado a uma
--    equipe de fora do grupo comum; so em fixed). D-F10-19 revista em
--    2026-11-16: o Critico tem N PAs reservados (opcao (b), N = 2,
--    configuravel: N e o numero de PAs marcados) e os papeis do dia (9.16)
--    dizem quem ocupa; o Hibrido fica na opcao (c) (PA fixo por pessoa, pelo
--    vinculo do Mapa PA): o valor 'hibrido' existe no ENUM, mas o servico
--    recusa enquanto a decisao for a (c).
-- 2. Duas restricoes CHECK no inventario (ALGORITHM=COPY: o MySQL confere as
--    linhas que existem; 16 linhas, com a manutencao ligada):
--    ck_pa_inventory_reserved: reserva so em fixed;
--    ck_pa_inventory_lend: emprestimo so em fixed sem reserva.
-- 3. portal_pa_preferences: preferencia de pessoa por PA (sem ordem, como o
--    script: custo 2 para qualquer preferencia), unica por par, FKs com
--    RESTRICT, autoria. So IDs: nenhum nome.
--
-- O dono do PA fixo continua sendo o vinculo recorrente (portal_pa_assignments,
-- migration 009): nada muda nele.
--
-- Pode ser aplicada ANTES do git merge (o codigo anterior le o inventario com
-- a lista de colunas dele e nao le a tabela nova) e DEVE ser aplicada antes
-- dele: o codigo do F10-3 le as colunas novas e a tabela nova.
-- A data nao e usada; SET time_zone segue a regra do A4 para sessao manual.
--
-- Ensaio (no servidor, a partir de /var/www/chronodesk; audit_log so na
-- estrutura):
--   sudo env DADOS=portal_pa_inventory,portal_pa_assignments \
--     VERIFICA=migrations/verificacao/20261119_022_pa_inventory_check.sql \
--     bash scripts/ensaio-migration.sh f10_022 \
--     migrations/20261119_022_pa_inventory.sql \
--     migrations/rollback/20261119_022_pa_inventory_down.sql audit_log
--
-- Rollback: migrations/rollback/20261119_022_pa_inventory_down.sql.
-- Verificacao: migrations/verificacao/20261119_022_pa_inventory_check.sql.

SET time_zone = 'America/Sao_Paulo';

-- 1. Colunas do inventario
SET @has_seat_type := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'portal_pa_inventory' AND COLUMN_NAME = 'seat_type'
);
SET @add_seat_type_sql := IF(
    @has_seat_type = 0,
    'ALTER TABLE portal_pa_inventory
        ADD COLUMN seat_type ENUM(''flexible'', ''fixed'') NOT NULL DEFAULT ''flexible''
        COMMENT ''flexible: entra na distribuicao do dia; fixed: do dono (vinculo) ou da equipe reservada (F10-3)'',
        ALGORITHM=INSTANT',
    'SELECT 1'
);
PREPARE add_seat_type_stmt FROM @add_seat_type_sql;
EXECUTE add_seat_type_stmt;
DEALLOCATE PREPARE add_seat_type_stmt;

SET @has_can_lend := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'portal_pa_inventory' AND COLUMN_NAME = 'can_lend'
);
SET @add_can_lend_sql := IF(
    @has_can_lend = 0,
    'ALTER TABLE portal_pa_inventory
        ADD COLUMN can_lend TINYINT(1) NOT NULL DEFAULT 0
        COMMENT ''1 = PA fixo de dono ausente entra na distribuicao do dia (D-F10-13)'',
        ALGORITHM=INSTANT',
    'SELECT 1'
);
PREPARE add_can_lend_stmt FROM @add_can_lend_sql;
EXECUTE add_can_lend_stmt;
DEALLOCATE PREPARE add_can_lend_stmt;

SET @has_reserved_team := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'portal_pa_inventory' AND COLUMN_NAME = 'reserved_team'
);
SET @add_reserved_team_sql := IF(
    @has_reserved_team = 0,
    'ALTER TABLE portal_pa_inventory
        ADD COLUMN reserved_team ENUM(''critico'', ''hibrido'') NULL DEFAULT NULL
        COMMENT ''Assento reservado a equipe (D-F10-19 revista: Critico; o Hibrido fica no PA fixo por pessoa)'',
        ALGORITHM=INSTANT',
    'SELECT 1'
);
PREPARE add_reserved_team_stmt FROM @add_reserved_team_sql;
EXECUTE add_reserved_team_stmt;
DEALLOCATE PREPARE add_reserved_team_stmt;

-- 2. Restricoes do inventario
SET @has_reserved_check := (
    SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'portal_pa_inventory'
      AND CONSTRAINT_TYPE = 'CHECK' AND CONSTRAINT_NAME = 'ck_pa_inventory_reserved'
);
SET @add_reserved_check_sql := IF(
    @has_reserved_check = 0,
    'ALTER TABLE portal_pa_inventory
        ADD CONSTRAINT ck_pa_inventory_reserved CHECK (reserved_team IS NULL OR seat_type = ''fixed''),
        ALGORITHM=COPY',
    'SELECT 1'
);
PREPARE add_reserved_check_stmt FROM @add_reserved_check_sql;
EXECUTE add_reserved_check_stmt;
DEALLOCATE PREPARE add_reserved_check_stmt;

SET @has_lend_check := (
    SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'portal_pa_inventory'
      AND CONSTRAINT_TYPE = 'CHECK' AND CONSTRAINT_NAME = 'ck_pa_inventory_lend'
);
SET @add_lend_check_sql := IF(
    @has_lend_check = 0,
    'ALTER TABLE portal_pa_inventory
        ADD CONSTRAINT ck_pa_inventory_lend CHECK (can_lend IN (0, 1) AND (can_lend = 0 OR (seat_type = ''fixed'' AND reserved_team IS NULL))),
        ALGORITHM=COPY',
    'SELECT 1'
);
PREPARE add_lend_check_stmt FROM @add_lend_check_sql;
EXECUTE add_lend_check_stmt;
DEALLOCATE PREPARE add_lend_check_stmt;

-- 3. Preferencias
SET @has_preferences_table := (
    SELECT COUNT(*) FROM information_schema.TABLES
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'portal_pa_preferences'
);
SET @create_preferences_sql := IF(
    @has_preferences_table = 0,
    'CREATE TABLE portal_pa_preferences (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        pa_id BIGINT NOT NULL COMMENT ''PA do inventario (portal_pa_inventory.id)'',
        employee_id INT NOT NULL,
        created_by VARCHAR(100) NOT NULL,
        created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY uq_pa_preference (pa_id, employee_id),
        KEY idx_pa_preference_employee (employee_id),
        CONSTRAINT fk_pa_preference_pa FOREIGN KEY (pa_id) REFERENCES portal_pa_inventory (id) ON DELETE RESTRICT,
        CONSTRAINT fk_pa_preference_employee FOREIGN KEY (employee_id) REFERENCES funcionarios (id) ON DELETE RESTRICT
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
      COMMENT=''Preferencias de PA do grupo comum (F10-3): custo 2 na distribuicao''',
    'SELECT 1'
);
PREPARE create_preferences_stmt FROM @create_preferences_sql;
EXECUTE create_preferences_stmt;
DEALLOCATE PREPARE create_preferences_stmt;
