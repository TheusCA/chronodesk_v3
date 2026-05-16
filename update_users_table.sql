-- Tabela de Usuários do Sistema
CREATE TABLE IF NOT EXISTS usuarios (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(50) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    role VARCHAR(20) NOT NULL DEFAULT 'admin', -- 'admin' ou 'gestor'
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    last_login TIMESTAMP NULL
) ENGINE=InnoDB;

-- Inserir usuário administrador padrão (se não existir)
-- A senha padrão será 'admin123' (hash será gerado via aplicação se necessário, mas aqui deixamos um placeholder se for via SQL direto)
-- Na prática, o script de migração PHP vai inserir o admin atual do config.json
