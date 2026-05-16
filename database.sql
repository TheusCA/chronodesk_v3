-- Criação do Banco de Dados
CREATE DATABASE IF NOT EXISTS sistema_pausas CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

USE sistema_pausas;

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
    INDEX idx_equipe (equipe)
) ENGINE=InnoDB;
