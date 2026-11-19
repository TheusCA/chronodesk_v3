-- Rollback da migration 20261119_022_pa_inventory.sql (inventario de PA
-- completo e preferencias, F10-3). NAO e migration de deploy. Aplicar so
-- DEPOIS de voltar o codigo (o codigo do F10-3 le as colunas e a tabela novas).
--
-- 0. Checagem previa: se QUALQUER copia *_bkp_022 ja existir (rollback
--    anterior), PARA antes de qualquer alteracao, com o erro 45000
--    "rollback_022 abortado: ...". Mesmo motivo dos rollbacks da 021 e da 024:
--    um segundo rollback trocaria a copia pela atual, ja sem os dados. Para
--    repetir: conferir as copias, renomea-las (RENAME TABLE) e rodar de novo.
--    A checagem usa o procedimento temporario rollback_022_confere_copias
--    (SIGNAL so existe em procedimento); depois de uma parada ele fica no
--    banco ate a proxima execucao, que o recria e o apaga no fim.
-- 0b. Copias, so do que existe (cada uma confere information_schema):
--    portal_pa_inventory_bkp_022 (id, numero do PA, tipo, emprestimo e
--    reserva de cada PA: nenhum dado pessoal) e portal_pa_preferences_bkp_022
--    (a tabela inteira: so IDs e autoria). As copias ficam: remover a mao
--    depois de conferir.
-- 1. A tabela das preferencias sai; as duas restricoes e as tres colunas do
--    inventario saem. O inventario volta ao que era (numero, fileira, coluna,
--    situacao e ativo, que a 022 nao muda); os vinculos do Mapa PA nao mudam.
-- Cada passo confere information_schema antes de alterar. O arquivo inteiro
-- NAO se repete depois de uma execucao com a 022 aplicada: a segunda para no
-- passo 0, de proposito. Sem a 022, nada muda e nenhuma copia e criada.

SET time_zone = 'America/Sao_Paulo';

-- 0. Checagem previa das copias
DROP PROCEDURE IF EXISTS rollback_022_confere_copias;
DELIMITER //
CREATE PROCEDURE rollback_022_confere_copias()
BEGIN
    IF (SELECT COUNT(*) FROM information_schema.TABLES
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME IN ('portal_pa_inventory_bkp_022', 'portal_pa_preferences_bkp_022')) > 0 THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'rollback_022 abortado: ja existe copia *_bkp_022 de rollback anterior. Nada foi alterado. Renomeie as copias e repita.';
    END IF;
END//
DELIMITER ;
CALL rollback_022_confere_copias();
DROP PROCEDURE rollback_022_confere_copias;

-- 0b. Copias (nenhuma existe: a checagem acima garante)
SET @has_inventory_columns := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'portal_pa_inventory'
      AND COLUMN_NAME IN ('seat_type', 'can_lend', 'reserved_team')
);
SET @copy_inventory_sql := IF(
    @has_inventory_columns = 3,
    'CREATE TABLE portal_pa_inventory_bkp_022 AS
        SELECT id, pa_number, seat_type, can_lend, reserved_team
        FROM portal_pa_inventory',
    'SELECT 1'
);
PREPARE copy_inventory_stmt FROM @copy_inventory_sql;
EXECUTE copy_inventory_stmt;
DEALLOCATE PREPARE copy_inventory_stmt;

SET @has_preferences_table := (
    SELECT COUNT(*) FROM information_schema.TABLES
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'portal_pa_preferences'
);
SET @copy_preferences_sql := IF(
    @has_preferences_table > 0,
    'CREATE TABLE portal_pa_preferences_bkp_022 AS
        SELECT id, pa_id, employee_id, created_by, created_at
        FROM portal_pa_preferences',
    'SELECT 1'
);
PREPARE copy_preferences_stmt FROM @copy_preferences_sql;
EXECUTE copy_preferences_stmt;
DEALLOCATE PREPARE copy_preferences_stmt;

-- 1. Preferencias, restricoes e colunas
SET @has_preferences_table := (
    SELECT COUNT(*) FROM information_schema.TABLES
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'portal_pa_preferences'
);
SET @drop_preferences_sql := IF(@has_preferences_table > 0, 'DROP TABLE portal_pa_preferences', 'SELECT 1');
PREPARE drop_preferences_stmt FROM @drop_preferences_sql;
EXECUTE drop_preferences_stmt;
DEALLOCATE PREPARE drop_preferences_stmt;

SET @has_lend_check := (
    SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'portal_pa_inventory'
      AND CONSTRAINT_TYPE = 'CHECK' AND CONSTRAINT_NAME = 'ck_pa_inventory_lend'
);
SET @drop_lend_check_sql := IF(@has_lend_check > 0, 'ALTER TABLE portal_pa_inventory DROP CHECK ck_pa_inventory_lend', 'SELECT 1');
PREPARE drop_lend_check_stmt FROM @drop_lend_check_sql;
EXECUTE drop_lend_check_stmt;
DEALLOCATE PREPARE drop_lend_check_stmt;

SET @has_reserved_check := (
    SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'portal_pa_inventory'
      AND CONSTRAINT_TYPE = 'CHECK' AND CONSTRAINT_NAME = 'ck_pa_inventory_reserved'
);
SET @drop_reserved_check_sql := IF(@has_reserved_check > 0, 'ALTER TABLE portal_pa_inventory DROP CHECK ck_pa_inventory_reserved', 'SELECT 1');
PREPARE drop_reserved_check_stmt FROM @drop_reserved_check_sql;
EXECUTE drop_reserved_check_stmt;
DEALLOCATE PREPARE drop_reserved_check_stmt;

SET @drop_columns_sql := (
    SELECT IF(
        COUNT(*) = 0,
        'SELECT 1',
        CONCAT('ALTER TABLE portal_pa_inventory ', GROUP_CONCAT(CONCAT('DROP COLUMN ', COLUMN_NAME) ORDER BY COLUMN_NAME SEPARATOR ', '))
    )
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'portal_pa_inventory'
      AND COLUMN_NAME IN ('seat_type', 'can_lend', 'reserved_team')
);
PREPARE drop_columns_stmt FROM @drop_columns_sql;
EXECUTE drop_columns_stmt;
DEALLOCATE PREPARE drop_columns_stmt;
