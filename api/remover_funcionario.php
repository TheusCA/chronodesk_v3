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

$funcionario_id = intval($data['funcionario_id']);

global $gerenciador;
if (!$gerenciador) {
    json_response(['sucesso' => false, 'mensagem' => 'Gerenciador não inicializado'], 500);
}

try {
    $pdo = get_db_connection();
    $stmt = $pdo->prepare("UPDATE funcionarios SET ativo = 0 WHERE id = :id");
    $stmt->execute([':id' => $funcionario_id]);
} catch (Exception $e) {
    json_response(['sucesso' => false, 'mensagem' => 'Erro ao desativar funcionário: ' . $e->getMessage()], 500);
}

$resultado = $gerenciador->remover_funcionario($funcionario_id);

json_response($resultado);
?>
