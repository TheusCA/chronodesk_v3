<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../init.php';
require_once __DIR__ . '/../db.php';

verificar_admin_login();
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
    $stmt = $pdo->prepare("UPDATE funcionarios SET ativo = 0 WHERE id = :id");
    $stmt->execute([':id' => $funcionario_id]);
} catch (Exception $e) {
    error_log('[FUNCIONARIO] Erro ao desativar funcionário: ' . $e->getMessage());
    json_response(['sucesso' => false, 'mensagem' => public_error_message($e, 'Erro ao desativar funcionário.')], 500);
}

$resultado = $gerenciador->remover_funcionario($funcionario_id);

if (($resultado['sucesso'] ?? false) === true) {
    audit_log('FUNCIONARIO_REMOVIDO', "Funcionario desativado (ID: {$funcionario_id})", 'CRITICAL');
}

json_response($resultado);
?>
