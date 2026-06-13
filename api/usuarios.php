<?php
/**
 * CRUD de usuarios locais, restrito a administradores.
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../classes/Usuario.php';

verificar_admin_login_api();

$action = $_GET['action'] ?? '';

try {
    if (!ENABLE_LOCAL_ADMIN) {
        json_response([
            'sucesso' => false,
            'mensagem' => 'Gerenciamento de usuarios locais esta desativado.',
        ], 403);
    }

    $usuarioModel = new Usuario();

    switch ($action) {
        case 'listar':
            require_get_method();
            json_response([
                'sucesso' => true,
                'usuarios' => $usuarioModel->listar(),
            ]);

        case 'criar':
            require_post_method();
            require_csrf_token();
            require_json_content_type();

            $input = json_decode(file_get_contents('php://input'), true);
            if (!is_array($input)) {
                throw new InvalidArgumentException('Dados invalidos.');
            }
            $username = sanitize_input($input['username'] ?? '', 100);
            $password = $input['password'] ?? '';
            $role = in_array($input['role'] ?? '', ['admin', 'gestor'], true)
                ? $input['role']
                : 'gestor';

            if (strlen($username) < 3) {
                throw new InvalidArgumentException('Usuario deve ter pelo menos 3 caracteres.');
            }
            if (!is_string($password) || strlen($password) < 8 || strlen($password) > 128) {
                throw new InvalidArgumentException('Senha deve ter entre 8 e 128 caracteres.');
            }

            if (!$usuarioModel->criar($username, $password, $role)) {
                throw new RuntimeException('Erro ao criar usuario.');
            }
            audit_log('USER_CREATED', "Usuario '{$username}' criado com role '{$role}'", 'WARNING');
            json_response([
                'success' => true,
                'sucesso' => true,
                'message' => 'Usuario criado com sucesso',
                'mensagem' => 'Usuario criado com sucesso',
            ], 201);

        case 'deletar':
            require_post_method();
            require_csrf_token();
            require_json_content_type();

            $input = json_decode(file_get_contents('php://input'), true);
            if (!is_array($input)) {
                throw new InvalidArgumentException('Dados invalidos.');
            }
            $id = filter_var($input['id'] ?? null, FILTER_VALIDATE_INT, [
                'options' => ['min_range' => 1],
            ]);
            if (!$id) {
                throw new InvalidArgumentException('ID invalido.');
            }

            $target = $usuarioModel->deletarProtegido((int)$id);
            if (!$target) {
                throw new InvalidArgumentException('Usuario nao encontrado.');
            }
            audit_log('USER_DELETED', "Usuario '{$target['username']}' removido", 'CRITICAL');
            json_response([
                'success' => true,
                'sucesso' => true,
                'message' => 'Usuario removido com sucesso',
                'mensagem' => 'Usuario removido com sucesso',
            ]);

        case 'atualizar_role':
            require_post_method();
            require_csrf_token();
            require_json_content_type();

            $input = json_decode(file_get_contents('php://input'), true);
            if (!is_array($input)) {
                throw new InvalidArgumentException('Dados invalidos.');
            }
            $id = filter_var($input['id'] ?? null, FILTER_VALIDATE_INT, [
                'options' => ['min_range' => 1],
            ]);
            $role = $input['role'] ?? '';
            if (!$id || !in_array($role, ['admin', 'gestor'], true)) {
                throw new InvalidArgumentException('Usuario ou perfil invalido.');
            }

            $target = $usuarioModel->atualizarRoleProtegido((int)$id, $role);
            if (!$target) {
                throw new InvalidArgumentException('Usuario nao encontrado.');
            }
            audit_log('USER_ROLE_UPDATED', "Perfil do usuario ID {$id} alterado para '{$role}'", 'WARNING');
            json_response([
                'success' => true,
                'sucesso' => true,
                'message' => 'Perfil atualizado com sucesso',
                'mensagem' => 'Perfil atualizado com sucesso',
            ]);

        default:
            throw new InvalidArgumentException('Acao invalida.');
    }
} catch (DomainException $e) {
    json_response([
        'sucesso' => false,
        'error' => $e->getMessage(),
        'mensagem' => $e->getMessage(),
    ], 409);
} catch (InvalidArgumentException $e) {
    json_response([
        'sucesso' => false,
        'error' => $e->getMessage(),
        'mensagem' => $e->getMessage(),
    ], 400);
} catch (Throwable $e) {
    error_log('[USUARIOS] Falha na acao ' . sanitize_input($action, 50));
    $message = public_error_message($e, 'Nao foi possivel processar a solicitacao.');
    json_response([
        'sucesso' => false,
        'error' => $message,
        'mensagem' => $message,
    ], 500);
}
