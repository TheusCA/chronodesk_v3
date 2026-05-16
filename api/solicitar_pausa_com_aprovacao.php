<?php
/**
 * [SECURED] Solicitar Pausa com Aprovação
 * Correções: CSRF, validação, limite de observação
 */
require_once __DIR__ . '/../init.php';
header('Content-Type: application/json; charset=utf-8');

require_csrf_token();
require_json_content_type();

global $gerenciador;
$data = json_decode(file_get_contents('php://input'), true);

$funcionario_id = validate_funcionario_id($data['funcionario_id'] ?? null);
if (!$funcionario_id) {
    json_response(["sucesso" => false, "mensagem" => "ID do funcionário inválido."], 400);
}

$motivo = validate_motivo_pausa($data['motivo_pausa'] ?? '');
if (!$motivo) {
    json_response(["sucesso" => false, "mensagem" => "Motivo inválido."], 400);
}

// Limitar tamanho da observação
$observacao = sanitize_input($data['observacao'] ?? '', 500);

$funcionario = $gerenciador->getFuncionario($funcionario_id);
if (!$funcionario) {
    json_response(["sucesso" => false, "mensagem" => "Funcionário não encontrado."], 404);
}

if ($funcionario->em_pausa) {
    json_response(["sucesso" => false, "mensagem" => "{$funcionario->nome} já está em pausa."], 400);
}

$resultado = $gerenciador->iniciar_pausa($funcionario_id, $motivo);

if ($resultado["sucesso"] && $motivo == "Reunião") {
    $funcionario = $gerenciador->getFuncionario($funcionario_id);
    if ($funcionario) {
        $funcionario->observacao_reuniao = $observacao;
        $gerenciador->salvar_estado();
        if (!empty($observacao)) {
            $resultado["mensagem"] .= " Observação: {$observacao}";
        }
    }
}

json_response($resultado);
