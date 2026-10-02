<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../init.php';
require_once __DIR__ . '/../db.php';

// [Lote 5b] Admin e Liderança, com a regra de perfis de 2.3 do desenho.
$actor_role = require_portal_auth();
require_funcionario_change($actor_role, null, null, 'remover');
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

global $gerenciador;
if (!$gerenciador) {
    json_response(['sucesso' => false, 'mensagem' => 'Gerenciador não inicializado'], 500);
}

try {
    $pdo = get_db_connection();
    $currentStmt = $pdo->prepare('SELECT access_role FROM funcionarios WHERE id = :id LIMIT 1');
    $currentStmt->execute([':id' => $funcionario_id]);
    $currentRole = $currentStmt->fetchColumn();
    if ($currentRole === false) {
        json_response(['sucesso' => false, 'mensagem' => 'Funcionário não encontrado.'], 404);
    }
    require_funcionario_change($actor_role, (string)$currentRole, null, 'remover');
    $stmt = $pdo->prepare("UPDATE funcionarios SET ativo = 0 WHERE id = :id");
    $stmt->execute([':id' => $funcionario_id]);
} catch (Exception $e) {
    error_log('[FUNCIONARIO] Erro ao desativar funcionário: ' . $e->getMessage());
    json_response(['sucesso' => false, 'mensagem' => public_error_message($e, 'Erro ao desativar funcionário.')], 500);
}

$resultado = $gerenciador->remover_funcionario($funcionario_id);

if (($resultado['sucesso'] ?? false) === true) {
    audit_log('FUNCIONARIO_REMOVIDO', "Funcionario desativado (ID: {$funcionario_id}, perfil: {$currentRole}) por {$actor_role}", 'CRITICAL');
}

json_response($resultado);
?>
