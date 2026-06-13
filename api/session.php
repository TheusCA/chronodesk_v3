<?php
require_once __DIR__ . '/../config.php';

require_get_method();

$ci_authenticated = ci_session_is_current();
$gestor_authenticated = usuario_pode_acessar_metricas();
$role = current_portal_role();

json_response([
    'sucesso' => true,
    'csrf_token' => generate_csrf_token(),
    'role' => $role,
    'permissions' => portal_permissions_for_role($role),
    'ci' => [
        'autenticado' => $ci_authenticated,
        'funcionario_id' => $ci_authenticated ? (int)($_SESSION['ci_funcionario_id'] ?? 0) : 0,
        'nome' => $ci_authenticated ? sanitize_input($_SESSION['ci_nome'] ?? '', 100) : '',
        'username' => $ci_authenticated ? sanitize_input($_SESSION['ci_username'] ?? '', 100) : '',
    ],
    'gestor' => [
        'autenticado' => $gestor_authenticated,
        'admin' => $gestor_authenticated
            && isset($_SESSION['admin_logged_in'])
            && $_SESSION['admin_logged_in'] === true,
        'username' => $gestor_authenticated ? sanitize_input(
            $_SESSION['admin_username'] ?? $_SESSION['username'] ?? '',
            100
        ) : '',
        'role' => $gestor_authenticated ? ($role ?? 'gestor') : null,
    ],
]);
