<?php
/**
 * [SECURED] Finalizar Pausa
 * Correções: CSRF, validação de input, Content-Type
 */
require_once __DIR__ . '/../init.php';
require_once __DIR__ . '/../auth_ldap.php';
header('Content-Type: application/json; charset=utf-8');

require_post_method();
require_csrf_token();
require_json_content_type();

global $gerenciador;
$data = json_decode(file_get_contents('php://input'), true);
if (!is_array($data)) {
    json_response(["sucesso" => false, "mensagem" => "Dados inválidos."], 400);
}

$funcionario_id = validate_funcionario_id($data['funcionario_id'] ?? null);
if (!$funcionario_id) {
    json_response(["sucesso" => false, "mensagem" => "ID do funcionário inválido."], 400);
}

exigir_autenticacao_ci_pausa($data, $funcionario_id, 'finalizar_pausa');

$resultado = with_pause_state_lock(function () use ($gerenciador, $funcionario_id) {
    $gerenciador->carregar_estado();
    return $gerenciador->finalizar_pausa($funcionario_id);
});
if ($resultado['sucesso']) {
    audit_log('PAUSE_FINISH', 'Pausa finalizada para funcionário ID ' . $funcionario_id, 'INFO');
}
json_response($resultado, $resultado['sucesso'] ? 200 : 409);
