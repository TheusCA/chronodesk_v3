-- ChronoDesk - hardening idempotente dos modulos operacionais.
-- Preserva historico e permite atualizar ambientes que aplicaram uma versao
-- anterior da migration 003.

UPDATE portal_sync_queue AS queue_item
JOIN (
    SELECT grouped_duplicates.*
    FROM (
        SELECT record_type, record_id, operation, MAX(id) AS keep_id
        FROM portal_sync_queue
        WHERE status = 'pending'
        GROUP BY record_type, record_id, operation
        HAVING COUNT(*) > 1
    ) AS grouped_duplicates
) AS duplicates
    ON duplicates.record_type = queue_item.record_type
    AND duplicates.record_id = queue_item.record_id
    AND duplicates.operation = queue_item.operation
    AND duplicates.keep_id <> queue_item.id
SET
    queue_item.status = 'error',
    queue_item.last_error = COALESCE(
        queue_item.last_error,
        'Item pendente substituido durante hardening da fila.'
    )
WHERE queue_item.status = 'pending';

SET @has_pending_key := (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'portal_sync_queue'
      AND COLUMN_NAME = 'pending_key'
);
SET @add_pending_key_sql := IF(
    @has_pending_key = 0,
    'ALTER TABLE portal_sync_queue
        ADD COLUMN pending_key VARCHAR(160)
        GENERATED ALWAYS AS (
            CASE
                WHEN status = ''pending''
                THEN CONCAT(record_type, '':'', record_id, '':'', operation)
                ELSE NULL
            END
        ) STORED',
    'SELECT 1'
);
PREPARE add_pending_key_stmt FROM @add_pending_key_sql;
EXECUTE add_pending_key_stmt;
DEALLOCATE PREPARE add_pending_key_stmt;

SET @has_pending_key_index := (
    SELECT COUNT(*)
    FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'portal_sync_queue'
      AND INDEX_NAME = 'uq_sync_queue_pending'
);
SET @add_pending_key_index_sql := IF(
    @has_pending_key_index = 0,
    'ALTER TABLE portal_sync_queue
        ADD UNIQUE KEY uq_sync_queue_pending (pending_key)',
    'SELECT 1'
);
PREPARE add_pending_key_index_stmt FROM @add_pending_key_index_sql;
EXECUTE add_pending_key_index_stmt;
DEALLOCATE PREPARE add_pending_key_index_stmt;
