<?php
/**
 * [SECURED] Solicitar Pausa com Aprovação
 * Correções: CSRF, validação, limite de observação
 */
require_once __DIR__ . '/../init.php';
require_once __DIR__ . '/../auth_ldap.php';
require_once __DIR__ . '/../services/NotificationService.php';
require_once __DIR__ . '/../services/ApprovalRequestService.php';
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

$motivo = validate_motivo_pausa($data['motivo_pausa'] ?? '');
if (!$motivo) {
    json_response(["sucesso" => false, "mensagem" => "Motivo inválido."], 400);
}
if ($motivo !== 'Reunião') {
    json_response(["sucesso" => false, "mensagem" => "Somente pausas de reunião usam o fluxo de aprovação."], 400);
}

// Limitar tamanho da observação
$observacao = sanitize_input($data['observacao'] ?? '', 500);

exigir_autenticacao_ci_pausa($data, $funcionario_id, 'solicitar_pausa');

$funcionario = $gerenciador->getFuncionario($funcionario_id);
if (!$funcionario) {
    json_response(["sucesso" => false, "mensagem" => "Funcionário não encontrado."], 404);
}

if ($funcionario->em_pausa) {
    json_response(["sucesso" => false, "mensagem" => "{$funcionario->nome} já está em pausa."], 400);
}

$resultado = with_pause_state_lock(function () use ($gerenciador, $funcionario_id, $motivo, $observacao) {
    $gerenciador->carregar_estado();
    return $gerenciador->solicitar_pausa($funcionario_id, $motivo, $observacao);
});

if ($resultado['sucesso']) {
    audit_log('PAUSE_REQUEST', 'Solicitação para funcionário ID ' . $funcionario_id, 'INFO');
    $funcionario_atualizado = $gerenciador->getFuncionario($funcionario_id);
    (new ApprovalRequestService())->create([
        'employee_id' => $funcionario_atualizado->id,
        'employee_name' => $funcionario_atualizado->nome,
        'team' => $funcionario_atualizado->equipe,
        'reason' => $funcionario_atualizado->motivo_pausa,
        'observation' => $funcionario_atualizado->observacao_reuniao,
        'requested_at' => $funcionario_atualizado->solicitacao_timestamp,
    ]);
    (new NotificationService())->notifyMeetingApproval([
        'employee_name' => $funcionario_atualizado->nome,
        'team' => $funcionario_atualizado->equipe,
        'reason' => $funcionario_atualizado->motivo_pausa,
        'observation' => $funcionario_atualizado->observacao_reuniao,
        'requested_at' => $funcionario_atualizado->solicitacao_timestamp,
    ]);
}
json_response($resultado, $resultado['sucesso'] ? 200 : 409);
