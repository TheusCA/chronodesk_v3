-- ============================================
-- Portal SDK v3.0 - Schema do Banco de Dados
-- Atualizado para incluir: tabela funcionarios,
-- suporte a LDAP/AD, logs de autenticação
-- ============================================

CREATE DATABASE IF NOT EXISTS sistema_pausas 
    CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE sistema_pausas;

-- ============================================
-- Tabela de Funcionários (CIs)
-- NOVO: agora no MySQL, não mais apenas em JSON
-- Campo ad_login para autenticação via AD
-- ============================================
CREATE TABLE IF NOT EXISTS funcionarios (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    nome          VARCHAR(100) NOT NULL,
    equipe        VARCHAR(10)  NOT NULL DEFAULT 'n1',
    ad_login      VARCHAR(100) DEFAULT NULL COMMENT 'Login do Active Directory (ex: joao.silva)',
    jornada_entrada TIME NOT NULL DEFAULT '08:00:00',
    jornada_saida   TIME NOT NULL DEFAULT '17:00:00',
    almoco_inicio   TIME NOT NULL DEFAULT '12:00:00',
    almoco_fim      TIME NOT NULL DEFAULT '13:00:00',
    ativo         BOOLEAN NOT NULL DEFAULT TRUE,
    ad_login_ativo VARCHAR(100) GENERATED ALWAYS AS (
        CASE
            WHEN ativo = 1 AND ad_login IS NOT NULL AND ad_login <> '' THEN ad_login
            ELSE NULL
        END
    ) STORED,
    criado_em     TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    atualizado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_equipe (equipe),
    INDEX idx_ad_login (ad_login),
    INDEX idx_ativo (ativo),
    UNIQUE KEY uq_funcionarios_ad_login_ativo (ad_login_ativo)
) ENGINE=InnoDB;

-- ============================================
-- Tabela de Histórico de Pausas
-- ============================================
CREATE TABLE IF NOT EXISTS pausas (
    id                INT AUTO_INCREMENT PRIMARY KEY,
    id_funcionario    INT NOT NULL,
    nome_funcionario  VARCHAR(100) NOT NULL,
    equipe            VARCHAR(10) NOT NULL,
    inicio_pausa      DATETIME NOT NULL,
    fim_pausa         DATETIME NOT NULL,
    duracao_segundos  INT NOT NULL,
    motivo_pausa      VARCHAR(50) NOT NULL,
    alerta_15min      BOOLEAN DEFAULT FALSE,
    alerta_20min      BOOLEAN DEFAULT FALSE,
    status_aprovacao  VARCHAR(20) DEFAULT NULL,
    observacao_reuniao TEXT DEFAULT NULL,
    data_registro     TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_funcionario (id_funcionario),
    INDEX idx_data (inicio_pausa),
    INDEX idx_equipe (equipe),
    FOREIGN KEY (id_funcionario) REFERENCES funcionarios(id) ON DELETE RESTRICT
) ENGINE=InnoDB;

-- ============================================
-- Tabela de Usuários Admin/Gestores
-- ============================================
CREATE TABLE IF NOT EXISTS usuarios (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    username      VARCHAR(50) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    role          VARCHAR(20) NOT NULL DEFAULT 'gestor',
    created_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    last_login    TIMESTAMP NULL
) ENGINE=InnoDB;

-- ============================================
-- Tabela de Auditoria de Segurança
-- ============================================
CREATE TABLE IF NOT EXISTS audit_log (
    id        INT AUTO_INCREMENT PRIMARY KEY,
    timestamp DATETIME DEFAULT CURRENT_TIMESTAMP,
    user_ip   VARCHAR(45) NOT NULL DEFAULT 'unknown',
    username  VARCHAR(50) DEFAULT 'anonymous',
    action    VARCHAR(100) NOT NULL,
    details   TEXT,
    severity  ENUM('INFO','WARNING','CRITICAL') DEFAULT 'INFO',
    INDEX idx_timestamp (timestamp),
    INDEX idx_action (action),
    INDEX idx_severity (severity)
) ENGINE=InnoDB;

-- ============================================
-- Dados iniciais: Funcionários do setor
-- (migrar do funcionarios.json)
-- ============================================
INSERT IGNORE INTO funcionarios (id, nome, equipe, jornada_entrada, jornada_saida, almoco_inicio, almoco_fim, ativo) VALUES
(2,  'David Alexandre',  'n1', '08:00:00', '17:00:00', '12:00:00', '13:00:00', TRUE),
(3,  'Gustavo Eugenio',  'n1', '08:00:00', '17:00:00', '12:00:00', '13:00:00', TRUE),
(4,  'Matheus Moraes',   'n1', '08:00:00', '17:00:00', '12:00:00', '13:00:00', TRUE),
(5,  'Pedro Henrique',   'n1', '08:00:00', '17:00:00', '12:00:00', '13:00:00', TRUE),
(6,  'Sabrina Cristina', 'n1', '08:00:00', '17:00:00', '12:00:00', '13:00:00', TRUE),
(7,  'Victor Franco',    'n1', '08:00:00', '17:00:00', '12:00:00', '13:00:00', TRUE),
(8,  'Vinicius Martins', 'n1', '08:00:00', '17:00:00', '12:00:00', '13:00:00', TRUE),
(9,  'Bruno Ventura',    'n2', '08:00:00', '17:00:00', '12:00:00', '13:00:00', TRUE),
(10, 'Fabio Luiz',       'n2', '08:00:00', '17:00:00', '12:00:00', '13:00:00', TRUE),
(11, 'Gabriel Henrique', 'n2', '08:00:00', '17:00:00', '12:00:00', '13:00:00', TRUE),
(12, 'Guilherme Martin', 'n2', '08:00:00', '17:00:00', '12:00:00', '13:00:00', TRUE),
(13, 'Isabelly Cristina','n2', '08:00:00', '17:00:00', '12:00:00', '13:00:00', TRUE),
(15, 'Luiz Felipe',      'n2', '08:00:00', '17:00:00', '12:00:00', '13:00:00', TRUE),
(16, 'Danilo Angelo',    'n2', '08:00:00', '17:00:00', '12:00:00', '13:00:00', TRUE),
(17, 'Davi Henrique',    'n2', '08:00:00', '17:00:00', '12:00:00', '13:00:00', TRUE);

-- ============================================
-- Usuário dedicado para a aplicação
-- AJUSTE a senha antes de executar em produção
-- ============================================
-- CREATE USER IF NOT EXISTS 'chronodesk_app'@'localhost' IDENTIFIED BY 'SENHA_FORTE_AQUI';
-- GRANT SELECT, INSERT, UPDATE, DELETE ON sistema_pausas.* TO 'chronodesk_app'@'localhost';
-- FLUSH PRIVILEGES;
