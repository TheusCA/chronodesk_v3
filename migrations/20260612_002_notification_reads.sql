-- Compatibilidade para ambientes que ja aplicaram a migration de fundacao.
-- Leitura e individual por usuario; nenhuma notificacao existente e removida.

CREATE TABLE IF NOT EXISTS portal_notification_reads (
    notification_id BIGINT NOT NULL,
    username VARCHAR(100) NOT NULL,
    read_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (notification_id, username),
    INDEX idx_notification_read_user (username, read_at),
    CONSTRAINT fk_notification_read
        FOREIGN KEY (notification_id) REFERENCES portal_notifications(id)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
