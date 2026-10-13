<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../services/EmployeeArchiveService.php';

// [F4] Restaurar cadastro arquivado (D12; desenho, secao 9.6): so admin
// (funcionarios.restaurar, D1). PA, escala e ausencias nao voltam.
$actor_role = require_portal_auth();
if (!portal_role_can($actor_role, 'funcionarios.restaurar')) {
    audit_log('FUNCIONARIO_ALTERACAO_NEGADA', "contexto=restaurar perfil_ator={$actor_role}", 'WARNING');
    json_response(['sucesso' => false, 'mensagem' => EmployeeArchiveService::RESTORE_DENIED_MESSAGE], 403);
}
require_csrf_token();
require_json_content_type();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['sucesso' => false, 'mensagem' => 'Método não permitido'], 405);
}

$data = json_decode(file_get_contents('php://input'), true);

if (!$data || !isset($data['funcionario_id'])) {
    json_response(['sucesso' => false, 'mensagem' => 'ID do funcionário não fornecido'], 400);
}

$funcionario_id = validate_funcionario_id($data['funcionario_id']);
if (!$funcionario_id) {
    json_response(['sucesso' => false, 'mensagem' => 'ID do funcionário inválido'], 400);
}

try {
    $resultado = (new EmployeeArchiveService())->restore(
        (int)$funcionario_id,
        EmployeeArchiveService::sessionActor($actor_role, $_SESSION ?? [])
    );
} catch (Throwable $e) {
    [$status, $mensagem] = EmployeeArchiveService::httpError($e);
    if ($status === 403) {
        audit_log('FUNCIONARIO_ALTERACAO_NEGADA', "contexto=restaurar perfil_ator={$actor_role} funcionario_id={$funcionario_id}", 'WARNING');
    }
    json_response(['sucesso' => false, 'mensagem' => $mensagem], $status);
}

json_response([
    'sucesso' => true,
    'mensagem' => 'Cadastro restaurado. PA e escala precisam ser cadastrados de novo.',
    'avisos' => $resultado['avisos'],
]);
