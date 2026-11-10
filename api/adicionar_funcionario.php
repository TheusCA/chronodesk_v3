<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../init.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../classes/Funcionario.php';

// [Lote 5b] Admin e Liderança, com a regra de perfis de 2.3 do desenho.
$actor_role = require_portal_auth();
require_funcionario_change($actor_role, null, null, 'adicionar');
require_csrf_token();
require_json_content_type();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['sucesso' => false, 'mensagem' => 'Método não permitido'], 405);
}

$data = json_decode(file_get_contents('php://input'), true);

if (!$data) {
    json_response(['sucesso' => false, 'mensagem' => 'Dados inválidos'], 400);
}

// [pos-deploy 1, D1] O ID vem do banco (AUTO_INCREMENT). Um 'id' enviado por
// cliente antigo e ignorado (nao recusado); a auditoria registra so que veio.
$id_recebido_ignorado = array_key_exists('id', $data);
$nome = validate_nome($data['nome'] ?? '', 3, 100);
$equipe = validate_funcionario_equipe($data['equipe'] ?? '');
$access_role = validate_access_role($data['access_role'] ?? 'tecnico');
$ad_login = validate_ad_login($data['ad_login'] ?? null);
$jornada_entrada = isset($data['jornada_entrada']) ? trim($data['jornada_entrada']) : '08:00';
$jornada_saida = isset($data['jornada_saida']) ? trim($data['jornada_saida']) : '17:00';
$almoco_inicio = isset($data['almoco_inicio']) ? trim($data['almoco_inicio']) : '12:00';
$almoco_fim = isset($data['almoco_fim']) ? trim($data['almoco_fim']) : '13:00';
// [F4, D4] Cadastro novo e sempre ativo; arquivar e outra acao.
$ativo_input = array_key_exists('ativo', $data) ? $data['ativo'] : true;
if (!in_array($ativo_input, [true, false, 0, 1, '0', '1'], true)) {
    json_response(['sucesso' => false, 'mensagem' => 'Valor inválido para a situação do cadastro.'], 400);
}
if (!in_array($ativo_input, [true, 1, '1'], true)) {
    json_response(['sucesso' => false, 'mensagem' => 'Cadastro novo é sempre ativo. Para arquivar, use Arquivar depois de salvar.'], 400);
}
$ativo = true;

if ($nome === false) {
    json_response(['sucesso' => false, 'mensagem' => 'Nome inválido. Use de 3 a 100 caracteres válidos.'], 400);
}

if ($equipe === null) {
    json_response(['sucesso' => false, 'mensagem' => 'Equipe inválida. Use N1, N2 ou Liderança.'], 400);
}

if ($access_role === null) {
    json_response(['sucesso' => false, 'mensagem' => 'Perfil de acesso inválido.'], 400);
}

require_funcionario_change($actor_role, null, $access_role, 'adicionar');

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

// [D1] Sem a checagem "ID ja existe": ela so olhava a lista em memoria e
// deixava o ID de um arquivado estourar como 500 na chave primaria.
if ($ad_login !== null && funcionario_ad_login_em_uso($ad_login)) {
    json_response(['sucesso' => false, 'mensagem' => 'Login AD já vinculado a outro funcionário (ativo ou inativo).'], 400);
}

// Adicionar funcionário: INSERT sem a coluna id; o ID e o lastInsertId(),
// conferido dentro da transacao (D2: acima da faixa aceita hoje, desfaz e 409).
$pdo = null;
try {
    $pdo = get_db_connection();
    $pdo->beginTransaction();
    $stmt = $pdo->prepare(
        "INSERT INTO funcionarios (
            nome, equipe, access_role, ad_login, jornada_entrada, jornada_saida,
            almoco_inicio, almoco_fim, ativo
        ) VALUES (
            :nome, :equipe, :access_role, :ad_login, :jornada_entrada, :jornada_saida,
            :almoco_inicio, :almoco_fim, :ativo
        )"
    );
    $stmt->execute([
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
    $id = filter_var($pdo->lastInsertId(), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if ($id === false) {
        $pdo->rollBack();
        error_log('[FUNCIONARIO] lastInsertId invalido na criacao; INSERT desfeito');
        json_response(['sucesso' => false, 'mensagem' => 'Erro ao adicionar funcionário.'], 500);
    }
    // [D2] Os outros endpoints aceitam so de 1 a 999 (validate_funcionario_id):
    // um cadastro acima disso nao poderia ser editado, arquivado nem pausar.
    if (validate_funcionario_id($id) === null) {
        $pdo->rollBack();
        audit_log('FUNCIONARIO_ID_FORA_DA_FAIXA', "Criacao desfeita: o banco gerou o ID {$id}, acima da faixa aceita (1 a 999)", 'WARNING');
        json_response(['sucesso' => false, 'mensagem' => "O banco gerou o ID {$id}, acima da faixa aceita hoje (1 a 999). Nada foi gravado. Avise o administrador do sistema."], 409);
    }
    $pdo->commit();

    // Adicionar ao gerenciador em memória
    $funcionario = new Funcionario($id, $nome, $equipe, $jornada_entrada, $jornada_saida, $almoco_inicio, $almoco_fim, $ativo, $ad_login, $access_role);
    $gerenciador->adicionar_funcionario($funcionario);
    // [F4, L-11] Sem salvar_estado(): cadastro novo nao tem pausa, e regravar o
    // estado.json daqui, fora do lock, podia desfazer uma pausa em andamento.
    // [D4] Nada e gravado no funcionarios.json na criacao: o ID do cadastro e
    // so o do banco.
    audit_log('FUNCIONARIO_ADICIONADO', "Funcionario '{$nome}' adicionado (ID: {$id}, equipe: {$equipe}, perfil: {$access_role})", 'WARNING');
    if ($id_recebido_ignorado) {
        audit_log('FUNCIONARIO_ID_IGNORADO', "Criacao do funcionario ID {$id}: o campo id veio na requisicao e foi ignorado (o ID vem do banco)", 'INFO');
    }
    if (funcionario_role_is_elevated($access_role)) {
        audit_log('FUNCIONARIO_PERFIL_ALTERADO', "Funcionario ID {$id} criado com perfil {$access_role} por {$actor_role}", 'CRITICAL');
    }

    json_response([
        'sucesso' => true,
        'mensagem' => "Funcionário '{$nome}' adicionado com o ID {$id}.",
        'funcionario' => ['id' => $id],
    ]);
} catch (Exception $e) {
    if ($pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('[FUNCIONARIO] Erro ao adicionar funcionário: ' . $e->getMessage());
    json_response(['sucesso' => false, 'mensagem' => public_error_message($e, 'Erro ao adicionar funcionário.')], 500);
}
?>
