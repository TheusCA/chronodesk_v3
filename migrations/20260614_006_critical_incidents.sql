-- ChronoDesk - chamados criticos e salas de crise.
-- Migration incremental para MySQL 8.0. Nao remove nem altera dados legados.

CREATE TABLE IF NOT EXISTS portal_critical_incidents (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    ticket_number VARCHAR(100) NOT NULL,
    source ENUM('servicenow', 'jira', 'teams', 'manual', 'other') NOT NULL DEFAULT 'manual',
    title VARCHAR(180) NOT NULL,
    summary TEXT DEFAULT NULL,
    severity ENUM('low', 'medium', 'high', 'critical') NOT NULL,
    status ENUM(
        'open', 'in_progress', 'war_room', 'mitigated', 'resolved', 'cancelled'
    ) NOT NULL DEFAULT 'open',
    opened_at DATETIME NOT NULL,
    war_room_started_at DATETIME DEFAULT NULL,
    mitigated_at DATETIME DEFAULT NULL,
    resolved_at DATETIME DEFAULT NULL,
    impact TEXT DEFAULT NULL,
    affected_users INT UNSIGNED DEFAULT NULL,
    affected_services TEXT DEFAULT NULL,
    responsible_area VARCHAR(120) DEFAULT NULL,
    owner_name VARCHAR(160) DEFAULT NULL,
    owner_login VARCHAR(100) DEFAULT NULL,
    involved_teams VARCHAR(500) DEFAULT NULL,
    root_cause TEXT DEFAULT NULL,
    resolution TEXT DEFAULT NULL,
    workaround TEXT DEFAULT NULL,
    actions_taken TEXT DEFAULT NULL,
    next_steps TEXT DEFAULT NULL,
    meeting_url VARCHAR(1000) DEFAULT NULL,
    participants TEXT DEFAULT NULL,
    notes TEXT DEFAULT NULL,
    created_by VARCHAR(100) NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_by VARCHAR(100) NOT NULL,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_critical_incident_ticket (source, ticket_number),
    INDEX idx_critical_incident_period (opened_at, status, severity),
    INDEX idx_critical_incident_status (status, updated_at),
    INDEX idx_critical_incident_area (responsible_area, opened_at),
    INDEX idx_critical_incident_owner (owner_login, opened_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
