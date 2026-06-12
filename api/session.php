<?php
require_once __DIR__ . '/../config.php';

require_get_method();

json_response([
    'sucesso' => true,
    'csrf_token' => generate_csrf_token(),
    'ci' => [
        'autenticado' => isset($_SESSION['ci_logged_in']) && $_SESSION['ci_logged_in'] === true,
        'funcionario_id' => (int)($_SESSION['ci_funcionario_id'] ?? 0),
        'nome' => sanitize_input($_SESSION['ci_nome'] ?? '', 100),
        'username' => sanitize_input($_SESSION['ci_username'] ?? '', 100),
    ],
    'gestor' => [
        'autenticado' => usuario_pode_acessar_metricas(),
        'admin' => isset($_SESSION['admin_logged_in']) && $_SESSION['admin_logged_in'] === true,
        'username' => sanitize_input(
            $_SESSION['admin_username'] ?? $_SESSION['username'] ?? '',
            100
        ),
    ],
]);
