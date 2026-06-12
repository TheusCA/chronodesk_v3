<?php
/**
 * [SECURED] Solicitações Pendentes
 * Correções: Autenticação obrigatória
 */
require_once __DIR__ . '/../init.php';
header('Content-Type: application/json; charset=utf-8');

// [VULN-003] Exigir autenticação para ver solicitações
verificar_login_api();

global $gerenciador;
$solicitacoes = [];
foreach ($gerenciador->getFuncionarios() as $funcionario) {
    if ($funcionario->status_aprovacao == "pendente") {
        $solicitacoes[] = [
            "id" => $funcionario->id,
            "nome" => $funcionario->nome,
            "equipe" => $funcionario->equipe,
            "motivo" => $funcionario->motivo_pausa,
            "observacao" => $funcionario->observacao_reuniao,
            "solicitacao_timestamp" => $funcionario->solicitacao_timestamp ? $funcionario->solicitacao_timestamp->format('c') : null
        ];
    }
}
json_response(["solicitacoes" => $solicitacoes]);
