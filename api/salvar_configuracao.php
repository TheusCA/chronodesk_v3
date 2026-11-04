<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../init.php';

verificar_admin_login_api();
require_csrf_token();
require_json_content_type();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['sucesso' => false, 'mensagem' => 'Método não permitido'], 405);
}

$data = json_decode(file_get_contents('php://input'), true);

// [9.12.1] Corpo que nao e objeto JSON (texto, numero) e invalido: antes caia
// nos padroes e era gravado.
if (!is_array($data) || !$data) {
    json_response(['sucesso' => false, 'mensagem' => 'Dados inválidos'], 400);
}

// [9.12.1] Campo ausente no corpo mantem o valor atual (antes voltava ao
// padrao e sobrescrevia a configuracao): configuracao_pausas_atualizada().
[$config, $erro_configuracao] = configuracao_pausas_atualizada(carregar_configuracao(), $data);
if ($erro_configuracao !== null) {
    json_response(['sucesso' => false, 'mensagem' => $erro_configuracao], 400);
}
$limite_pausa = $config['limite_pausa_por_equipe'];
$duracao_minutos = $config['duracao_pausa_minutos'];

// Salvar configuração
if (salvar_configuracao($config)) {
    // Atualizar gerenciador em memória
    global $gerenciador;
    if ($gerenciador) {
        $gerenciador->atualizar_configuracoes($limite_pausa, $duracao_minutos);
    }
    audit_log('CONFIGURACAO_ATUALIZADA', "Configuração de pausas atualizada: limite={$limite_pausa}, duração={$duracao_minutos}", 'WARNING');
    
    json_response([
        'sucesso' => true,
        'mensagem' => 'Configurações salvas com sucesso!'
    ]);
} else {
    json_response(['sucesso' => false, 'mensagem' => 'Erro ao salvar configurações'], 500);
}
?>
