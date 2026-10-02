-- ChronoDesk - equipe do anexo de escala (Lote 4, SEC-08).
-- Idempotente. Numeracao: 011 a 014 reservadas para a Parte B; 015 e 016 do
-- Lote 7 e do Lote 5.
--
-- portal_shift_attachments ganha a coluna team: n1, n2 ou lideranca; NULL =
-- todas as equipes. Tecnico e somente leitura veem os anexos da propria equipe
-- e os de todas as equipes; gestor, lideranca e admin veem todos (decisao de
-- 2026-10-02). Nenhuma linha e alterada: os anexos existentes ficam como
-- "todas as equipes", que e o comportamento de hoje.
--
-- Pode ser aplicada ANTES do git pull: o codigo anterior nao le a coluna e,
-- ao gravar, deixa NULL.
--
-- Rollback: migrations/rollback/20261002_017_shift_attachment_team_down.sql.

SET time_zone = 'America/Sao_Paulo';

SET @has_shift_team := (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'portal_shift_attachments'
      AND COLUMN_NAME = 'team'
);
SET @add_shift_team_sql := IF(
    @has_shift_team = 0,
    'ALTER TABLE portal_shift_attachments
        ADD COLUMN team ENUM(''n1'', ''n2'', ''lideranca'') NULL DEFAULT NULL
        COMMENT ''Equipe que ve o anexo; NULL = todas as equipes''
        AFTER reference_month',
    'SELECT 1'
);
PREPARE add_shift_team_stmt FROM @add_shift_team_sql;
EXECUTE add_shift_team_stmt;
DEALLOCATE PREPARE add_shift_team_stmt;

SET @has_shift_team_index := (
    SELECT COUNT(*)
    FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'portal_shift_attachments'
      AND INDEX_NAME = 'idx_shift_attachment_team'
);
SET @add_shift_team_index_sql := IF(
    @has_shift_team_index = 0,
    'ALTER TABLE portal_shift_attachments ADD INDEX idx_shift_attachment_team (status, team, reference_month)',
    'SELECT 1'
);
PREPARE add_shift_team_index_stmt FROM @add_shift_team_index_sql;
EXECUTE add_shift_team_index_stmt;
DEALLOCATE PREPARE add_shift_team_index_stmt;
