<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../services/EmployeeArchiveService.php';

// [F4] "Remover" e arquivar (RF-07; desenho, secao 9.6): o endereco e o corpo
// continuam os mesmos. D1: admin e Liderança, pela regra do Lote 5b; a regra
// com o perfil atual e a L9 sao conferidas no servico, com o cadastro travado.
// Inativo anterior ao F4 (sem data) e regularizado (D5); arquivado pelo F4 da 409.
$actor_role = require_portal_auth();
require_funcionario_change($actor_role, null, null, 'arquivar');
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
    $resultado = (new EmployeeArchiveService())->archive(
        (int)$funcionario_id,
        EmployeeArchiveService::sessionActor($actor_role, $_SESSION ?? [])
    );
} catch (Throwable $e) {
    [$status, $mensagem] = EmployeeArchiveService::httpError($e);
    if ($status === 403) {
        audit_log('FUNCIONARIO_ALTERACAO_NEGADA', "contexto=arquivar perfil_ator={$actor_role} funcionario_id={$funcionario_id}", 'WARNING');
    }
    json_response(['sucesso' => false, 'mensagem' => $mensagem], $status);
}

json_response([
    'sucesso' => true,
    'mensagem' => $resultado['regularizacao'] ? 'Cadastro inativo regularizado e arquivado.' : 'Funcionário arquivado.',
    'efeitos' => $resultado['efeitos'],
    'regularizacao' => $resultado['regularizacao'],
    'avisos' => $resultado['avisos'],
]);
