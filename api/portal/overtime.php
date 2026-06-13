<?php
require_once __DIR__ . '/_bootstrap.php';

$method = require_http_method(['GET', 'POST']);
$role = require_portal_auth();
$actor = portal_actor($role);
$service = new OperationalService();

try {
    if ($method === 'GET') {
        json_response([
            'sucesso' => true,
            'items' => $service->listOvertime($_GET, $actor),
            'competency' => OperationalService::competencyRange($_GET['competency'] ?? null),
        ]);
    }
    $data = portal_json_input();
    $action = $data['action'] ?? 'create';
    if ($action === 'create') {
        $id = $service->createOvertime($data, $actor);
        audit_log('OVERTIME_CREATED', 'Hora extra criada. ID ' . $id, 'INFO');
        json_response(['sucesso' => true, 'id' => $id, 'mensagem' => 'Hora extra enviada para aprovacao.'], 201);
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
        $service->decideOvertime((int)$id, $decision, $actor);
        audit_log('OVERTIME_DECIDED', "Hora extra {$id}: {$decision}", 'WARNING');
        json_response(['sucesso' => true, 'mensagem' => 'Decisao registrada.']);
    }
    throw new InvalidArgumentException('Acao invalida.');
} catch (Throwable $e) {
    portal_operational_error($e);
}
