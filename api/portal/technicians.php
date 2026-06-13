<?php
require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../../init.php';

require_get_method();
require_portal_auth();

global $gerenciador;
$items = [];
foreach ($gerenciador->getFuncionarios() as $funcionario) {
    if (
        !($funcionario->ativo ?? true)
        || !in_array(strtolower((string)$funcionario->equipe), ['n1', 'n2'], true)
        || ($funcionario->access_role ?? 'tecnico') !== 'tecnico'
    ) {
        continue;
    }
    $items[] = [
        'id' => (int)$funcionario->id,
        'name' => $funcionario->nome,
        'team' => strtoupper($funcionario->equipe),
        'role' => 'Técnico',
        'shift' => $funcionario->jornada_entrada . ' - ' . $funcionario->jornada_saida,
        'status' => $funcionario->status_disponibilidade()['label'] ?? 'Indisponível',
        'on_break' => (bool)$funcionario->em_pausa,
    ];
}
json_response(['sucesso' => true, 'items' => $items]);
