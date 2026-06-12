<?php
require_once __DIR__ . '/../db.php';

class Usuario {
    private $pdo;

    public function __construct() {
        $this->pdo = get_db_connection();
    }

    // Criar novo usuário
    public function criar($username, $password, $role = 'gestor') {
        try {
            $username = normalizar_samaccountname($username);
            if ($username === null) {
                throw new Exception("Usuário inválido.");
            }
            $hash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
            $sql = "INSERT INTO usuarios (username, password_hash, role) VALUES (:username, :hash, :role)";
            $stmt = $this->pdo->prepare($sql);
            return $stmt->execute([
                ':username' => $username,
                ':hash' => $hash,
                ':role' => $role
            ]);
        } catch (PDOException $e) {
            // Código 23000 é violação de constraint UNIQUE (usuário duplicado)
            if ($e->getCode() == 23000) {
                throw new Exception("Usuário já existe.");
            }
            throw $e;
        }
    }

    // Listar todos os usuários
    public function listar() {
        $stmt = $this->pdo->query("SELECT id, username, role, created_at, last_login FROM usuarios ORDER BY created_at DESC");
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // Deletar usuário (exceto o admin principal se for o caso, mas deixarei livre por enquanto)
    public function deletar($id) {
        $sql = "DELETE FROM usuarios WHERE id = :id";
        $stmt = $this->pdo->prepare($sql);
        return $stmt->execute([':id' => $id]);
    }

    // Autenticar usuário
    public function autenticar($username, $password) {
        $username = normalizar_samaccountname($username);
        if ($username === null) {
            return false;
        }
        $sql = "SELECT id, username, password_hash, role FROM usuarios WHERE username = :username LIMIT 1";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':username' => $username]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($user && password_verify($password, $user['password_hash'])) {
            // Atualizar last_login
            $updateSql = "UPDATE usuarios SET last_login = NOW() WHERE id = :id";
            $updateStmt = $this->pdo->prepare($updateSql);
            $updateStmt->execute([':id' => $user['id']]);
            
            return $user; // Retorna dados do usuário (exceto senha)
        }

        return false;
    }
    
    // Verificar se existe algum usuário cadastrado
    public function existeUsuarios() {
        $stmt = $this->pdo->query("SELECT COUNT(*) FROM usuarios");
        return $stmt->fetchColumn() > 0;
    }
}
