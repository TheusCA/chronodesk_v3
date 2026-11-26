<?php
// [F10-4] Escala do mes (desenho do F10, 3.5 e 8.9; migration 023).
// GET ?mes=AAAA-MM: o mes publicado (situacao e PA de cada pessoa por dia util,
//      com a divergencia contra a presenca de hoje), para qualquer sessao do
//      portal (D-F10-10); sem categoria de ausencia nem observacao.
//      &formato=paridade: o JSON paridade-escala-v1, so admin (desenho 8.1).
// POST (CSRF): preview e publish (escala_mensal.publish: Lideranca e admin;
//      publish com expected_revision e input_hash, 409 se outra publicacao
//      aconteceu ou se os dados mudaram); lock (pa_map.manage): trava ou solta
//      o PA de uma pessoa num dia.
require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../../services/MonthlyScheduleService.php';

$method = require_http_method(['GET', 'POST']);
$role = require_portal_auth();
$actor = portal_actor($role);

try {
    $service = new MonthlyScheduleService();
    if ($method === 'GET') {
        $format = $_GET['formato'] ?? '';
        if ($format === 'paridade') {
            json_response($service->parity($_GET, $actor));
        }
        if ($format === 'xlsx') {
            if (!portal_role_can($role, 'relatorios.read')) {
                audit_log('ESCALA_MENSAL_EXPORTACAO_NEGADA', 'Perfil sem permissao tentou exportar a escala do mes.', 'WARNING');
                json_response(['sucesso' => false, 'mensagem' => 'Apenas administradores, Liderança e gestão exportam a escala do mês.'], 403);
            }
            require_once __DIR__ . '/../../services/MonthlyScheduleSheet.php';
            $month = $service->exportData($_GET, $actor);
            $bytes = MonthlyScheduleSheet::build($month);
            audit_log('ESCALA_MENSAL_EXPORTADA', 'Exportacao XLSX da escala do mes: mes ' . $month['mes'] . ', revisao ' . $month['current_revision'] . ', pessoas ' . count($month['people']) . ', perfil ' . $role . '.', 'INFO');
            header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
            header('Content-Disposition: attachment; filename="' . MonthlyScheduleSheet::fileName($month['mes']) . '"');
            header('Content-Length: ' . strlen($bytes));
            header('Cache-Control: no-store');
            header('X-Content-Type-Options: nosniff');
            echo $bytes;
            exit;
        }
        json_response(['sucesso' => true] + $service->read($_GET, $actor));
    }
    require_portal_write_access($role);
    $data = portal_json_input();
    $action = $data['action'] ?? null;
    if ($action === 'lock') {
        json_response(['sucesso' => true, 'mensagem' => 'Trava do dia salva.'] + $service->lock($data, $actor));
    }
    if (!portal_role_can($role, 'escala_mensal.publish')) {
        audit_log('ESCALA_MENSAL_NEGADA', 'Perfil sem permissao tentou gerar ou publicar a escala do mes.', 'WARNING');
        json_response(['sucesso' => false, 'mensagem' => MonthlyScheduleService::FORBIDDEN_MESSAGE], 403);
    }
    if ($action === 'preview') {
        json_response(['sucesso' => true] + $service->preview($data, $actor));
    }
    if ($action === 'publish') {
        json_response(['sucesso' => true, 'mensagem' => 'Escala do mês publicada.'] + $service->publish($data, $actor));
    }
    throw new InvalidArgumentException('Ação da escala do mês inválida.');
} catch (Throwable $e) {
    portal_operational_error($e);
}
