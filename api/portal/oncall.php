<?php
require_once __DIR__ . '/_bootstrap.php';

$method = require_http_method(['GET', 'POST']);
$role = require_portal_auth();
$service = new OperationalService();

try {
    if ($method === 'GET') {
        json_response(['sucesso' => true, 'items' => $service->listOncall($_GET)]);
    }
    if (!in_array($role, ['admin', 'gestor'], true)) {
        json_response(['sucesso' => false, 'mensagem' => 'Apenas gestores podem cadastrar plantoes.'], 403);
    }
    $data = portal_json_input();
    $id = $service->createOncall($data, portal_username());
    audit_log('ONCALL_CREATED', 'Plantao criado. ID ' . $id, 'WARNING');
    json_response(['sucesso' => true, 'id' => $id, 'mensagem' => 'Plantao cadastrado.'], 201);
} catch (Throwable $e) {
    portal_operational_error($e);
}
