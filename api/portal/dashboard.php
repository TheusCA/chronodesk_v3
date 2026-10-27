<?php
require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../../init.php';
require_once __DIR__ . '/../../services/CriticalIncidentService.php';
require_once __DIR__ . '/../../services/PresenceService.php';

require_get_method();
$role = require_portal_auth();
$actor = portal_actor($role);

global $gerenciador;
// [F7, DV11] Observacao de reuniao dos outros tecnicos so para a gestao,
// inclusive no active_pauses.
$status = GerenciadorPausas::status_para_o_perfil($gerenciador->obter_status(), $actor);
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
    $operational = (new OperationalService())->listPendingWorkflowApprovals($actor);
    $workflowPendentes = count($operational['overtime'] ?? []) + count($operational['time_adjustments'] ?? []);
    $criticalSummary = (new CriticalIncidentService())->dashboardSummary();
} catch (Throwable $error) {
    error_log('[DASHBOARD] Resumo operacional parcial: ' . $error->getMessage());
}

// [F7, DV1 a DV6] Contadores da Visao Operacional pelo motor, com as pausas
// do mesmo estado do active_pauses (DV2). Desligavel por
// STATUS_PRESENCE_ENABLED (DV4); falha no calculo nao derruba o resto (DV5).
// Nao le a data de corte (DV3).
$now = new DateTimeImmutable('now');
$pausas = $gerenciador->pausas_atuais();
$presenceSummary = null;
if (PresenceService::statusPresenceEnabled()) {
    try {
        $presenceSummary = (new PresenceService(get_db_connection()))->operationalSummary($now, $pausas);
    } catch (Throwable $error) {
        error_log('[PRESENCE] Resumo de presenca indisponivel no dashboard: ' . get_class($error));
    }
}

json_response([
    'sucesso' => true,
    'server_now' => $now->format(DATE_ATOM),
    'summary' => [
        'tecnicos_monitorados' => count($funcionarios),
        'cis_disponiveis' => $presenceSummary['total']['disponiveis'] ?? null,
        'pausas_ativas' => $pausas_ativas,
        'solicitacoes_pendentes' => portal_role_is_manager($role) ? $pendentes + $workflowPendentes : 0,
        'alertas_operacionais' => (int)($criticalSummary['critical_open_count'] ?? 0),
        'chamados_criticos_abertos' => (int)($criticalSummary['open_count'] ?? 0),
        'war_rooms_ativas' => (int)($criticalSummary['war_room_count'] ?? 0),
        'plantonistas_ativos' => 0,
        'sobreavisos_ativos' => 0,
    ],
    'presence_summary' => $presenceSummary,
    'presence_meta' => [
        'disponivel' => $presenceSummary !== null,
        'pausas_disponiveis' => $pausas !== null,
    ],
    'critical_incidents' => $criticalSummary,
    'active_pauses' => array_values(array_filter(
        $funcionarios,
        static fn($item) => !empty($item['em_pausa'])
    )),
]);
