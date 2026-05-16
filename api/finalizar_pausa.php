<?php
/**
 * [SECURED] Finalizar Pausa
 * Correções: CSRF, validação de input, Content-Type
 */
require_once __DIR__ . '/../init.php';
header('Content-Type: application/json; charset=utf-8');

require_csrf_token();
require_json_content_type();

global $gerenciador;
$data = json_decode(file_get_contents('php://input'), true);

$funcionario_id = validate_funcionario_id($data['funcionario_id'] ?? null);
if (!$funcionario_id) {
    json_response(["sucesso" => false, "mensagem" => "ID do funcionário inválido."], 400);
}

$resultado = $gerenciador->finalizar_pausa($funcionario_id);
json_response($resultado);
