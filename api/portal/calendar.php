<?php
require_once __DIR__ . '/_bootstrap.php';

$method = require_http_method(['GET', 'POST']);
$role = require_portal_auth();

try {
    // [Lote 4, 0d] Conexao dentro do try: banco indisponivel vira 503 pelo
    // portal_operational_error(), sem excecao sem captura.
    $service = new OperationalService();
    if ($method === 'GET') {
        // [SEC-07, L-01] Ausencias sem o motivo; tipo so para a gestao e o proprio.
        json_response(['sucesso' => true] + $service->listCalendar($_GET, portal_actor($role)));
    }

    if (!portal_role_is_manager($role)) {
        json_response(['sucesso' => false, 'mensagem' => 'Apenas gestores podem criar eventos.'], 403);
    }
    $data = portal_json_input();
    $id = $service->createCalendarEvent($data, portal_username());
    audit_log('OPERATIONAL_EVENT_CREATED', 'Evento operacional criado. ID ' . $id, 'INFO');
    json_response(['sucesso' => true, 'id' => $id, 'mensagem' => 'Evento cadastrado.'], 201);
} catch (Throwable $e) {
    portal_operational_error($e);
}
