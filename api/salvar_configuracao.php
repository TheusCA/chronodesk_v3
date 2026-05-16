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

if (!$data) {
    json_response(['sucesso' => false, 'mensagem' => 'Dados inválidos'], 400);
}

// Validar dados
$limite_pausa = isset($data['limite_pausa_por_equipe']) ? intval($data['limite_pausa_por_equipe']) : 2;
$duracao_minutos = isset($data['duracao_pausa_minutos']) ? intval($data['duracao_pausa_minutos']) : 20;
$alerta_15min = isset($data['alerta_15minutos']) ? (bool)$data['alerta_15minutos'] : true;
$alerta_20min = isset($data['alerta_20minutos']) ? (bool)$data['alerta_20minutos'] : true;

if ($limite_pausa < 1 || $limite_pausa > 10) {
    json_response(['sucesso' => false, 'mensagem' => 'Limite de pausa deve estar entre 1 e 10'], 400);
}

if ($duracao_minutos < 5 || $duracao_minutos > 60) {
    json_response(['sucesso' => false, 'mensagem' => 'Duração da pausa deve estar entre 5 e 60 minutos'], 400);
}

// Carregar configuração atual
$config = carregar_configuracao();

// Atualizar configurações
$config['limite_pausa_por_equipe'] = $limite_pausa;
$config['duracao_pausa_minutos'] = $duracao_minutos;
$config['alerta_15minutos'] = $alerta_15min;
$config['alerta_20minutos'] = $alerta_20min;

// Salvar configuração
if (salvar_configuracao($config)) {
    // Atualizar gerenciador em memória
    global $gerenciador;
    if ($gerenciador) {
        $gerenciador->atualizar_configuracoes($limite_pausa, $duracao_minutos);
    }
    
    json_response([
        'sucesso' => true,
        'mensagem' => 'Configurações salvas com sucesso!'
    ]);
} else {
    json_response(['sucesso' => false, 'mensagem' => 'Erro ao salvar configurações'], 500);
}
?>
