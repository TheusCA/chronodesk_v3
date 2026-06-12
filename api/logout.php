<?php
require_once __DIR__ . '/../config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['sucesso' => false, 'mensagem' => 'Método não permitido.'], 405);
}

require_csrf_token();
require_json_content_type();

if (
    (isset($_SESSION['ci_logged_in']) && $_SESSION['ci_logged_in'] === true) ||
    usuario_pode_acessar_metricas()
) {
    audit_log('LOGOUT', 'Logout pela interface React', 'INFO');
}

destroy_current_session();
secure_session_start();

json_response([
    'sucesso' => true,
    'mensagem' => 'Sessão encerrada.',
    'csrf_token' => generate_csrf_token(),
]);
