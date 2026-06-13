-- ChronoDesk Portal Operacional - fundação incremental
-- Não remove nem altera dados legados.

CREATE TABLE IF NOT EXISTS portal_modules (
    id INT AUTO_INCREMENT PRIMARY KEY,
    module_key VARCHAR(60) NOT NULL UNIQUE,
    name VARCHAR(100) NOT NULL,
    minimum_role ENUM('admin', 'gestor', 'tecnico', 'somente_leitura') NOT NULL DEFAULT 'tecnico',
    enabled BOOLEAN NOT NULL DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS portal_notifications (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    recipient_username VARCHAR(100) DEFAULT NULL,
    recipient_role VARCHAR(30) DEFAULT NULL,
    title VARCHAR(160) NOT NULL,
    message VARCHAR(1000) NOT NULL,
    type VARCHAR(50) NOT NULL DEFAULT 'system',
    severity ENUM('info', 'warning', 'critical') NOT NULL DEFAULT 'info',
    related_url VARCHAR(255) DEFAULT NULL,
    is_read BOOLEAN NOT NULL DEFAULT FALSE,
    read_at DATETIME DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_notification_recipient (recipient_username, recipient_role, is_read),
    INDEX idx_notification_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

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

CREATE TABLE IF NOT EXISTS pause_approval_requests (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    employee_id INT NOT NULL,
    employee_name VARCHAR(100) NOT NULL,
    team VARCHAR(30) NOT NULL,
    reason VARCHAR(50) NOT NULL DEFAULT 'Reunião',
    observation VARCHAR(1000) DEFAULT NULL,
    requested_at DATETIME NOT NULL,
    status ENUM('pending', 'approved', 'rejected') NOT NULL DEFAULT 'pending',
    pending_employee_id INT GENERATED ALWAYS AS (
        CASE WHEN status = 'pending' THEN employee_id ELSE NULL END
    ) STORED,
    decided_at DATETIME DEFAULT NULL,
    decided_by VARCHAR(100) DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_approval_employee_status (employee_id, status),
    INDEX idx_approval_requested (requested_at),
    INDEX idx_approval_status (status),
    UNIQUE KEY uq_approval_pending_employee (pending_employee_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS portal_calendar_events (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(160) NOT NULL,
    description TEXT DEFAULT NULL,
    type VARCHAR(50) NOT NULL,
    starts_at DATETIME NOT NULL,
    ends_at DATETIME DEFAULT NULL,
    team VARCHAR(30) DEFAULT NULL,
    related_username VARCHAR(100) DEFAULT NULL,
    created_by VARCHAR(100) NOT NULL,
    status VARCHAR(30) NOT NULL DEFAULT 'active',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_calendar_range (starts_at, ends_at),
    INDEX idx_calendar_team (team)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS portal_announcements (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(160) NOT NULL,
    category VARCHAR(50) NOT NULL,
    severity ENUM('info', 'warning', 'critical') NOT NULL DEFAULT 'info',
    content TEXT NOT NULL,
    audience VARCHAR(100) DEFAULT 'all',
    is_pinned BOOLEAN NOT NULL DEFAULT FALSE,
    created_by VARCHAR(100) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_announcement_status (is_pinned, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS portal_documents (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(180) NOT NULL,
    category VARCHAR(50) NOT NULL,
    document_type VARCHAR(30) NOT NULL,
    content MEDIUMTEXT NOT NULL,
    tags VARCHAR(500) DEFAULT NULL,
    author VARCHAR(100) NOT NULL,
    status VARCHAR(30) NOT NULL DEFAULT 'published',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FULLTEXT KEY ft_documents (title, content, tags)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS portal_schedules (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    employee_id INT DEFAULT NULL,
    employee_name VARCHAR(100) NOT NULL,
    team VARCHAR(30) NOT NULL,
    schedule_type ENUM('turno', 'home_office', 'presencial', 'folga', 'ferias', 'sobreaviso', 'plantao', 'ausencia') NOT NULL,
    starts_at DATETIME NOT NULL,
    ends_at DATETIME NOT NULL,
    phone VARCHAR(30) DEFAULT NULL,
    status VARCHAR(30) NOT NULL DEFAULT 'active',
    created_by VARCHAR(100) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_schedule_range (starts_at, ends_at),
    INDEX idx_schedule_team (team, schedule_type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS portal_absences (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    employee_id INT NOT NULL,
    employee_name VARCHAR(100) NOT NULL,
    absence_type ENUM('ferias', 'atestado', 'treinamento', 'folga', 'justificada') NOT NULL,
    starts_on DATE NOT NULL,
    ends_on DATE NOT NULL,
    reason VARCHAR(500) DEFAULT NULL,
    status ENUM('pending', 'approved', 'rejected') NOT NULL DEFAULT 'pending',
    approved_by VARCHAR(100) DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_absence_range (starts_on, ends_on),
    INDEX idx_absence_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS portal_overtime (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    employee_id INT NOT NULL,
    employee_name VARCHAR(100) NOT NULL,
    work_date DATE NOT NULL,
    minutes INT UNSIGNED NOT NULL,
    reason VARCHAR(500) NOT NULL,
    status ENUM('pending', 'approved', 'rejected') NOT NULL DEFAULT 'pending',
    approved_by VARCHAR(100) DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_overtime_date (work_date),
    INDEX idx_overtime_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS portal_time_corrections (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    employee_id INT NOT NULL,
    employee_name VARCHAR(100) NOT NULL,
    correction_date DATE NOT NULL,
    correction_time TIME NOT NULL,
    reason VARCHAR(160) NOT NULL,
    justification VARCHAR(1000) NOT NULL,
    status ENUM('pending', 'approved', 'rejected') NOT NULL DEFAULT 'pending',
    reviewed_by VARCHAR(100) DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_correction_date (correction_date),
    INDEX idx_correction_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS portal_integrations (
    id INT AUTO_INCREMENT PRIMARY KEY,
    integration_key VARCHAR(60) NOT NULL UNIQUE,
    enabled BOOLEAN NOT NULL DEFAULT FALSE,
    last_status VARCHAR(30) DEFAULT NULL,
    last_checked_at DATETIME DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO portal_modules (module_key, name, minimum_role) VALUES
('dashboard', 'Dashboard', 'tecnico'),
('pausas', 'Pausas', 'tecnico'),
('admin', 'Administração', 'admin'),
('metricas', 'Métricas', 'gestor'),
('calendario', 'Calendário', 'tecnico'),
('tecnicos', 'Técnicos', 'tecnico'),
('escala_turnos', 'Escala de turnos', 'tecnico'),
('escala_presencial', 'Escala presencial', 'tecnico'),
('ausencias', 'Ausências', 'tecnico'),
('horas_extras', 'Horas extras', 'tecnico'),
('correcao_ponto', 'Correção de ponto', 'tecnico'),
('avisos', 'Avisos', 'tecnico'),
('plantonistas', 'Plantonistas', 'tecnico'),
('sobreavisos', 'Sobreavisos', 'tecnico'),
('documentacao', 'Documentação', 'tecnico'),
('relatorios', 'Relatórios', 'gestor'),
('configuracoes', 'Configurações', 'admin'),
('integracoes', 'Integrações', 'admin');
