<?php
require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../../init.php';

require_get_method();
$role = require_portal_auth();

global $gerenciador;
$status = $gerenciador->obter_status();
$funcionarios = array_merge($status['n1'] ?? [], $status['n2'] ?? []);
$pausas_ativas = count(array_filter($funcionarios, static fn($item) => !empty($item['em_pausa'])));
$pendentes = count(array_filter(
    $funcionarios,
    static fn($item) => ($item['status_aprovacao'] ?? null) === 'pendente'
));

json_response([
    'sucesso' => true,
    'summary' => [
        'tecnicos_monitorados' => count($funcionarios),
        'pausas_ativas' => $pausas_ativas,
        'solicitacoes_pendentes' => in_array($role, ['admin', 'gestor'], true) ? $pendentes : 0,
        'alertas_operacionais' => 0,
        'plantonistas_ativos' => 0,
        'sobreavisos_ativos' => 0,
    ],
    'active_pauses' => array_values(array_filter(
        $funcionarios,
        static fn($item) => !empty($item['em_pausa'])
    )),
]);
