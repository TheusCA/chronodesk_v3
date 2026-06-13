<?php
require_once __DIR__ . '/_bootstrap.php';

$method = require_http_method(['GET', 'POST']);
$role = require_portal_auth();
$service = new OperationalService();

try {
    if ($method === 'GET') {
        json_response(['sucesso' => true] + $service->listCalendar($_GET));
    }

    if (!in_array($role, ['admin', 'gestor'], true)) {
        json_response(['sucesso' => false, 'mensagem' => 'Apenas gestores podem criar eventos.'], 403);
    }
    $data = portal_json_input();
    $id = $service->createCalendarEvent($data, portal_username());
    audit_log('OPERATIONAL_EVENT_CREATED', 'Evento operacional criado. ID ' . $id, 'INFO');
    json_response(['sucesso' => true, 'id' => $id, 'mensagem' => 'Evento cadastrado.'], 201);
} catch (Throwable $e) {
    portal_operational_error($e);
}
