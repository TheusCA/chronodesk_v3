<?php
require_once __DIR__ . '/../init.php';

verificar_login_api();
require_csrf_token();
require_json_content_type();

$data = json_decode(file_get_contents('php://input'), true);
if (!is_array($data)) {
    json_response(['sucesso' => false, 'mensagem' => 'Dados inválidos.'], 400);
}

$funcionario_id = validate_funcionario_id($data['funcionario_id'] ?? null);
if (!$funcionario_id) {
    json_response(['sucesso' => false, 'mensagem' => 'ID do funcionário inválido.'], 400);
}

global $gerenciador;
$resultado = $gerenciador->rejeitar_pausa($funcionario_id);
if ($resultado['sucesso']) {
    audit_log('PAUSE_REJECT', 'Pausa rejeitada para funcionário ID ' . $funcionario_id, 'INFO');
}

json_response($resultado, $resultado['sucesso'] ? 200 : 409);
