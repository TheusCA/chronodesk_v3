<?php
require_once __DIR__ . '/_bootstrap.php';

$method = require_http_method(['GET', 'POST']);
$role = require_portal_auth();
$actor = portal_actor($role);

try {
    // [Lote 4, 0d] Conexao dentro do try: banco indisponivel vira 503 pelo
    // portal_operational_error(), sem excecao sem captura.
    $service = new OperationalService();
    if ($method === 'GET') {
        // [Lote NC, D-NC-6] NC da pessoa do formulario (so leitura), do cadastro.
        if (($_GET['nc'] ?? '') === '1') {
            json_response(['sucesso' => true] + $service->employeeNcForForm($_GET, $actor));
        }
        // [Lote NC revisto, D-NC-14 e D-NC-15] A exportacao e o .xlsx no modelo
        // do RH (o CSV de horas extras saiu): a previa antes de baixar.
        if (($_GET['export'] ?? '') === 'preview') {
            json_response(['sucesso' => true] + $service->overtimeRhPreview($_GET, $actor));
        }
        if (($_GET['format'] ?? '') === 'csv') {
            throw new InvalidArgumentException('A exportação de horas extras agora é a planilha .xlsx no modelo do RH.');
        }
        if (($_GET['format'] ?? '') === 'xlsx') {
            $config = carregar_configuracao();
            $export = $service->overtimeRhXlsx($_GET, $actor, (string)($config['centro_custo_horas_extras'] ?? OvertimeRhSheet::DEFAULT_COST_CENTER));
            $p = $export['previa'];
            audit_log('OVERTIME_EXPORTED', "Exportacao XLSX de horas extras (modelo do RH): aprovados {$p['aprovados']}, pendentes fora {$p['pendentes_fora']}, outros fora {$p['outros_fora']}, minutos {$p['total_minutos']}.", 'INFO');
            header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
            header('Content-Disposition: attachment; filename="' . $export['arquivo'] . '"');
            header('Content-Length: ' . strlen($export['bytes']));
            header('Cache-Control: no-store');
            header('X-Content-Type-Options: nosniff');
            echo $export['bytes'];
            exit;
        }
        $totals = $service->overtimeTotalsForFilters($_GET, $actor);
        json_response([
            'sucesso' => true,
        ] + $service->listOvertime($_GET, $actor) + [
            'totals' => [
                'approved_minutes' => $totals['approved_minutes'],
                'pending_minutes' => $totals['pending_minutes'],
            ],
            'competency' => OperationalService::competencyRange($_GET['competency'] ?? null),
        ]);
    }
    require_portal_write_access($role);
    $data = portal_json_input();
    $action = $data['action'] ?? 'create';
    if ($action === 'create') {
        $id = $service->createOvertime($data, $actor);
        audit_log('OVERTIME_CREATED', 'Hora extra criada. ID ' . $id, 'INFO');
        json_response(['sucesso' => true, 'id' => $id, 'mensagem' => 'Hora extra enviada para aprovacao.'], 201);
    }
    if ($action === 'decision') {
        if (!portal_role_is_manager($role)) {
            json_response(['sucesso' => false, 'mensagem' => 'Apenas gestores podem decidir lancamentos.'], 403);
        }
        $id = filter_var($data['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if (!$id) {
            throw new InvalidArgumentException('Registro invalido.');
        }
        $decision = (string)($data['decision'] ?? '');
        $service->decideOvertime((int)$id, $decision, $actor);
        audit_log('OVERTIME_DECIDED', "Hora extra {$id}: {$decision}", 'WARNING');
        json_response(['sucesso' => true, 'mensagem' => 'Decisao registrada.']);
    }
    throw new InvalidArgumentException('Acao invalida.');
} catch (Throwable $e) {
    portal_operational_error($e);
}
