<?php
/**
 * [SECURED] Rejeitar Pausa
 * Correções: Autenticação obrigatória, CSRF, validação
 */
require_once __DIR__ . '/../init.php';
header('Content-Type: application/json; charset=utf-8');

verificar_login();
require_csrf_token();
require_json_content_type();

global $gerenciador;
$data = json_decode(file_get_contents('php://input'), true);

$funcionario_id = validate_funcionario_id($data['funcionario_id'] ?? null);
if (!$funcionario_id) {
    json_response(["sucesso" => false, "mensagem" => "ID do funcionário inválido."], 400);
}

$funcionario = $gerenciador->getFuncionario($funcionario_id);
if (!$funcionario) {
    json_response(["sucesso" => false, "mensagem" => "Funcionário não encontrado."], 404);
}

if ($funcionario->status_aprovacao != "pendente") {
    json_response(["sucesso" => false, "mensagem" => "Não há solicitação pendente para este funcionário."], 400);
}

$funcionario->status_aprovacao = "rejeitado";
$funcionario->em_pausa = false;
$funcionario->inicio_pausa = null;
$funcionario->motivo_pausa = null;
$funcionario->solicitacao_timestamp = null;
$funcionario->observacao_reuniao = null;
$gerenciador->salvar_estado();

audit_log('PAUSA_REJEITADA', "Pausa rejeitada para {$funcionario->nome} (ID: {$funcionario_id})", 'INFO');

json_response(["sucesso" => true, "mensagem" => "Pausa de reunião rejeitada para {$funcionario->nome}."]);
