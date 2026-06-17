<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../init.php';
require_once __DIR__ . '/../db.php';

verificar_admin_login_api();
require_csrf_token();
require_json_content_type();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['sucesso' => false, 'mensagem' => 'Método não permitido'], 405);
}

$data = json_decode(file_get_contents('php://input'), true);

if (!$data || !isset($data['funcionario_id'])) {
    json_response(['sucesso' => false, 'mensagem' => 'Dados inválidos'], 400);
}

$funcionario_id = validate_funcionario_id($data['funcionario_id']);
if (!$funcionario_id) {
    json_response(['sucesso' => false, 'mensagem' => 'ID do funcionário inválido'], 400);
}
$nome = validate_nome($data['nome'] ?? '', 3, 100);
$equipe = validate_funcionario_equipe($data['equipe'] ?? '');
$access_role = validate_access_role($data['access_role'] ?? 'tecnico');
$ad_login = validate_ad_login($data['ad_login'] ?? null);
$jornada_entrada = isset($data['jornada_entrada']) ? trim($data['jornada_entrada']) : '08:00';
$jornada_saida = isset($data['jornada_saida']) ? trim($data['jornada_saida']) : '17:00';
$almoco_inicio = isset($data['almoco_inicio']) ? trim($data['almoco_inicio']) : '12:00';
$almoco_fim = isset($data['almoco_fim']) ? trim($data['almoco_fim']) : '13:00';
$ativo = isset($data['ativo']) ? (bool)$data['ativo'] : true;

if ($nome === false) {
    json_response(['sucesso' => false, 'mensagem' => 'Nome inválido. Use de 3 a 100 caracteres válidos.'], 400);
}

if ($equipe === null) {
    json_response(['sucesso' => false, 'mensagem' => 'Equipe inválida. Use N1, N2 ou Liderança.'], 400);
}

if ($access_role === null) {
    json_response(['sucesso' => false, 'mensagem' => 'Perfil de acesso inválido.'], 400);
}

if ($equipe === 'lideranca') {
    $access_role = 'admin';
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

if ($ativo && $ad_login !== null && funcionario_ad_login_ativo_existe($ad_login, $funcionario_id)) {
    json_response(['sucesso' => false, 'mensagem' => 'Login AD já vinculado a outro funcionário ativo.'], 400);
}

try {
    $pdo = get_db_connection();
    $currentStmt = $pdo->prepare('SELECT access_role FROM funcionarios WHERE id = :id LIMIT 1');
    $currentStmt->execute([':id' => $funcionario_id]);
    $currentRole = $currentStmt->fetchColumn();
    if ($currentRole === false) {
        json_response(['sucesso' => false, 'mensagem' => 'Funcionário não encontrado.'], 404);
    }
    $stmt = $pdo->prepare(
        "UPDATE funcionarios
         SET nome = :nome,
             equipe = :equipe,
             access_role = :access_role,
             ad_login = :ad_login,
             jornada_entrada = :jornada_entrada,
             jornada_saida = :jornada_saida,
             almoco_inicio = :almoco_inicio,
             almoco_fim = :almoco_fim,
             ativo = :ativo
         WHERE id = :id"
    );
    $stmt->execute([
        ':id' => $funcionario_id,
        ':nome' => $nome,
        ':equipe' => $equipe,
        ':access_role' => $access_role,
        ':ad_login' => $ad_login,
        ':jornada_entrada' => formatar_hora_mysql($jornada_entrada, '08:00:00'),
        ':jornada_saida' => formatar_hora_mysql($jornada_saida, '17:00:00'),
        ':almoco_inicio' => formatar_hora_mysql($almoco_inicio, '12:00:00'),
        ':almoco_fim' => formatar_hora_mysql($almoco_fim, '13:00:00'),
        ':ativo' => $ativo ? 1 : 0,
    ]);

    if ($stmt->rowCount() === 0 && !$gerenciador->getFuncionario($funcionario_id)) {
        json_response(['sucesso' => false, 'mensagem' => 'Funcionário não encontrado.'], 404);
    }

    $resultado = $gerenciador->atualizar_funcionario($funcionario_id, $nome, $equipe, $jornada_entrada, $jornada_saida, $almoco_inicio, $almoco_fim, $ativo, $ad_login, $access_role);
} catch (Exception $e) {
    error_log('[FUNCIONARIO] Erro ao atualizar funcionário: ' . $e->getMessage());
    json_response(['sucesso' => false, 'mensagem' => public_error_message($e, 'Erro ao atualizar funcionário.')], 500);
}

if (($resultado['sucesso'] ?? false) === true) {
    audit_log('FUNCIONARIO_ATUALIZADO', "Funcionario '{$nome}' atualizado (ID: {$funcionario_id}, equipe: {$equipe}, perfil: {$access_role}, ativo: " . ($ativo ? 'sim' : 'nao') . ")", 'WARNING');
    if ((string)$currentRole !== $access_role) {
        audit_log('FUNCIONARIO_PERFIL_ALTERADO', "Perfil do funcionario ID {$funcionario_id} alterado de {$currentRole} para {$access_role}", 'CRITICAL');
    }
}

json_response($resultado);
?>
