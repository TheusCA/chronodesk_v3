<?php
require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../../init.php';
require_once __DIR__ . '/../../services/CriticalIncidentService.php';

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
$workflowPendentes = 0;
$criticalSummary = [
    'open_count' => 0,
    'war_room_count' => 0,
    'critical_open_count' => 0,
    'last_update' => null,
];
try {
    $actor = portal_actor($role);
    $operational = (new OperationalService())->listPendingWorkflowApprovals($actor);
    $workflowPendentes = count($operational['overtime'] ?? []) + count($operational['time_adjustments'] ?? []);
    $criticalSummary = (new CriticalIncidentService())->dashboardSummary();
} catch (Throwable $error) {
    error_log('[DASHBOARD] Resumo operacional parcial: ' . $error->getMessage());
}

json_response([
    'sucesso' => true,
    'summary' => [
        'tecnicos_monitorados' => count($funcionarios),
        'pausas_ativas' => $pausas_ativas,
        'solicitacoes_pendentes' => in_array($role, ['admin', 'gestor'], true) ? $pendentes + $workflowPendentes : 0,
        'alertas_operacionais' => (int)($criticalSummary['critical_open_count'] ?? 0),
        'chamados_criticos_abertos' => (int)($criticalSummary['open_count'] ?? 0),
        'war_rooms_ativas' => (int)($criticalSummary['war_room_count'] ?? 0),
        'plantonistas_ativos' => 0,
        'sobreavisos_ativos' => 0,
    ],
    'critical_incidents' => $criticalSummary,
    'active_pauses' => array_values(array_filter(
        $funcionarios,
        static fn($item) => !empty($item['em_pausa'])
    )),
]);
