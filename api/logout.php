<?php
require_once __DIR__ . '/../config.php';

require_post_method();
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
