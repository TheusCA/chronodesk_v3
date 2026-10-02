-- Rollback da migration 20261002_017_shift_attachment_team.sql (Lote 4, SEC-08).
-- Aplicar DEPOIS de voltar o codigo (o codigo do Lote 4 le a coluna team).
-- Remove o indice e a coluna; a classificacao por equipe dos anexos se perde
-- e todos voltam a ser visiveis para qualquer perfil, como antes. Nenhum
-- anexo e apagado. Idempotente.

SET time_zone = 'America/Sao_Paulo';

SET @has_shift_team_index := (
    SELECT COUNT(*)
    FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'portal_shift_attachments'
      AND INDEX_NAME = 'idx_shift_attachment_team'
);
SET @drop_shift_team_index_sql := IF(
    @has_shift_team_index > 0,
    'ALTER TABLE portal_shift_attachments DROP INDEX idx_shift_attachment_team',
    'SELECT 1'
);
PREPARE drop_shift_team_index_stmt FROM @drop_shift_team_index_sql;
EXECUTE drop_shift_team_index_stmt;
DEALLOCATE PREPARE drop_shift_team_index_stmt;

SET @has_shift_team := (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'portal_shift_attachments'
      AND COLUMN_NAME = 'team'
);
SET @drop_shift_team_sql := IF(
    @has_shift_team > 0,
    'ALTER TABLE portal_shift_attachments DROP COLUMN team',
    'SELECT 1'
);
PREPARE drop_shift_team_stmt FROM @drop_shift_team_sql;
EXECUTE drop_shift_team_stmt;
DEALLOCATE PREPARE drop_shift_team_stmt;
