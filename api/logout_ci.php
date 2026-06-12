<?php
require_once __DIR__ . '/../config.php';
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['sucesso' => false, 'mensagem' => 'Método não permitido.'], 405);
}

require_csrf_token();
require_json_content_type();

if (isset($_SESSION['ci_logged_in']) && $_SESSION['ci_logged_in'] === true) {
    audit_log('LOGOUT', 'Logout CI para funcionario ID ' . (int)($_SESSION['ci_funcionario_id'] ?? 0), 'INFO');
}

clear_ci_session();
session_regenerate_id(true);

json_response(['sucesso' => true, 'mensagem' => 'Sessão CI encerrada.']);
