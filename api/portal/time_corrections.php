<?php
require_once __DIR__ . '/_bootstrap.php';

$method = require_http_method(['GET', 'POST']);
$role = require_portal_auth();
$actor = portal_actor($role);
$service = new OperationalService();

try {
    if ($method === 'GET') {
        if (($_GET['format'] ?? '') === 'csv') {
            audit_log('TIME_ADJUSTMENT_EXPORTED', 'Exportacao CSV de correcao de ponto.', 'INFO');
            $service->streamTimeAdjustmentsCsv($_GET, $actor);
            exit;
        }
        json_response([
            'sucesso' => true,
            'items' => $service->listTimeAdjustments($_GET, $actor),
            'competency' => OperationalService::competencyRange($_GET['competency'] ?? null),
        ]);
    }
    require_portal_write_access($role);
    $data = portal_json_input();
    $action = $data['action'] ?? 'create';
    if ($action === 'create') {
        $id = $service->createTimeAdjustment($data, $actor);
        audit_log('TIME_ADJUSTMENT_CREATED', 'Ajuste de ponto criado. ID ' . $id, 'INFO');
        json_response(['sucesso' => true, 'id' => $id, 'mensagem' => 'Ajuste enviado para aprovacao.'], 201);
    }
    if ($action === 'decision') {
        if (!in_array($role, ['admin', 'gestor'], true)) {
            json_response(['sucesso' => false, 'mensagem' => 'Apenas gestores podem decidir lancamentos.'], 403);
        }
        $id = filter_var($data['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if (!$id) {
            throw new InvalidArgumentException('Registro invalido.');
        }
        $decision = (string)($data['decision'] ?? '');
        $service->decideTimeAdjustment((int)$id, $decision, $actor);
        audit_log('TIME_ADJUSTMENT_DECIDED', "Ajuste de ponto {$id}: {$decision}", 'WARNING');
        json_response(['sucesso' => true, 'mensagem' => 'Decisao registrada.']);
    }
    throw new InvalidArgumentException('Acao invalida.');
} catch (Throwable $e) {
    portal_operational_error($e);
}
