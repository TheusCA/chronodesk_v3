<?php
require_once __DIR__ . '/../init.php';
require_once __DIR__ . '/../services/ApprovalRequestService.php';

require_post_method();
require_portal_auth(['admin', 'gestor']);
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
$funcionario = $gerenciador->getFuncionario($funcionario_id);
if (!$funcionario) {
    json_response(['sucesso' => false, 'mensagem' => 'Funcionário não encontrado.'], 404);
}

$resultado = with_pause_state_lock(function () use ($gerenciador, $funcionario_id) {
    $gerenciador->carregar_estado();
    return $gerenciador->rejeitar_pausa($funcionario_id);
});
if ($resultado['sucesso']) {
    audit_log('PAUSE_REJECT', 'Pausa rejeitada para funcionário ID ' . $funcionario_id, 'INFO');
    (new ApprovalRequestService())->decide(
        $funcionario_id,
        'rejected',
        sanitize_input($_SESSION['admin_username'] ?? $_SESSION['username'] ?? 'gestor', 100)
    );
}

json_response($resultado, $resultado['sucesso'] ? 200 : 409);
