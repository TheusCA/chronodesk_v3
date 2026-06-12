<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../init.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../classes/Funcionario.php';

verificar_admin_login_api();
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
$nome = validate_nome($data['nome'] ?? '', 3, 100);
$equipe = isset($data['equipe']) ? trim($data['equipe']) : '';
$ad_login = validate_ad_login($data['ad_login'] ?? null);
$jornada_entrada = isset($data['jornada_entrada']) ? trim($data['jornada_entrada']) : '08:00';
$jornada_saida = isset($data['jornada_saida']) ? trim($data['jornada_saida']) : '17:00';
$almoco_inicio = isset($data['almoco_inicio']) ? trim($data['almoco_inicio']) : '12:00';
$almoco_fim = isset($data['almoco_fim']) ? trim($data['almoco_fim']) : '13:00';
$ativo = isset($data['ativo']) ? (bool)$data['ativo'] : true;

if ($id < 1 || $id > 999) {
    json_response(['sucesso' => false, 'mensagem' => 'ID inválido (deve estar entre 1 e 999)'], 400);
}

if ($nome === false) {
    json_response(['sucesso' => false, 'mensagem' => 'Nome inválido. Use de 3 a 100 caracteres válidos.'], 400);
}

if ($equipe !== 'n1' && $equipe !== 'n2') {
    json_response(['sucesso' => false, 'mensagem' => 'Equipe inválida (deve ser n1 ou n2)'], 400);
}

if ($ad_login === false) {
    json_response(['sucesso' => false, 'mensagem' => 'Login AD inválido. Use letras, números, ponto, hífen, underscore ou @.'], 400);
}

// Validar formato de horários (HH:MM ou HH:MM:SS)
if (!preg_match('/^([01]\d|2[0-3]):([0-5]\d)(?::[0-5]\d)?$/', $jornada_entrada)) {
    json_response(['sucesso' => false, 'mensagem' => 'Horário de entrada inválido (formato: HH:MM)'], 400);
}
if (!preg_match('/^([01]\d|2[0-3]):([0-5]\d)(?::[0-5]\d)?$/', $jornada_saida)) {
    json_response(['sucesso' => false, 'mensagem' => 'Horário de saída inválido (formato: HH:MM)'], 400);
}
if (!preg_match('/^([01]\d|2[0-3]):([0-5]\d)(?::[0-5]\d)?$/', $almoco_inicio)) {
    json_response(['sucesso' => false, 'mensagem' => 'Horário de início do almoço inválido (formato: HH:MM)'], 400);
}
if (!preg_match('/^([01]\d|2[0-3]):([0-5]\d)(?::[0-5]\d)?$/', $almoco_fim)) {
    json_response(['sucesso' => false, 'mensagem' => 'Horário de fim do almoço inválido (formato: HH:MM)'], 400);
}

$jornada_entrada = normalizar_hora_funcionario($jornada_entrada, '08:00');
$jornada_saida = normalizar_hora_funcionario($jornada_saida, '17:00');
$almoco_inicio = normalizar_hora_funcionario($almoco_inicio, '12:00');
$almoco_fim = normalizar_hora_funcionario($almoco_fim, '13:00');

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

if ($ativo && $ad_login !== null && funcionario_ad_login_ativo_existe($ad_login)) {
    json_response(['sucesso' => false, 'mensagem' => 'Login AD já vinculado a outro funcionário ativo.'], 400);
}

// Adicionar funcionário
try {
    $pdo = get_db_connection();
    $stmt = $pdo->prepare(
        "INSERT INTO funcionarios (
            id, nome, equipe, ad_login, jornada_entrada, jornada_saida,
            almoco_inicio, almoco_fim, ativo
        ) VALUES (
            :id, :nome, :equipe, :ad_login, :jornada_entrada, :jornada_saida,
            :almoco_inicio, :almoco_fim, :ativo
        )"
    );
    $stmt->execute([
        ':id' => $id,
        ':nome' => $nome,
        ':equipe' => $equipe,
        ':ad_login' => $ad_login,
        ':jornada_entrada' => formatar_hora_mysql($jornada_entrada, '08:00:00'),
        ':jornada_saida' => formatar_hora_mysql($jornada_saida, '17:00:00'),
        ':almoco_inicio' => formatar_hora_mysql($almoco_inicio, '12:00:00'),
        ':almoco_fim' => formatar_hora_mysql($almoco_fim, '13:00:00'),
        ':ativo' => $ativo ? 1 : 0,
    ]);
    
    // Adicionar ao gerenciador em memória
    $funcionario = new Funcionario($id, $nome, $equipe, $jornada_entrada, $jornada_saida, $almoco_inicio, $almoco_fim, $ativo, $ad_login);
    $gerenciador->adicionar_funcionario($funcionario);
    $gerenciador->salvar_estado();
    audit_log('FUNCIONARIO_ADICIONADO', "Funcionario '{$nome}' adicionado (ID: {$id}, equipe: {$equipe})", 'WARNING');
    
    json_response([
        'sucesso' => true,
        'mensagem' => "Funcionário '{$nome}' adicionado com sucesso!"
    ]);
} catch (Exception $e) {
    error_log('[FUNCIONARIO] Erro ao adicionar funcionário: ' . $e->getMessage());
    json_response(['sucesso' => false, 'mensagem' => public_error_message($e, 'Erro ao adicionar funcionário.')], 500);
}
?>
