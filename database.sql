-- Criação do Banco de Dados
CREATE DATABASE IF NOT EXISTS sistema_pausas CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

USE sistema_pausas;

-- Tabela de Funcionários (fonte principal)
CREATE TABLE IF NOT EXISTS funcionarios (
    id INT AUTO_INCREMENT PRIMARY KEY,
    nome VARCHAR(100) NOT NULL,
    equipe VARCHAR(10) NOT NULL DEFAULT 'n1',
    ad_login VARCHAR(100) DEFAULT NULL COMMENT 'Login do Active Directory',
    jornada_entrada TIME NOT NULL DEFAULT '08:00:00',
    jornada_saida TIME NOT NULL DEFAULT '17:00:00',
    almoco_inicio TIME NOT NULL DEFAULT '12:00:00',
    almoco_fim TIME NOT NULL DEFAULT '13:00:00',
    ativo BOOLEAN NOT NULL DEFAULT TRUE,
    ad_login_ativo VARCHAR(100) GENERATED ALWAYS AS (
        CASE
            WHEN ativo = 1 AND ad_login IS NOT NULL AND ad_login <> '' THEN ad_login
            ELSE NULL
        END
    ) STORED,
    criado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    atualizado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_funcionarios_equipe (equipe),
    INDEX idx_funcionarios_ad_login (ad_login),
    INDEX idx_funcionarios_ativo (ativo),
    UNIQUE KEY uq_funcionarios_ad_login_ativo (ad_login_ativo)
) ENGINE=InnoDB;

-- Tabela de Histórico de Pausas (Substitui o pausas.csv)
CREATE TABLE IF NOT EXISTS pausas (
    id INT AUTO_INCREMENT PRIMARY KEY,
    id_funcionario INT NOT NULL,
    nome_funcionario VARCHAR(100) NOT NULL,
    equipe VARCHAR(10) NOT NULL,
    inicio_pausa DATETIME NOT NULL,
    fim_pausa DATETIME NOT NULL,
    duracao_segundos INT NOT NULL,
    motivo_pausa VARCHAR(50) NOT NULL,
    alerta_15min BOOLEAN DEFAULT FALSE,
    alerta_20min BOOLEAN DEFAULT FALSE,
    status_aprovacao VARCHAR(20) DEFAULT NULL,
    observacao_reuniao TEXT DEFAULT NULL,
    data_registro TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_funcionario (id_funcionario),
    INDEX idx_data (inicio_pausa),
    INDEX idx_equipe (equipe),
    CONSTRAINT fk_pausas_funcionarios FOREIGN KEY (id_funcionario) REFERENCES funcionarios(id) ON DELETE RESTRICT
) ENGINE=InnoDB;
