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
    json_response(['sucesso' => false, 'mensagem' => 'Dados inválidos'], 400);
}

$funcionario_id = intval($data['funcionario_id']);
$nome = isset($data['nome']) ? trim($data['nome']) : '';
$equipe = isset($data['equipe']) ? trim($data['equipe']) : '';
$jornada_entrada = isset($data['jornada_entrada']) ? trim($data['jornada_entrada']) : '08:00';
$jornada_saida = isset($data['jornada_saida']) ? trim($data['jornada_saida']) : '17:00';
$almoco_inicio = isset($data['almoco_inicio']) ? trim($data['almoco_inicio']) : '12:00';
$almoco_fim = isset($data['almoco_fim']) ? trim($data['almoco_fim']) : '13:00';
$ativo = isset($data['ativo']) ? (bool)$data['ativo'] : true;

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

// Atualizar no arquivo JSON
$funcionarios = carregar_funcionarios_sistema();
foreach ($funcionarios as &$func) {
    if ($func['id'] == $funcionario_id) {
        $func['nome'] = $nome;
        $func['equipe'] = $equipe;
        $func['jornada_entrada'] = $jornada_entrada;
        $func['jornada_saida'] = $jornada_saida;
        $func['almoco_inicio'] = $almoco_inicio;
        $func['almoco_fim'] = $almoco_fim;
        $func['ativo'] = $ativo;
        break;
    }
}
unset($func); // Liberar referência
salvar_funcionarios_sistema($funcionarios);

// Atualizar no gerenciador
$resultado = $gerenciador->atualizar_funcionario($funcionario_id, $nome, $equipe, $jornada_entrada, $jornada_saida, $almoco_inicio, $almoco_fim, $ativo);

json_response($resultado);
?>
