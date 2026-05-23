<?php
/**
 * [SECURED] CRUD de Usuários
 * Correções: Proteção contra exclusão do último admin, auditoria
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../classes/Usuario.php';

verificar_admin_login();

header('Content-Type: application/json; charset=utf-8');
$action = $_GET['action'] ?? '';

try {
    $usuarioModel = new Usuario();

    switch ($action) {
        case 'listar':
            $usuarios = $usuarioModel->listar();
            echo json_encode(['usuarios' => $usuarios]);
            break;

        case 'criar':
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') throw new Exception('Método inválido');
            require_csrf_token();
            require_json_content_type();
            
            $input = json_decode(file_get_contents('php://input'), true);
            $username = sanitize_input($input['username'] ?? '');
            $password = $input['password'] ?? '';  // NÃO sanitizar senha
            $role = in_array($input['role'] ?? '', ['admin', 'gestor']) ? $input['role'] : 'gestor';

            if (empty($username) || strlen($username) < 3) throw new Exception('Usuário deve ter pelo menos 3 caracteres');
            if (empty($password) || strlen($password) < 6) throw new Exception('Senha deve ter pelo menos 6 caracteres');

            if ($usuarioModel->criar($username, $password, $role)) {
                audit_log('USER_CREATED', "Usuário '{$username}' criado com role '{$role}'", 'WARNING');
                echo json_encode(['success' => true, 'message' => 'Usuário criado com sucesso']);
            } else {
                throw new Exception('Erro ao criar usuário');
            }
            break;

        case 'deletar':
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') throw new Exception('Método inválido');
            require_csrf_token();
            require_json_content_type();
            
            $input = json_decode(file_get_contents('php://input'), true);
            $id = intval($input['id'] ?? 0);
            if ($id <= 0) throw new Exception('ID inválido');

            // [VULN-011] Proteger contra exclusão do último admin
            $usuarios = $usuarioModel->listar();
            $admins = array_filter($usuarios, fn($u) => $u['role'] === 'admin');
            $usuario_alvo = null;
            foreach ($usuarios as $u) {
                if ($u['id'] == $id) { $usuario_alvo = $u; break; }
            }

            if ($usuario_alvo && $usuario_alvo['role'] === 'admin' && count($admins) <= 1) {
                throw new Exception('Não é possível remover o último administrador do sistema');
            }

            if ($usuarioModel->deletar($id)) {
                $nome_deletado = $usuario_alvo['username'] ?? 'ID:' . $id;
                audit_log('USER_DELETED', "Usuário '{$nome_deletado}' removido", 'CRITICAL');
                echo json_encode(['success' => true, 'message' => 'Usuário removido com sucesso']);
            } else {
                throw new Exception('Erro ao remover usuário');
            }
            break;

        default:
            throw new Exception('Ação inválida');
    }
} catch (Exception $e) {
    http_response_code(400);
    error_log('[USUARIOS] Erro na ação ' . sanitize_input($action, 50) . ': ' . $e->getMessage());
    echo json_encode(['error' => public_error_message($e, 'Não foi possível processar a solicitação.')], JSON_UNESCAPED_UNICODE);
}
