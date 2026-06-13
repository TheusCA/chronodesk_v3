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
                throw new InvalidArgumentException("Usuário inválido.");
            }
            if (!in_array($role, ['admin', 'gestor'], true)) {
                throw new InvalidArgumentException("Perfil inválido.");
            }
            if (!is_string($password) || strlen($password) < 8 || strlen($password) > 128) {
                throw new InvalidArgumentException("A senha deve ter entre 8 e 128 caracteres.");
            }
            $hash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
            if ($hash === false) {
                throw new RuntimeException("Não foi possível proteger a senha.");
            }
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
                throw new DomainException("Usuário já existe.");
            }
            throw $e;
        }
    }

    // Listar todos os usuários
    public function listar() {
        $stmt = $this->pdo->query("SELECT id, username, role, created_at, last_login FROM usuarios ORDER BY created_at DESC");
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function deletar($id) {
        return $this->deletarProtegido((int)$id) !== null;
    }

    public function deletarProtegido(int $id): ?array {
        $this->pdo->beginTransaction();
        try {
            $admins = $this->pdo
                ->query("SELECT id FROM usuarios WHERE role = 'admin' ORDER BY id FOR UPDATE")
                ->fetchAll(PDO::FETCH_COLUMN);
            $stmt = $this->pdo->prepare(
                "SELECT id, username, role FROM usuarios WHERE id = :id FOR UPDATE"
            );
            $stmt->execute([':id' => $id]);
            $target = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$target) {
                $this->pdo->commit();
                return null;
            }
            if ($target['role'] === 'admin' && count($admins) <= 1) {
                throw new DomainException('Não é possível remover o último administrador do sistema.');
            }

            $delete = $this->pdo->prepare("DELETE FROM usuarios WHERE id = :id");
            $delete->execute([':id' => $id]);
            if ($delete->rowCount() !== 1) {
                throw new RuntimeException('O usuário não pôde ser removido.');
            }
            $this->pdo->commit();
            return $target;
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    public function atualizarRole($id, $role) {
        return $this->atualizarRoleProtegido((int)$id, $role) !== null;
    }

    public function atualizarRoleProtegido(int $id, string $role): ?array {
        if (!in_array($role, ['admin', 'gestor'], true)) {
            throw new InvalidArgumentException('Perfil inválido.');
        }

        $this->pdo->beginTransaction();
        try {
            $admins = $this->pdo
                ->query("SELECT id FROM usuarios WHERE role = 'admin' ORDER BY id FOR UPDATE")
                ->fetchAll(PDO::FETCH_COLUMN);
            $stmt = $this->pdo->prepare(
                "SELECT id, username, role FROM usuarios WHERE id = :id FOR UPDATE"
            );
            $stmt->execute([':id' => $id]);
            $target = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$target) {
                $this->pdo->commit();
                return null;
            }
            if ($target['role'] === 'admin' && $role !== 'admin' && count($admins) <= 1) {
                throw new DomainException('Não é possível remover o perfil do último administrador.');
            }

            if ($target['role'] !== $role) {
                $update = $this->pdo->prepare("UPDATE usuarios SET role = :role WHERE id = :id");
                $update->execute([':role' => $role, ':id' => $id]);
            }
            $this->pdo->commit();
            $target['new_role'] = $role;
            return $target;
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    public function atualizarSenhaPorUsername($username, $password) {
        $username = normalizar_samaccountname($username);
        if ($username === null) {
            throw new InvalidArgumentException('Usuário inválido.');
        }
        if (!is_string($password) || strlen($password) < 8 || strlen($password) > 128) {
            throw new InvalidArgumentException('A senha deve ter entre 8 e 128 caracteres.');
        }
        $hash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
        if ($hash === false) {
            throw new RuntimeException('Não foi possível proteger a senha.');
        }
        $stmt = $this->pdo->prepare(
            'UPDATE usuarios SET password_hash = :password_hash WHERE username = :username'
        );
        $stmt->execute([
            ':password_hash' => $hash,
            ':username' => $username,
        ]);
        return $stmt->rowCount() > 0;
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
