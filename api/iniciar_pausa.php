<?php
/**
 * [SECURED] Iniciar Pausa
 * Correções: CSRF, validação de input, Content-Type
 */
require_once __DIR__ . '/../init.php';
require_once __DIR__ . '/../auth_ldap.php';
header('Content-Type: application/json; charset=utf-8');

// [VULN-005] Verificar CSRF
require_csrf_token();
// [VULN-022] Validar Content-Type
require_json_content_type();

global $gerenciador;
$data = json_decode(file_get_contents('php://input'), true);
if (!is_array($data)) {
    json_response(["sucesso" => false, "mensagem" => "Dados inválidos."], 400);
}

// [VULN-019] Validação rigorosa
$funcionario_id = validate_funcionario_id($data['funcionario_id'] ?? null);
if (!$funcionario_id) {
    json_response(["sucesso" => false, "mensagem" => "ID do funcionário inválido."], 400);
}

$motivo = validate_motivo_pausa($data['motivo_pausa'] ?? '');
if (!$motivo) {
    json_response(["sucesso" => false, "mensagem" => "Motivo de pausa inválido. Use: Café, Pessoal ou Reunião."], 400);
}

exigir_autenticacao_ci_pausa($data, $funcionario_id, 'iniciar_pausa');

$resultado = $gerenciador->iniciar_pausa($funcionario_id, $motivo);
json_response($resultado);
