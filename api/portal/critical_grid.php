<?php
// [Escala do Critico em grade, D-ESC-1 a D-ESC-20]
// GET ?month=AAAA-MM: o mes do Critico (semanas, pessoa x dia, resumo), para
//      qualquer sessao do portal; a ausencia so com a categoria para quem tem
//      escalas.manage (D-ESC-5). ?format=xlsx: o mesmo mes em .xlsx no formato
//      da planilha (D-ESC-19), o que cada perfil ve.
// POST (escalas.manage, CSRF: Lideranca e admin): preview e apply do dia ou do
//      intervalo de uma pessoa (trocar o papel, marcar ausencia, excecao,
//      desfazer); apply confere a versao de cada dia (409 com a contagem).
require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../../services/CriticalGridService.php';
require_once __DIR__ . '/../../services/CriticalGridSheet.php';

$method = require_http_method(['GET', 'POST']);
$role = require_portal_auth();
$actor = portal_actor($role);

try {
    $service = new CriticalGridService();
    if ($method === 'GET') {
        $grid = $service->month($_GET, $actor);
        if (($_GET['format'] ?? '') === 'xlsx') {
            $bytes = CriticalGridSheet::build($grid);
            audit_log('ESCALA_CRITICO_EXPORTADA', 'Exportacao XLSX da Escala do Critico: mes ' . $grid['month']['key'] . ', pessoas ' . count($grid['people']) . ', semanas ' . count($grid['weeks']) . '.', 'INFO');
            header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
            header('Content-Disposition: attachment; filename="' . CriticalGridSheet::fileName($grid['month']['label']) . '"');
            header('Content-Length: ' . strlen($bytes));
            header('Cache-Control: no-store');
            header('X-Content-Type-Options: nosniff');
            echo $bytes;
            exit;
        }
        json_response(['sucesso' => true] + $grid);
    }
    if (!portal_role_can($role, 'escalas.manage')) {
        audit_log('ESCALA_CRITICO_NEGADA', 'Perfil sem permissao tentou alterar a Escala do Critico.', 'WARNING');
        json_response(['sucesso' => false, 'mensagem' => CriticalGridService::FORBIDDEN_MESSAGE], 403);
    }
    $data = portal_json_input();
    $action = $data['action'] ?? null;
    if ($action === 'preview') {
        json_response(['sucesso' => true] + $service->preview($data, $actor));
    }
    if ($action === 'apply') {
        json_response(['sucesso' => true, 'mensagem' => 'Escala do dia salva.'] + $service->apply($data, $actor));
    }
    throw new InvalidArgumentException('Ação da Escala do Crítico inválida.');
} catch (Throwable $e) {
    portal_operational_error($e);
}
