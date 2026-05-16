<?php
/**
 * [SECURED] Aprovar Pausa
 * Correções: Autenticação obrigatória, CSRF, validação
 */
require_once __DIR__ . '/../init.php';
header('Content-Type: application/json; charset=utf-8');

// [VULN-003] Exigir autenticação para aprovar pausas
verificar_login();
require_csrf_token();
require_json_content_type();

global $gerenciador;
$data = json_decode(file_get_contents('php://input'), true);

$funcionario_id = validate_funcionario_id($data['funcionario_id'] ?? null);
if (!$funcionario_id) {
    json_response(["sucesso" => false, "mensagem" => "ID do funcionário inválido."], 400);
}

$funcionario = $gerenciador->getFuncionario($funcionario_id);
if (!$funcionario) {
    json_response(["sucesso" => false, "mensagem" => "Funcionário não encontrado."], 404);
}

if ($funcionario->status_aprovacao != "pendente") {
    json_response(["sucesso" => false, "mensagem" => "Não há solicitação pendente para este funcionário."], 400);
}

// Verificar limite de pausas ativas na equipe
$pausas_cafe_equipe = 0;
foreach ($gerenciador->getFuncionarios() as $f) {
    if ($f->equipe == $funcionario->equipe && $f->em_pausa && $f->motivo_pausa == "Café" && $f->status_aprovacao == "aprovado") {
        $pausas_cafe_equipe++;
    }
}

if ($funcionario->motivo_pausa == "Café" && $pausas_cafe_equipe >= $gerenciador->limite_pausa_por_equipe) {
    json_response(["sucesso" => false, "mensagem" => "Limite de pausas de café atingido para a equipe {$funcionario->equipe}."], 400);
}

$funcionario->status_aprovacao = "aprovado";
$gerenciador->salvar_estado();

// [VULN-017] Auditoria
audit_log('PAUSA_APROVADA', "Pausa de {$funcionario->motivo_pausa} aprovada para {$funcionario->nome} (ID: {$funcionario_id})", 'INFO');

json_response(["sucesso" => true, "mensagem" => "Pausa de {$funcionario->motivo_pausa} aprovada para {$funcionario->nome}."]);
