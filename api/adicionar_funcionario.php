<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../init.php';
require_once __DIR__ . '/../classes/Funcionario.php';

verificar_admin_login();
require_csrf_token();
require_json_content_type();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['sucesso' => false, 'mensagem' => 'Método não permitido'], 405);
}

$data = json_decode(file_get_contents('php://input'), true);

if (!$data) {
    json_response(['sucesso' => false, 'mensagem' => 'Dados inválidos'], 400);
}

$id = isset($data['id']) ? intval($data['id']) : 0;
$nome = isset($data['nome']) ? trim($data['nome']) : '';
$equipe = isset($data['equipe']) ? trim($data['equipe']) : '';
$jornada_entrada = isset($data['jornada_entrada']) ? trim($data['jornada_entrada']) : '08:00';
$jornada_saida = isset($data['jornada_saida']) ? trim($data['jornada_saida']) : '17:00';
$almoco_inicio = isset($data['almoco_inicio']) ? trim($data['almoco_inicio']) : '12:00';
$almoco_fim = isset($data['almoco_fim']) ? trim($data['almoco_fim']) : '13:00';
$ativo = isset($data['ativo']) ? (bool)$data['ativo'] : true;

if ($id < 1 || $id > 999) {
    json_response(['sucesso' => false, 'mensagem' => 'ID inválido (deve estar entre 1 e 999)'], 400);
}

if (strlen($nome) < 3) {
    json_response(['sucesso' => false, 'mensagem' => 'Nome deve ter no mínimo 3 caracteres'], 400);
}

if ($equipe !== 'n1' && $equipe !== 'n2') {
    json_response(['sucesso' => false, 'mensagem' => 'Equipe inválida (deve ser n1 ou n2)'], 400);
}

// Validar formato de horários (HH:MM)
if (!preg_match('/^([01]\d|2[0-3]):([0-5]\d)$/', $jornada_entrada)) {
    json_response(['sucesso' => false, 'mensagem' => 'Horário de entrada inválido (formato: HH:MM)'], 400);
}
if (!preg_match('/^([01]\d|2[0-3]):([0-5]\d)$/', $jornada_saida)) {
    json_response(['sucesso' => false, 'mensagem' => 'Horário de saída inválido (formato: HH:MM)'], 400);
}
if (!preg_match('/^([01]\d|2[0-3]):([0-5]\d)$/', $almoco_inicio)) {
    json_response(['sucesso' => false, 'mensagem' => 'Horário de início do almoço inválido (formato: HH:MM)'], 400);
}
if (!preg_match('/^([01]\d|2[0-3]):([0-5]\d)$/', $almoco_fim)) {
    json_response(['sucesso' => false, 'mensagem' => 'Horário de fim do almoço inválido (formato: HH:MM)'], 400);
}

// Validar que entrada < saída
if ($jornada_entrada >= $jornada_saida) {
    json_response(['sucesso' => false, 'mensagem' => 'Horário de entrada deve ser anterior ao horário de saída'], 400);
}

// Validar que almoço início < almoço fim
if ($almoco_inicio >= $almoco_fim) {
    json_response(['sucesso' => false, 'mensagem' => 'Horário de início do almoço deve ser anterior ao horário de fim'], 400);
}

global $gerenciador;
if (!$gerenciador) {
    json_response(['sucesso' => false, 'mensagem' => 'Gerenciador não inicializado'], 500);
}

// Verificar se funcionário já existe
$funcs = $gerenciador->obter_funcionarios();
if (isset($funcs[$id])) {
    json_response(['sucesso' => false, 'mensagem' => 'ID já existe. Use outro ID.'], 400);
}

// Adicionar funcionário
try {
    // Carregar funcionários existentes
    $funcionarios = carregar_funcionarios_sistema();
    
    // Adicionar novo funcionário
    $funcionarios[] = [
        'id' => $id,
        'nome' => $nome,
        'equipe' => $equipe,
        'jornada_entrada' => $jornada_entrada,
        'jornada_saida' => $jornada_saida,
        'almoco_inicio' => $almoco_inicio,
        'almoco_fim' => $almoco_fim,
        'ativo' => $ativo
    ];
    
    // Ordenar por ID
    usort($funcionarios, function($a, $b) {
        return $a['id'] - $b['id'];
    });
    
    // Salvar no arquivo JSON
    salvar_funcionarios_sistema($funcionarios);
    
    // Adicionar ao gerenciador em memória
    $funcionario = new Funcionario($id, $nome, $equipe, $jornada_entrada, $jornada_saida, $almoco_inicio, $almoco_fim, $ativo);
    $gerenciador->adicionar_funcionario($funcionario);
    $gerenciador->salvar_estado();
    
    json_response([
        'sucesso' => true,
        'mensagem' => "Funcionário '{$nome}' adicionado com sucesso!"
    ]);
} catch (Exception $e) {
    json_response(['sucesso' => false, 'mensagem' => 'Erro ao adicionar funcionário: ' . $e->getMessage()], 500);
}
?>
