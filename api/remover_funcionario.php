<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../init.php';

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

// Remover do arquivo JSON
$funcionarios = carregar_funcionarios_sistema();
$funcionarios = array_filter($funcionarios, function($func) use ($funcionario_id) {
    return $func['id'] != $funcionario_id;
});
$funcionarios = array_values($funcionarios); // Reindexar array
salvar_funcionarios_sistema($funcionarios);

// Remover do gerenciador
$resultado = $gerenciador->remover_funcionario($funcionario_id);

json_response($resultado);
?>
